<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Listens for local CalDAV object changes — the public OCP events
 * OCP\Calendar\Events\{CalendarObjectCreated,CalendarObjectUpdated,
 * CalendarObjectDeleted,CalendarObjectMovedToTrash,CalendarObjectRestored,
 * CalendarObjectMoved}Event, dispatched by CalDavBackend via dispatchTyped —
 * and re-plans the users the change concerns when the request ends
 * (DeferredReplans): the calendar's owner and the user who made the change
 * (a sharee writing to a shared calendar, whose busy time it is too).
 *
 * - VTODO changes: fixes orphan slots on task delete and re-places/re-mirrors on
 *   task edits, including adding or removing DTSTART/DUE.
 * - VEVENT changes: an ordinary event changes busy time, so blocks move at once
 *   instead of at the next cron pass.
 *
 * Skipped: CalPlan's own writes and blocks, users the app is not enabled for,
 * users whose last reconcile had nothing to plan (ConfigService::isPlanActive;
 * for a task, unless it carries `calplan`), users whose client holds the
 * re-plans back while it writes many tasks (ConfigService::holdReplans), the
 * birthday calendar, and a created, deleted or restored event that is
 * transparent or outside the planning window.
 */

namespace OCA\AutoSchedule\Listener;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\DeferredReplans;
use OCA\AutoSchedule\Service\IcalCodec;
use OCA\AutoSchedule\Service\ReconcileRunGuard;
use OCP\App\IAppManager;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * @template-implements IEventListener<AbstractCalendarObjectEvent|CalendarObjectMovedEvent>
 */
class CalendarObjectChangeListener implements IEventListener {
	private const BIRTHDAY_CALENDAR_URI = 'contact_birthdays';

	public function __construct(
		private DeferredReplans $replans,
		private ConfigService $config,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (ReconcileRunGuard::isActive()) {
			return; // CalPlan's own writes
		}

		// CalendarObjectMovedEvent is not an AbstractCalendarObjectEvent. A move
		// matters when either side is watched: into a watched calendar it adds a
		// task or busy time, out of one it orphans a block or frees time.
		if ($event instanceof CalendarObjectMovedEvent) {
			$objectData = $event->getObjectData();
			$sides = [$event->getTargetCalendarData(), $event->getSourceCalendarData()];
		} elseif ($event instanceof AbstractCalendarObjectEvent) {
			$objectData = $event->getObjectData();
			$sides = [$event->getCalendarData()];
			// Purging an object from the trash bin: it stopped counting when trashed.
			if ($event instanceof CalendarObjectDeletedEvent && ($objectData['{http://nextcloud.com/ns}deleted-at'] ?? $objectData['deleted_at'] ?? null) !== null) {
				return;
			}
		} else {
			return;
		}

		// These public OCP events expose the denormalized calendar-object row.
		// NC 34 uses lowercase `component`; retain `componenttype` and raw-data
		// fallbacks for compatible payloads.
		$component = strtoupper((string)($objectData['component'] ?? $objectData['componenttype'] ?? ''));
		$raw = (string)($objectData['calendardata'] ?? '');
		if (str_contains($component, 'VTODO') || ($component === '' && stripos($raw, 'BEGIN:VTODO') !== false)) {
			$trigger = 'task_change';
		} elseif (str_contains($component, 'VEVENT') || ($component === '' && stripos($raw, 'BEGIN:VEVENT') !== false)) {
			if (str_starts_with((string)($objectData['uri'] ?? ''), Defaults::SLOT_UID_PREFIX)) {
				return; // a CalPlan block
			}
			$trigger = 'event_change';
		} else {
			return;
		}

		$actor = $this->userSession->getUser()?->getUID();
		$users = [];
		foreach ($sides as $calendarData) {
			$owner = self::ownerOf($calendarData);
			$uri = (string)($calendarData['uri'] ?? '');
			if ($owner === null || $uri === self::BIRTHDAY_CALENDAR_URI) {
				continue;
			}
			// CalPlan neither reads nor writes ignored calendars: a change only
			// there needs no re-plan. A sharee knows the calendar under
			// "<uri>_shared_by_<owner>".
			if (!in_array($uri, $this->config->getIgnoredCalendars($owner), true)) {
				$users[$owner] = true;
			}
			if ($actor !== null && $actor !== $owner
				&& !in_array($uri . '_shared_by_' . $owner, $this->config->getIgnoredCalendars($actor), true)) {
				$users[$actor] = true;
			}
		}
		// The object's content is the same before and after a move, a creation
		// or a deletion; only an update may have moved it from elsewhere.
		$contentIsWhole = !($event instanceof CalendarObjectUpdatedEvent);
		$flagged = stripos($raw, Defaults::FLAG_CATEGORY) !== false;
		foreach (array_keys($users) as $userId) {
			$userId = (string)$userId;
			$active = $this->config->isPlanActive($userId) || $this->config->getFlaggedCalendars($userId) !== [];
			if (!$this->isEnabledFor($userId)
				|| $this->config->areReplansHeld($userId, time())
				|| (!$active && !($trigger === 'task_change' && $flagged))
				|| ($trigger === 'event_change' && $contentIsWhole && $raw !== '' && !$this->mayBeBusy($userId, $raw))) {
				continue;
			}
			$this->replans->add($userId, $trigger);
		}
	}

	private function isEnabledFor(string $userId): bool {
		$user = $this->userManager->get($userId);
		return $user !== null && $this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}

	/** Whether an event of *raw* is opaque and lies (partly) in the user's planning window. */
	private function mayBeBusy(string $userId, string $raw): bool {
		try {
			$tz = $this->config->getUserTimeZone($userId);
			$from = (new \DateTimeImmutable('now', $tz))->modify('-1 day');
			$until = $from->modify('+' . ($this->config->getPlanningDays($userId) + 2) . ' days');
			foreach (IcalCodec::parseEvents($raw, $from, $until, $tz) as $event) {
				if (($event->transp ?? null) !== 'TRANSPARENT' && $event->end > $from && $event->start < $until) {
					return true;
				}
			}
			return false;
		} catch (\Throwable $_) {
			return true; // unreadable: let the re-plan decide
		}
	}

	/** The user id of a principals/users/<userId> calendar, or null. */
	private static function ownerOf(array $calendarData): ?string {
		$principalUri = $calendarData['principaluri'] ?? null;
		if (!is_string($principalUri) || $principalUri === '') {
			return null;
		}
		$parts = explode('/', $principalUri);
		if (count($parts) < 3 || $parts[0] !== 'principals' || $parts[1] !== 'users' || $parts[2] === '') {
			return null;
		}
		return $parts[2];
	}
}
