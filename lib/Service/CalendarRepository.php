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
 *  - getCalendarsForUser($principalUri): array of calendar info, each with
 *    'id' (passed back verbatim to the object methods), 'uri', displayname, …
 *  - getCalendarObjects($calendarId): metadata rows with 'uri' + 'component'
 *    (lowercased component type, e.g. 'vevent'/'vtodo'; no calendar-data, for
 *    efficiency).
 *  - getCalendarObject($calendarId, $objectUri): row incl. 'calendardata', or null.
 *  - createCalendarObject / updateCalendarObject / deleteCalendarObject.
 */

namespace OCA\AutoSchedule\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;

class CalendarRepository {
	public function __construct(
		private CalDavBackend $backend,
	) {
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

	/** @return Task[] all VTODOs in the calendar */
	public function getTasks(string $calendarId, int $defaultEstimateMinutes = Defaults::DEFAULT_ESTIMATE_MINUTES, string $calendarUri = ''): array {
		$tasks = [];
		foreach ($this->backend->getCalendarObjects($calendarId) as $obj) {
			$compType = (string)($obj['component'] ?? '');
			if (stripos($compType, 'VTODO') === false) {
				continue;
			}
			$objectUri = (string)($obj['uri'] ?? '');
			$full = $this->backend->getCalendarObject($calendarId, $objectUri);
			if ($full === null || !isset($full['calendardata'])) {
				continue;
			}
			foreach (IcalCodec::parseTasks((string)$full['calendardata'], $defaultEstimateMinutes) as $t) {
				$tasks[] = $t->withSource($calendarUri, $objectUri, $t->categories);
			}
		}
		// Resolve parent/child: a task "has children" when another VTODO in
		// this calendar points at it via RELATED-TO;RELTYPE=PARENT (plan 0.9.1).
		// The child's parentUid is already parsed by IcalCodec; here we mark the
		// parents so IcalCodec::slotContent can emit the ↴/↳ relation marker.
		$byUid = [];
		$childrenByParent = [];
		foreach ($tasks as $task) {
			$byUid[$task->id] = $task;
			if ($task->parentUid !== null) {
				$childrenByParent[$task->parentUid][] = $task->title;
			}
		}
		$out = [];
		foreach ($tasks as $task) {
			$parentTitle = $task->parentUid !== null ? ($byUid[$task->parentUid]->title ?? null) : null;
			$out[] = $task->withRelationships($parentTitle, $childrenByParent[$task->id] ?? []);
		}
		return $out;
	}

	/**
	 * Read VEVENTs overlapping the requested scheduling window, plus every owned
	 * CalPlan slot (including stale slots outside the window so cleanup still works).
	 * CalDavBackend::calendarQuery uses denormalized first/last occurrence columns
	 * and handles recurring-event overlap before returning full object rows.
	 *
	 * @return CalendarEvent[]
	 */
	public function getEvents(string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $until): array {
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
		]);

		$icsByUri = [];
		// NC 32-34 CalDavBackend::calendarQuery() returns object URI strings,
		// not full calendar-object rows. Fetch each matching body explicitly.
		foreach ($matchingUris as $uri) {
			$uri = (string)$uri;
			$full = $this->backend->getCalendarObject($calendarId, $uri);
			if ($full !== null && isset($full['calendardata'])) {
				$icsByUri[$uri] = (string)$full['calendardata'];
			}
		}
		// Range queries intentionally omit distant events. Merge owned slots from
		// metadata so a stale/out-of-window derived object can still be deleted.
		foreach ($this->backend->getCalendarObjects($calendarId) as $obj) {
			$uri = (string)($obj['uri'] ?? '');
			$component = (string)($obj['component'] ?? '');
			if (!str_starts_with($uri, Defaults::SLOT_UID_PREFIX)
				|| stripos($component, 'VEVENT') === false
				|| isset($icsByUri[$uri])) {
				continue;
			}
			$full = $this->backend->getCalendarObject($calendarId, $uri);
			if ($full !== null && isset($full['calendardata'])) {
				$icsByUri[$uri] = (string)$full['calendardata'];
			}
		}

		$events = [];
		foreach ($icsByUri as $ics) {
			foreach (IcalCodec::parseEvents($ics) as $event) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/** Cheap check: does the calendar hold any of our working-slot objects? */
	public function hasSlotObjects(string $calendarId): bool {
		foreach ($this->backend->getCalendarObjects($calendarId) as $obj) {
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

	/** Create or update the working-slot VEVENT for *task*. */
	public function putSlot(string $calendarId, Task $task, Slot $slot, ?\DateTimeImmutable $now = null): void {
		$objectUri = $this->slotObjectUri($task->id);
		$ics = IcalCodec::buildSlotEvent($task, $slot->start, $slot->end, $now);
		$existing = $this->backend->getCalendarObject($calendarId, $objectUri);
		if ($existing !== null) {
			$this->backend->updateCalendarObject($calendarId, $objectUri, $ics);
		} else {
			$this->backend->createCalendarObject($calendarId, $objectUri, $ics);
		}
	}

	/** Remove the working-slot VEVENT for *taskUid*, tolerating "already gone". */
	public function deleteSlot(string $calendarId, string $taskUid): void {
		$objectUri = $this->slotObjectUri($taskUid);
		// CalDavBackend::deleteCalendarObject() already treats an absent object as
		// a successful no-op. Do not swallow real backend, permission, transaction,
		// or trash-name-conflict failures here: ReconcileService isolates the failed
		// write and reports it in diagnostics so a stale slot is never claimed as
		// successfully removed.
		$this->backend->deleteCalendarObject($calendarId, $objectUri);
	}

	private function slotObjectUri(string $taskUid): string {
		return Defaults::SLOT_UID_PREFIX . $taskUid . '.ics';
	}

	/** @return string[] raw iCal bodies of objects whose component type includes $component */
	private function objectsOfComponent(string $calendarId, string $component): array {
		$out = [];
		foreach ($this->backend->getCalendarObjects($calendarId) as $obj) {
			$compType = (string)($obj['component'] ?? '');
			if (stripos($compType, $component) === false) {
				continue;
			}
			$full = $this->backend->getCalendarObject($calendarId, (string)$obj['uri']);
			if ($full !== null && isset($full['calendardata'])) {
				$out[] = (string)$full['calendardata'];
			}
		}
		return $out;
	}
}