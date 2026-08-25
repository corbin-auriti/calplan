<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Listens for local CalDAV object create/update/delete — the public OCP events
 * OCP\Calendar\Events\{CalendarObjectCreated,CalendarObjectUpdated,
 * CalendarObjectDeleted,CalendarObjectMovedToTrash}Event, dispatched by CalDavBackend via dispatchTyped —
 * and synchronously re-reconciles the owning user for VTODO changes only. Fixes
 * orphan slots on task delete and re-places/re-mirrors on task edits, including
 * adding or removing DTSTART/DUE. VEVENT writes are deliberately left to the
 * cron/on-demand pass so creating an ordinary meeting never blocks on a full-user
 * schedule. A deduplicated deferred per-user job is the production follow-up.
 */

namespace OCA\AutoSchedule\Listener;

use OCA\AutoSchedule\Service\ReconcileRunGuard;
use OCA\AutoSchedule\Service\ReconcileService;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<CalendarObjectCreatedEvent|CalendarObjectUpdatedEvent|CalendarObjectDeletedEvent|CalendarObjectMovedToTrashEvent>
 */
class CalendarObjectChangeListener implements IEventListener {
	/** In-process guard: our own slot writes re-dispatch these events; skip them. */
	private static bool $reconciling = false;

	public function __construct(
		private ReconcileService $reconcileService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (self::$reconciling || ReconcileRunGuard::isActive()) {
			return;
		}

		// These public OCP events expose the denormalized calendar-object row.
		// NC 34 uses lowercase `component`; retain `componenttype` and raw-data
		// fallbacks for compatible payloads. Do not make every VEVENT save wait for
		// a full scan and schedule of all the user's calendars.
		$objectData = $event->getObjectData();
		$component = strtoupper((string)($objectData['component'] ?? $objectData['componenttype'] ?? ''));
		$isTask = str_contains($component, 'VTODO');
		if (!$isTask && $component === '') {
			$calendarData = $objectData['calendardata'] ?? null;
			$isTask = is_string($calendarData) && stripos($calendarData, 'BEGIN:VTODO') !== false;
		}
		if (!$isTask) {
			return;
		}

		// All registered events extend AbstractCalendarObjectEvent -> getCalendarData().
		$calendarData = $event->getCalendarData();
		$principalUri = $calendarData['principaluri'] ?? null;
		if (!is_string($principalUri) || $principalUri === '') {
			return;
		}
		// principals/users/<userId>
		$parts = explode('/', $principalUri);
		if (count($parts) < 3 || $parts[0] !== 'principals' || $parts[1] !== 'users') {
			return;
		}
		$userId = $parts[2];
		if ($userId === '') {
			return;
		}

		self::$reconciling = true;
		try {
			$this->reconcileService->reconcileUser($userId, false, 'task_change');
		} catch (\Throwable $e) {
			// The event fires inside CalDavBackend's open transaction; never let a
			// reconcile failure abort the user's original CalDAV write. Best-effort:
			// log and move on; the next toggle/cron reconcile recovers.
			$this->logger->error('CalPlan: synchronous reconcile for {user} failed: {msg}', [
				'user' => $userId,
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
		} finally {
			self::$reconciling = false;
		}
	}
}
