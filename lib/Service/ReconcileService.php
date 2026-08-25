<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-user orchestration: for each of the user's calendars that has work to do
 * (a flagged calendar, a task tagged `calplan`, or pre-existing slot
 * events), gather tasks + events, reconcile, and apply. Skipping calendars with
 * nothing to do keeps the cron pass cheap.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Model\Task;
use Psr\Log\LoggerInterface;

class ReconcileService {
	public function __construct(
		private CalendarRepository $repo,
		private Reconciler $reconciler,
		private ConfigService $config,
		private LoggerInterface $logger,
		private BehavioralHistoryService $history,
	) {
	}

	/** @return Reconciliation[] one per calendar that was reconciled */
	public function reconcileUser(string $userId, bool $processReview = true, string $trigger = 'other'): array {
		$started = hrtime(true);
		$phaseStarted = $started;
		$principalUri = 'principals/users/' . $userId;
		$calendars = $this->repo->getCalendarsForPrincipal($principalUri);
		$calendarCountBeforeExclusions = count($calendars);
		$calendars = self::withoutIgnoredCalendars($calendars, $this->config->getIgnoredCalendars($userId));
		$working = $this->config->getWorkingHours($userId);
		$flaggedUris = array_fill_keys($this->config->getFlaggedCalendars($userId), true);
		$userTz = $this->config->getUserTimeZone($userId);
		$now = new \DateTimeImmutable('now', $userTz);
		$policy = $this->config->getSchedulingPolicy($userId);
		$eventExclusions = new EventExclusionPolicy(
			$this->config->getIgnoredEventTitles($userId),
			$this->config->getEventTitleMatchMode($userId),
		);
		$calendarSetupMs = self::elapsedMs($phaseStarted);

		// Busy only matters where a slot can be placed. Include one day behind now
		// for ongoing/overnight events and query through the fixed planning horizon.
		// CalendarRepository separately merges every owned slot for stale cleanup.
		$eventFrom = $now->modify('-1 day');
		$eventUntil = $now->modify('+' . Defaults::LOOKAHEAD_DAYS . ' days')->setTime(23, 59, 59);
		$phaseStarted = hrtime(true);
		$eventsByCalendar = [];
		$allEvents = [];
		$rawEventCount = 0;
		$titleExcludedEventCount = 0;
		$calendarDiagnostics = [];
		foreach ($calendars as $uri => $cal) {
			$rawEvents = $this->repo->getEvents((string)$cal['id'], $eventFrom, $eventUntil);
			$events = array_values(array_filter(
				$rawEvents,
				fn ($event) => !$eventExclusions->ignores($event),
			));
			$rawEventCount += count($rawEvents);
			$titleExcludedEventCount += count($rawEvents) - count($events);
			$eventsByCalendar[$uri] = $events;
			$calendarDiagnostics[$uri] = [
				'calendar' => (string)($cal['{DAV:}displayname'] ?? $uri),
				'eventsRead' => count($rawEvents),
				'eventsIgnoredByTitle' => count($rawEvents) - count($events),
				'tasksRead' => 0,
				'scheduledTasks' => 0,
				'placed' => 0,
				'removed' => 0,
				'unchanged' => 0,
				'unscheduled' => 0,
			];
			foreach ($events as $event) {
				$allEvents[] = $event;
			}
		}
		$eventReadMs = self::elapsedMs($phaseStarted);

		$results = [];
		$taskCount = 0;
		$scheduledTaskCount = 0;
		$putCount = 0;
		$deleteCount = 0;
		$unchangedCount = 0;
		$taskReadNs = 0;
		$scheduleNs = 0;
		$applyNs = 0;
		$putFailures = 0;
		$deleteFailures = 0;
		$unscheduledReasons = [];
		$dailyCountUsed = [];
		foreach ($calendars as $uri => $cal) {
			$calendarId = (string)$cal['id'];
			$phaseStarted = hrtime(true);
			$tasks = $this->repo->getTasks($calendarId, $policy['defaultDurationMinutes'], (string)$uri);
			foreach ($tasks as $index => $task) {
				if ($task->autoSchedule && $this->config->isTaskPaused($userId, $task->id)) {
					// Explicitly enabling Auto-schedule is the only restart action while
					// automatic rescheduling is Off. Normalize the review category here
					// too, so enabling from any CalDAV client behaves like the Tasks switch.
					$categories = array_values(array_filter(
						$task->categories,
						fn (string $category) => $category !== Defaults::REVIEW_CATEGORY,
					));
					if ($categories !== $task->categories) {
						try {
							ReconcileRunGuard::enter();
							$this->repo->updateTaskCategories($calendarId, $task, $categories);
							$task = $task->withCategories($categories);
							$tasks[$index] = $task;
						} catch (\Throwable $e) {
							$this->logger->warning('CalPlan could not clear a restarted task review category', [
								'app' => 'calplan',
								'exception' => $e,
							]);
						} finally {
							ReconcileRunGuard::leave();
						}
					}
					$this->config->setTaskPaused($userId, $task->id, false);
				}
			}
			if ($processReview) {
				$tasks = $this->reviewExpiredTasks($userId, $calendarId, $tasks, $eventsByCalendar[$uri], $now);
			}
			$taskReadNs += hrtime(true) - $phaseStarted;
			$taskCount += count($tasks);
			$calendarDiagnostics[$uri]['tasksRead'] = count($tasks);
			$flaggedTasks = array_values(array_filter(
				$tasks,
				fn (Task $task) => $task->autoSchedule && !$task->isDone(),
			));
			$toSchedule = isset($flaggedUris[$uri])
				? array_values(array_filter($tasks, fn (Task $task) => !$task->isDone()
					&& !$this->config->isTaskPaused($userId, $task->id)
					&& !($policy['autoRescheduleMinutes'] === 0 && $task->needsReview())))
				: array_values(array_filter($flaggedTasks, fn (Task $task) => !$this->config->isTaskPaused($userId, $task->id)));
			$scheduledTaskCount += count($toSchedule);
			$calendarDiagnostics[$uri]['scheduledTasks'] = count($toSchedule);

			$hasExistingSlots = $this->repo->hasSlotObjects($calendarId);
			if ($toSchedule === [] && !$hasExistingSlots) {
				continue;
			}

			// Avoid rebuilding a nested "all other calendars" array. Filter the one
			// flattened per-user event list by object identity; current-calendar events
			// remain the source for existing-placement detection.
			$currentIds = [];
			foreach ($eventsByCalendar[$uri] as $event) {
				$currentIds[spl_object_id($event)] = true;
			}
			$otherEvents = array_values(array_filter(
				$allEvents,
				fn ($event) => !isset($currentIds[spl_object_id($event)]),
			));
			$phaseStarted = hrtime(true);
			$recon = $this->reconciler->reconcile(
				$toSchedule,
				$working,
				$eventsByCalendar[$uri],
				$now,
				dailyCap: $policy['dailyCapMinutes'],
				extraBusyEvents: $otherEvents,
				taskGapMinutes: $policy['taskGapMinutes'],
				eventBufferMinutes: $policy['eventBufferMinutes'],
				dailyTaskCount: $policy['dailyTaskCount'],
				dailyCountUsed: $dailyCountUsed,
			);
			$scheduleNs += hrtime(true) - $phaseStarted;
			$tasksById = [];
			foreach ($toSchedule as $task) {
				$tasksById[$task->id] = $task;
			}
			foreach ($recon->unscheduled as $taskId => $reason) {
				$unscheduledReasons[$reason] = ($unscheduledReasons[$reason] ?? 0) + 1;
				if (isset($tasksById[$taskId])) {
					try {
						$this->history->recordUnscheduled($userId, $tasksById[$taskId], $reason, $now);
					} catch (\Throwable $_) {
						// History is optional observation data; never break scheduling.
					}
				}
			}
			$phaseStarted = hrtime(true);
			$applyResult = $this->apply($userId, $calendarId, $recon, $now);
			$applyNs += hrtime(true) - $phaseStarted;
			$putFailures += $applyResult['putFailures'];
			$deleteFailures += $applyResult['deleteFailures'];
			$putCount += count($recon->toPut);
			$deleteCount += count($recon->toDelete);
			$unchangedCount += count($recon->unchanged);
			$calendarDiagnostics[$uri]['placed'] = count($recon->toPut) - $applyResult['putFailures'];
			$calendarDiagnostics[$uri]['removed'] = count($recon->toDelete) - $applyResult['deleteFailures'];
			$calendarDiagnostics[$uri]['writeFailures'] = [
				'put' => $applyResult['putFailures'],
				'delete' => $applyResult['deleteFailures'],
			];
			$calendarDiagnostics[$uri]['unchanged'] = count($recon->unchanged);
			$calendarDiagnostics[$uri]['unscheduled'] = count($recon->unscheduled);
			$results[] = $recon;
		}

		$report = [
			'timestamp' => $now->format(DATE_ATOM),
			'trigger' => in_array($trigger, ['manual', 'background', 'task_change'], true) ? $trigger : 'other',
			'processReview' => $processReview,
			'calendars' => count($calendars),
			'ignoredCalendars' => $calendarCountBeforeExclusions - count($calendars),
			'eventsRead' => $rawEventCount,
			'eventsIgnoredByTitle' => $titleExcludedEventCount,
			'tasksRead' => $taskCount,
			'scheduledTasks' => $scheduledTaskCount,
			'reconciledCalendars' => count($results),
			'placed' => $putCount - $putFailures,
			'removed' => $deleteCount - $deleteFailures,
			'plannedWrites' => ['put' => $putCount, 'delete' => $deleteCount],
			'unchanged' => $unchangedCount,
			'unscheduled' => array_sum($unscheduledReasons),
			'unscheduledReasons' => $unscheduledReasons,
			'writeFailures' => ['put' => $putFailures, 'delete' => $deleteFailures],
			'timingsMs' => [
				'calendarSetup' => $calendarSetupMs,
				'eventRead' => $eventReadMs,
				'taskRead' => self::nsToMs($taskReadNs),
				'schedule' => self::nsToMs($scheduleNs),
				'apply' => self::nsToMs($applyNs),
				'total' => self::elapsedMs($started),
			],
			'byCalendar' => array_values($calendarDiagnostics),
		];
		try {
			$this->config->recordReconcileDiagnostic($userId, $report);
		} catch (\Throwable $_) {
			// Diagnostics must never break scheduling.
		}

		if ($this->config->isPerformanceLoggingEnabled()) {
			$this->logger->info('CalPlan reconcile metrics', [
				'app' => 'calplan',
				'user' => $userId,
				'calendars' => count($calendars),
				'events' => count($allEvents),
				'tasks' => $taskCount,
				'scheduled_tasks' => $scheduledTaskCount,
				'reconciled_calendars' => count($results),
				'planned_puts' => $putCount,
				'planned_deletes' => $deleteCount,
				'unchanged' => $unchangedCount,
				'window_start' => $eventFrom->format(DATE_ATOM),
				'window_end' => $eventUntil->format(DATE_ATOM),
				'calendar_setup_ms' => $calendarSetupMs,
				'event_read_ms' => $eventReadMs,
				'task_read_ms' => self::nsToMs($taskReadNs),
				'schedule_ms' => self::nsToMs($scheduleNs),
				'apply_ms' => self::nsToMs($applyNs),
				'total_ms' => self::elapsedMs($started),
			]);
		}
		return $results;
	}

	/**
	 * Hard exclusion boundary: excluded calendars never reach event/task reads,
	 * review, slot ownership checks, or apply.
	 *
	 * @param array<string,mixed> $calendars
	 * @param string[] $ignoredUris
	 * @return array<string,mixed>
	 */
	public static function withoutIgnoredCalendars(array $calendars, array $ignoredUris): array {
		$ignored = array_fill_keys($ignoredUris, true);
		return array_filter(
			$calendars,
			fn (string $uri) => !isset($ignored[$uri]),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * Add the review category after an expired slot. When automatic rescheduling
	 * is Off, also remove the scheduling category so the slot is cleaned up and
	 * the task stays paused until Auto-schedule is explicitly enabled again.
	 *
	 * @param Task[] $tasks
	 * @param \OCA\AutoSchedule\Model\CalendarEvent[] $events
	 * @return Task[]
	 */
	private function reviewExpiredTasks(string $userId, string $calendarId, array $tasks, array $events, \DateTimeImmutable $now): array {
		$slots = [];
		foreach ($events as $event) {
			if ($event->autoScheduleTaskUid !== null) {
				$slots[$event->autoScheduleTaskUid] = $event;
			}
		}
		$autoReschedule = $this->config->getAutoRescheduleMinutes($userId) > 0;
		$graceMinutes = $this->config->reviewGraceMinutes($userId);
		$out = [];
		foreach ($tasks as $task) {
			$categories = $task->categories;
			$expired = isset($slots[$task->id]) && self::isPastReviewGrace($slots[$task->id]->end, $now, $graceMinutes);
			$shouldReview = !$task->isDone() && ($expired || (!$autoReschedule && $task->needsReview()));
			if (!$shouldReview) {
				$out[] = $task;
				continue;
			}
			$categories = $task->reviewCategories($autoReschedule);
			if ($categories !== $task->categories) {
				try {
					ReconcileRunGuard::enter();
					$this->repo->updateTaskCategories($calendarId, $task, $categories);
					if (!$autoReschedule) {
						$this->config->setTaskPaused($userId, $task->id, true);
					}
				} catch (\Throwable $e) {
					$this->logger->warning('CalPlan could not update task review categories', [
						'app' => 'calplan',
						'exception' => $e,
					]);
					$categories = $task->categories;
				} finally {
					ReconcileRunGuard::leave();
				}
			}
			$out[] = $task->withCategories($categories);
		}
		return $out;
	}

	public static function isPastReviewGrace(\DateTimeImmutable $slotEnd, \DateTimeImmutable $now, int $graceMinutes): bool {
		return $slotEnd->modify('+' . max(0, $graceMinutes) . ' minutes') <= $now;
	}

	private static function elapsedMs(int $started): float {
		return self::nsToMs(hrtime(true) - $started);
	}

	private static function nsToMs(int $nanoseconds): float {
		return round($nanoseconds / 1_000_000, 2);
	}

	/** @return array{putFailures:int,deleteFailures:int} */
	private function apply(string $userId, string $calendarId, Reconciliation $recon, ?\DateTimeImmutable $now = null): array {
		$putFailures = 0;
		$deleteFailures = 0;
		foreach ($recon->toPut as [$task, $slot]) {
			// Best-effort: a single bad write (e.g. a task living in a
			// component-restricted "tasks" calendar that rejects VEVENT slots)
			// must not abort the whole reconcile and leave every other slot
			// un-upgraded. Swallow + let the next reconcile retry (plan 0.9.1).
			try {
				$this->repo->putSlot($calendarId, $task, $slot, $now);
			} catch (\Throwable $_) {
				$putFailures++;
				// keep going -- reconcile is idempotent and re-runs on the next trigger
				continue;
			}
			try {
				$this->history->recordScheduled($userId, $task, $slot, $now ?? new \DateTimeImmutable());
			} catch (\Throwable $_) {
				// Optional observation data must never change the calendar outcome.
			}
		}
		foreach ($recon->toDelete as $tid) {
			try {
				$this->repo->deleteSlot($calendarId, $tid);
			} catch (\Throwable $_) {
				$deleteFailures++;
				// already gone / restricted -- fine
				continue;
			}
			try {
				$this->history->recordRemoved($userId, $tid, $now ?? new \DateTimeImmutable());
			} catch (\Throwable $_) {
				// Optional observation data must never change the calendar outcome.
			}
		}
		return ['putFailures' => $putFailures, 'deleteFailures' => $deleteFailures];
	}
}