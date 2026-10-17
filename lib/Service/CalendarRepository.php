<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CalDAV-backed calendar object access for calplan. Uses Nextcloud's
 * bundled OCA\DAV\CalDAV\CalDavBackend (note the capital CalDAV — NC's dav app
 * namespace is OCA\DAV\CalDAV, case-sensitive on Linux) and Sabre\VObject
 * for the iCalendar bodies. The pure parse/build of those bodies lives in
 * IcalCodec (unit-tested); this class is the thin I/O wrapper, validated live.
 *
 * Verified against the NC 34 dav app (OCA\DAV\CalDAV\CalDavBackend):
 *  - getCalendarsForUser($principalUri): the user's own calendars and those
 *    shared with them ('{http://owncloud.org/ns}owner-principal' names the
 *    owner, '{http://owncloud.org/ns}read-only' a read-only share), each with
 *    'id' (passed back verbatim to the object methods), 'uri', displayname, …
 *  - getSubscriptionsForUser / getFederatedCalendarsForUser: subscribed and
 *    federated calendars, whose objects use calendar type 1 and 2.
 *  - getCalendarObjects($calendarId, $type): metadata rows with 'uri' +
 *    'component' (lowercased component type, e.g. 'vevent'/'vtodo'; no
 *    calendar-data, for efficiency).
 *  - getMultipleCalendarObjects($calendarId, $uris, $type): rows incl. 'calendardata'.
 *  - createCalendarObject / updateCalendarObject / deleteCalendarObject.
 *    These skip the CalDAV server's ACL and component checks, so CalPlan
 *    itself writes only to calendars the user owns and that hold events.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;
use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Settings;

class CalendarRepository {
	private const OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
	/** Bodies fetched per query. */
	private const FETCH_CHUNK = 100;

	/** @var array<string, list<array<string,mixed>>> object metadata by calendar type and id, for one reconcile */
	private array $objectLists = [];

	public function __construct(
		private CalDavBackend $backend,
		private LoggerInterface $logger,
	) {
	}

	/** Forget the object listings read so far (call at the start of each reconcile). */
	public function resetCache(): void {
		$this->objectLists = [];
	}

	/** @return array<string, array<string,mixed>> calendar info keyed by calendar uri */
	public function getCalendarsForPrincipal(string $principalUri): array {
		$out = [];
		foreach ($this->backend->getCalendarsForUser($principalUri) as $cal) {
			// CalDavBackend includes deleted-calendar tombstones. They remain DAV
			// collections for restore/trash semantics but must never be reconciled or
			// offered as active personal-setting choices.
			if (($cal['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null) {
				continue;
			}
			$uri = (string)($cal['uri'] ?? '');
			if ($uri !== '') {
				$out[$uri] = $cal;
			}
		}
		return $out;
	}

	/**
	 * Calendars the user subscribed to (webcal/ICS) or accepted from another
	 * server. CalPlan only reads their events as busy time.
	 *
	 * @return array<string, array{id: string, name: string, type: int}> keyed by a settings key ("subscription:<uri>", "federated:<uri>")
	 */
	public function getBusyOnlyCalendars(string $principalUri): array {
		$out = [];
		foreach ($this->backend->getSubscriptionsForUser($principalUri) as $sub) {
			$uri = (string)($sub['uri'] ?? '');
			if ($uri !== '') {
				$out['subscription:' . $uri] = ['id' => (string)$sub['id'], 'name' => (string)($sub['{DAV:}displayname'] ?? $uri), 'type' => CalDavBackend::CALENDAR_TYPE_SUBSCRIPTION];
			}
		}
		if (method_exists($this->backend, 'getFederatedCalendarsForUser')) {
			try {
				foreach ($this->backend->getFederatedCalendarsForUser($principalUri) as $cal) {
					$uri = (string)($cal['uri'] ?? '');
					if ($uri !== '') {
						$out['federated:' . $uri] = ['id' => (string)$cal['id'], 'name' => (string)($cal['{DAV:}displayname'] ?? $uri), 'type' => CalDavBackend::CALENDAR_TYPE_FEDERATED];
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('CalPlan could not list federated calendars', ['app' => 'calplan', 'exception' => $e]);
			}
		}
		return $out;
	}

	/**
	 * Whether *principalUri* owns *calendar*. A calendar another user shared
	 * (read-only or not) is never planned or written by the sharee's CalPlan:
	 * the owner's CalPlan plans it, and the sharee only sees its events as busy.
	 *
	 * @param array<string,mixed> $calendar
	 */
	public static function isOwnedBy(array $calendar, string $principalUri): bool {
		$owner = $calendar[self::OWNER_PRINCIPAL] ?? null;
		return !is_string($owner) || $owner === '' || $owner === $principalUri;
	}

	/**
	 * Whether *calendar* can hold events. A Tasks-only list (VTODO only, what
	 * the Tasks app creates) cannot: no calendar shows an event stored there.
	 *
	 * @param array<string,mixed> $calendar
	 */
	public static function supportsEvents(array $calendar): bool {
		$set = $calendar[self::COMPONENT_SET] ?? null;
		if ($set === null) {
			return true;
		}
		$components = is_object($set) && method_exists($set, 'getValue') ? $set->getValue() : (array)$set;
		return in_array('VEVENT', array_map('strtoupper', array_map('strval', (array)$components)), true);
	}

	/**
	 * All VTODOs in the calendar, their relationships resolved. A task object
	 * that cannot be parsed is skipped, so one broken task never stops the plan.
	 *
	 * @return Task[]
	 */
	public function getTasks(string $calendarId, int $defaultEstimateMinutes = Defaults::DEFAULT_ESTIMATE_MINUTES, string $calendarUri = '', ?\DateTimeZone $tz = null): array {
		$uris = [];
		foreach ($this->objects($calendarId, CalDavBackend::CALENDAR_TYPE_CALENDAR) as $obj) {
			if (stripos((string)($obj['component'] ?? ''), 'VTODO') !== false) {
				$uris[] = (string)($obj['uri'] ?? '');
			}
		}
		$tasks = [];
		foreach ($this->bodies($calendarId, $uris, CalDavBackend::CALENDAR_TYPE_CALENDAR) as $objectUri => $ics) {
			try {
				$parsed = IcalCodec::parseTasks($ics, $defaultEstimateMinutes, $tz);
			} catch (\Throwable $e) {
				$this->logger->warning('CalPlan skipped a task it cannot read: {uri}', ['app' => 'calplan', 'uri' => $objectUri, 'exception' => $e]);
				continue;
			}
			foreach ($parsed as $t) {
				$tasks[] = $t->withSource($calendarUri, (string)$objectUri, $t->categories);
			}
		}
		// Resolve parent/child: a task "has children" when another VTODO in
		// this calendar points at it via RELATED-TO;RELTYPE=PARENT (plan 0.9.1).
		// The child's parentUid is already parsed by IcalCodec; here we mark the
		// parents so IcalCodec::slotContent can emit the ↴/↳ relation marker.
		// A task naming itself as parent is neither its own parent nor its own
		// subtask.
		$byUid = [];
		$childrenByParent = [];
		foreach ($tasks as $task) {
			$byUid[$task->id] = $task;
			if ($task->parentUid !== null && $task->parentUid !== $task->id) {
				$childrenByParent[$task->parentUid][] = $task->title;
			}
		}
		$out = [];
		foreach ($tasks as $task) {
			$parentTitle = $task->parentUid !== null && $task->parentUid !== $task->id ? ($byUid[$task->parentUid]->title ?? null) : null;
			$out[] = $task->withRelationships($parentTitle, $childrenByParent[$task->id] ?? []);
		}
		return $out;
	}

	/**
	 * Read VEVENTs overlapping the requested scheduling window, plus (for the
	 * user's own calendars) every owned CalPlan slot, including stale slots
	 * outside the window so cleanup still works. CalDavBackend::calendarQuery
	 * uses denormalized first/last occurrence columns and handles
	 * recurring-event overlap before returning object URIs. An event that
	 * cannot be parsed is skipped.
	 *
	 * @return CalendarEvent[]
	 */
	public function getEvents(string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $until, ?\DateTimeZone $tz = null, int $calendarType = CalDavBackend::CALENDAR_TYPE_CALENDAR): array {
		// calendarQuery expands recurring events to test the range; Sabre's
		// default limit would drop a daily event older than about ten years.
		$maxRecurrences = Settings::$maxRecurrences;
		Settings::$maxRecurrences = max($maxRecurrences, 50000);
		try {
			$matchingUris = $this->backend->calendarQuery($calendarId, [
				'name' => 'VCALENDAR',
				'is-not-defined' => false,
				'comp-filters' => [[
					'name' => 'VEVENT',
					'is-not-defined' => false,
					'comp-filters' => [],
					'prop-filters' => [],
					'time-range' => ['start' => $from, 'end' => $until],
				]],
				'prop-filters' => [],
				'time-range' => null,
			], $calendarType);
		} finally {
			Settings::$maxRecurrences = $maxRecurrences;
		}
		// NC 32-34 CalDavBackend::calendarQuery() returns object URI strings,
		// not full calendar-object rows.
		$uris = array_map('strval', $matchingUris);
		if ($calendarType === CalDavBackend::CALENDAR_TYPE_CALENDAR) {
			// Range queries intentionally omit distant events. Add owned slots from
			// metadata so a stale/out-of-window derived object can still be deleted.
			$inRange = array_fill_keys($uris, true);
			foreach ($this->objects($calendarId, $calendarType) as $obj) {
				$uri = (string)($obj['uri'] ?? '');
				if (str_starts_with($uri, Defaults::SLOT_UID_PREFIX)
					&& stripos((string)($obj['component'] ?? ''), 'VEVENT') !== false
					&& !isset($inRange[$uri])) {
					$uris[] = $uri;
				}
			}
		}

		$events = [];
		foreach ($this->bodies($calendarId, $uris, $calendarType) as $uri => $ics) {
			try {
				foreach (IcalCodec::parseEvents($ics, $from, $until, $tz, (string)$uri) as $event) {
					$events[] = $event;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('CalPlan skipped an event it cannot read: {uri}', ['app' => 'calplan', 'uri' => $uri, 'exception' => $e]);
			}
		}
		return $events;
	}

	/** Cheap check: does the calendar hold any of our working-slot objects? */
	public function hasSlotObjects(string $calendarId): bool {
		foreach ($this->objects($calendarId, CalDavBackend::CALENDAR_TYPE_CALENDAR) as $obj) {
			if (str_starts_with((string)($obj['uri'] ?? ''), Defaults::SLOT_UID_PREFIX)) {
				return true;
			}
		}
		return false;
	}

	/** @param string[] $categories */
	public function updateTaskCategories(string $calendarId, Task $task, array $categories): void {
		if ($task->objectUri === null || $task->objectUri === '') {
			throw new \InvalidArgumentException('Task object URI is unavailable');
		}
		$existing = $this->backend->getCalendarObject($calendarId, $task->objectUri);
		if ($existing === null || !isset($existing['calendardata'])) {
			throw new \RuntimeException('Task object no longer exists');
		}
		$ics = IcalCodec::replaceTaskCategories((string)$existing['calendardata'], $categories);
		$this->backend->updateCalendarObject($calendarId, $task->objectUri, $ics);
	}

	/**
	 * Create or update the working-slot VEVENT for *task*. An existing block
	 * is updated at *objectUri*, where it is stored (an imported block may not
	 * use CalPlan's file name).
	 */
	public function putSlot(string $calendarId, Task $task, Slot $slot, ?\DateTimeImmutable $now = null, ?string $objectUri = null): void {
		$ics = IcalCodec::buildSlotEvent($task, $slot->start, $slot->end, $now);
		$objectUri ??= self::slotObjectUri($task->id);
		if ($this->backend->getCalendarObject($calendarId, $objectUri) !== null) {
			$this->backend->updateCalendarObject($calendarId, $objectUri, $ics);
		} else {
			$this->backend->createCalendarObject($calendarId, $objectUri, $ics);
		}
	}

	/**
	 * Remove the working-slot VEVENT stored at *objectUri*, for good: a derived
	 * block does not belong in the calendar's trash bin. An absent object is
	 * a no-op (CalDavBackend). Real backend, permission or transaction
	 * failures are not swallowed: ReconcileService isolates the failed write
	 * and reports it in diagnostics so a stale slot is never claimed as
	 * successfully removed.
	 */
	public function deleteSlot(string $calendarId, string $objectUri): void {
		$this->backend->deleteCalendarObject($calendarId, $objectUri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
	}

	/** The object URI CalPlan gives the block of task *taskUid*. */
	public static function slotObjectUri(string $taskUid): string {
		return Defaults::SLOT_UID_PREFIX . $taskUid . '.ics';
	}

	/** @return list<array<string,mixed>> the calendar's object metadata, listed once per reconcile */
	private function objects(string $calendarId, int $calendarType): array {
		$key = $calendarType . ':' . $calendarId;
		return $this->objectLists[$key] ??= array_values($this->backend->getCalendarObjects($calendarId, $calendarType));
	}

	/**
	 * The bodies of the objects *uris*, fetched in a few queries.
	 *
	 * @param string[] $uris
	 * @return array<string, string> calendar data by object URI
	 */
	private function bodies(string $calendarId, array $uris, int $calendarType): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique(array_filter($uris, fn (string $uri) => $uri !== ''))), self::FETCH_CHUNK) as $chunk) {
			foreach ($this->backend->getMultipleCalendarObjects($calendarId, $chunk, $calendarType) as $row) {
				if (isset($row['uri'], $row['calendardata'])) {
					$data = $row['calendardata'];
					$out[(string)$row['uri']] = is_resource($data) ? (string)stream_get_contents($data) : (string)$data;
				}
			}
		}
		return $out;
	}
}
