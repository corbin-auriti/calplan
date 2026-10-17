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
 * and, for VTODO changes only, re-plans the calendar's owner when the request
 * ends (DeferredReplans). Fixes orphan slots on task delete and
 * re-places/re-mirrors on task edits, including adding or removing
 * DTSTART/DUE. VEVENT writes are left to the cron/on-demand pass.
 *
 * Skipped: CalPlan's own writes, users the app is not enabled for, and users
 * whose last reconcile had nothing to plan (ConfigService::isPlanActive),
 * unless the task carries `calplan`.
 */

namespace OCA\AutoSchedule\Listener;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\DeferredReplans;
use OCA\AutoSchedule\Service\ReconcileRunGuard;
use OCP\App\IAppManager;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;

/**
 * @template-implements IEventListener<AbstractCalendarObjectEvent|CalendarObjectMovedEvent>
 */
class CalendarObjectChangeListener implements IEventListener {
	public function __construct(
		private DeferredReplans $replans,
		private ConfigService $config,
		private IAppManager $appManager,
		private IUserManager $userManager,
	) {
	}

	public function handle(Event $event): void {
		if (ReconcileRunGuard::isActive()) {
			return; // CalPlan's own writes
		}

		// CalendarObjectMovedEvent is not an AbstractCalendarObjectEvent. A move
		// matters when either side is watched: into a watched calendar it adds a
		// task, out of one it orphans a block.
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
		if (!str_contains($component, 'VTODO') && !($component === '' && stripos($raw, 'BEGIN:VTODO') !== false)) {
			return;
		}

		$users = [];
		foreach ($sides as $calendarData) {
			$owner = self::ownerOf($calendarData);
			// CalPlan neither reads nor writes ignored calendars: a change only
			// there needs no re-plan.
			if ($owner !== null && !in_array((string)($calendarData['uri'] ?? ''), $this->config->getIgnoredCalendars($owner), true)) {
				$users[$owner] = true;
			}
		}
		$flagged = stripos($raw, Defaults::FLAG_CATEGORY) !== false;
		foreach (array_keys($users) as $userId) {
			$userId = (string)$userId;
			$active = $this->config->isPlanActive($userId) || $this->config->getFlaggedCalendars($userId) !== [];
			if (!$this->isEnabledFor($userId) || (!$active && !$flagged)) {
				continue;
			}
			$this->replans->add($userId, 'task_change');
		}
	}

	private function isEnabledFor(string $userId): bool {
		$user = $this->userManager->get($userId);
		return $user !== null && $this->appManager->isEnabledForUser(Application::APP_ID, $user);
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
