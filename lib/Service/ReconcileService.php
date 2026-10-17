<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-user orchestration: read the user's tasks and busy time, review expired
 * blocks, plan every calendar that has work to do (a flagged calendar, a task
 * tagged `calplan`, or pre-existing slot events) in one pass, and apply the
 * result. Only the user's own calendars are planned and written; calendars
 * shared with the user, subscriptions and federated calendars only count as
 * busy time. One reconcile per user runs at a time.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Model\Task;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class ReconcileService {
	/** Nextcloud's birthday calendar: transparent events only, never busy. */
	private const BIRTHDAY_CALENDAR_URI = 'contact_birthdays';
	/** How long a reconcile waits for the user's running one to finish. */
	public const LOCK_WAIT_SECONDS = 30;
	public const TRIGGERS = ['manual', 'background', 'task_change', 'event_change'];

	/** @var array<string,mixed>|null the report of the last reconcileUser() call */
	private ?array $lastReport = null;

	public function __construct(
		private CalendarRepository $repo,
		private Reconciler $reconciler,
		private ConfigService $config,
		private LoggerInterface $logger,
		private BehavioralHistoryService $history,
		private ?ILockingProvider $locking = null,
	) {
	}

	/** The report (diagnostics) of the last reconcileUser() call in this process, or null. */
	public function lastReport(): ?array {
		return $this->lastReport;
	}

	/**
	 * Reconcile *userId*'s calendars. Runs of one user never overlap: a run
	 * waits up to *lockWaitSeconds* for the running one, and is skipped
	 * (returning []) when it is still busy.
	 *
	 * @return Reconciliation[] one per calendar that was reconciled
	 */
	public function reconcileUser(string $userId, bool $processReview = true, string $trigger = 'other', int $lockWaitSeconds = self::LOCK_WAIT_SECONDS): array {
		$this->lastReport = null;
		$lock = 'calplan/reconcile/' . $userId;
		if (!$this->acquireLock($lock, $lockWaitSeconds)) {
			$this->logger->info('CalPlan skipped a reconcile for {user}: another one is still running', ['app' => 'calplan', 'user' => $userId]);
			return [];
		}
		// A run that waited for another one must see what that one stored.
		$this->config->reload($userId);
		// CalPlan's own writes below re-dispatch calendar events; the listener
		// must not re-plan for them.
		ReconcileRunGuard::enter();
		try {
			return $this->run($userId, $processReview, $trigger);
		} finally {
			ReconcileRunGuard::leave();
			$this->releaseLock($lock);
		}
	}

	private function acquireLock(string $lock, int $waitSeconds): bool {
		if ($this->locking === null) {
			return true;
		}
		$deadline = microtime(true) + max(0, $waitSeconds);
		while (true) {
			try {
				$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'CalPlan reconcile');
				return true;
			} catch (LockedException $_) {
				if (microtime(true) >= $deadline) {
					return false;
				}
				usleep(250_000);
			}
		}
	}

	private function releaseLock(string $lock): void {
		try {
			$this->locking?->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			$this->logger->warning('CalPlan could not release its reconcile lock', ['app' => 'calplan', 'exception' => $e]);
		}
	}

	/** @return Reconciliation[] */
	private function run(string $userId, bool $processReview, string $trigger): array {
		$started = hrtime(true);
		$phaseStarted = $started;
		$this->repo->resetCache();
		$principalUri = 'principals/users/' . $userId;
		$ignoredUris = $this->config->getIgnoredCalendars($userId);
		$allCalendars = $this->repo->getCalendarsForPrincipal($principalUri);
		$allBusyOnly = $this->repo->getBusyOnlyCalendars($principalUri);
		$calendars = self::withoutIgnoredCalendars($allCalendars, $ignoredUris);
		$busyOnly = self::withoutIgnoredCalendars($allBusyOnly, $ignoredUris);
		$ignoredCount = count($allCalendars) + count($allBusyOnly) - count($calendars) - count($busyOnly);
		$working = $this->config->getWorkingHours($userId);
		$flaggedUris = array_fill_keys($this->config->getFlaggedCalendars($userId), true);
		$userTz = $this->config->getUserTimeZone($userId);
		$now = new \DateTimeImmutable('now', $userTz);
		$today = $now->format('Y-m-d');
		$policy = $this->config->getSchedulingPolicy($userId);
		$eventExclusions = new EventExclusionPolicy(
			$this->config->getIgnoredEventTitles($userId),
			$this->config->getEventTitleMatchMode($userId),
		);
		$calendarSetupMs = self::elapsedMs($phaseStarted);

		// Busy only matters where a slot can be placed. Include one day behind now
		// for ongoing/overnight events and query through the planning window.
		// CalendarRepository separately merges every owned slot for stale cleanup.
		$eventFrom = $now->modify('-1 day');
		$eventUntil = $now->modify('+' . $policy['planningDays'] . ' days')->setTime(23, 59, 59);
		$phaseStarted = hrtime(true);
		$eventsByCalendar = [];
		$allEvents = [];
		$rawEventCount = 0;
		$titleExcludedEventCount = 0;
		$calendarDiagnostics = [];
		$sources = [];
		foreach ($calendars as $uri => $cal) {
			$sources[(string)$uri] = [(string)$cal['id'], CalDavBackend::CALENDAR_TYPE_CALENDAR, (string)($cal['{DAV:}displayname'] ?? $uri)];
		}
		foreach ($busyOnly as $key => $cal) {
			$sources[(string)$key] = [$cal['id'], $cal['type'], $cal['name']];
		}
		foreach ($sources as $uri => [$id, $type, $name]) {
			$rawEvents = $uri === self::BIRTHDAY_CALENDAR_URI ? [] : $this->repo->getEvents($id, $eventFrom, $eventUntil, $userTz, $type);
			$events = array_values(array_filter($rawEvents, fn (CalendarEvent $event) => !$eventExclusions->ignores($event)));
			$rawEventCount += count($rawEvents);
			$titleExcludedEventCount += count($rawEvents) - count($events);
			$eventsByCalendar[$uri] = $events;
			$calendarDiagnostics[$uri] = [
				'calendar' => $name,
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

		$taskCount = 0;
		$scheduledTaskCount = 0;
		$taskReadNs = 0;
		$unscheduledTasks = [];
		$rewritten = [];
		$released = [];
		$groupsByTask = [];
		$seenUids = [];
		$restarted = array_fill_keys($this->config->getRestartedTasks($userId), true);
		$autoReschedule = $policy['autoRescheduleMinutes'] > 0;
		// 1. Per calendar the user owns: read and normalize tasks, review
		// expired blocks, pick the tasks to schedule.
		$plans = [];
		foreach ($calendars as $uri => $cal) {
			$uri = (string)$uri;
			if (!CalendarRepository::isOwnedBy($cal, $principalUri)) {
				continue;
			}
			$calendarId = (string)$cal['id'];
			$phaseStarted = hrtime(true);
			$tasks = $this->repo->getTasks($calendarId, $policy['defaultDurationMinutes'], $uri, $userTz);
			foreach ($tasks as $index => $task) {
				if ($task->autoSchedule && $this->config->isTaskPaused($userId, $task->id)) {
					// Turning Auto-schedule on is the restart action while automatic
					// rescheduling is Off; from another client than the Tasks switch
					// it leaves the review category behind. Removing it now would
					// rewrite the task inside that client's own write (handing it a
					// stale ETag), so outside a review run the next one does it.
					$this->config->setTaskPaused($userId, $task->id, false);
					if ($task->needsReview()) {
						if ($processReview) {
							$tasks[$index] = $this->rewriteCategories($calendarId, $task, $task->categoriesWithoutReview(), $rewritten);
						} else {
							$this->config->setTaskRestarted($userId, $task->id, true);
							$restarted[$task->id] = true;
						}
					}
				}
			}
			if ($processReview) {
				$tasks = $this->reviewExpiredTasks($userId, $calendarId, $tasks, $eventsByCalendar[$uri], $allEvents, $now, $restarted, $released, $rewritten);
			}
			$taskReadNs += hrtime(true) - $phaseStarted;
			$taskCount += count($tasks);
			$calendarDiagnostics[$uri]['tasksRead'] = count($tasks);
			if (isset($flaggedUris[$uri])) {
				// A calendar flagged as a whole schedules every open task in it
				// (parents with open subtasks then being containers).
				$tasks = TaskLimits::resolve(array_map(
					fn (Task $task) => $task->isDone() || $task->autoSchedule || $this->config->isTaskPaused($userId, $task->id) || (!$autoReschedule && $task->needsReview())
						? $task
						: $task->withAutoSchedule(),
					$tasks,
				));
			}
			foreach ($tasks as $task) {
				$groupsByTask[$task->id] ??= array_map(fn (array $group) => $group['id'], $task->limitGroups);
			}
			$toSchedule = [];
			foreach ($tasks as $task) {
				if (!$task->autoSchedule || $task->isDone() || $this->config->isTaskPaused($userId, $task->id)) {
					continue;
				}
				if (isset($seenUids[$task->id])) {
					// The same UID in an earlier calendar (an .ics imported twice):
					// one task, one plan.
					$unscheduledTasks[(string)$task->id] ??= ['reason' => 'duplicate_uid', 'uri' => (string)$task->objectUri];
					continue;
				}
				$seenUids[$task->id] = true;
				$toSchedule[] = $task;
			}
			if (!CalendarRepository::supportsEvents($cal)) {
				// A Tasks-only list cannot show an event: its tasks get no block
				// (and blocks stored there earlier are removed).
				foreach ($toSchedule as $task) {
					if (!$task->hasScheduledSubtasks) {
						$unscheduledTasks[(string)$task->id] = ['reason' => 'calendar_without_events', 'uri' => (string)$task->objectUri];
					}
				}
				$toSchedule = [];
			}
			$scheduledTaskCount += count($toSchedule);
			$calendarDiagnostics[$uri]['scheduledTasks'] = count($toSchedule);
			if ($toSchedule === [] && !$this->repo->hasSlotObjects($calendarId)) {
				continue;
			}
			$plans[$uri] = ['tasks' => $toSchedule, 'events' => $eventsByCalendar[$uri]];
		}

		// 2. One scheduling pass over every calendar's tasks: they compete for the
		// same free time by urgency, and the daily caps and gaps span calendars.
		$phaseStarted = hrtime(true);
		$recons = $plans === [] ? [] : $this->reconciler->reconcileCalendars(
			$plans,
			$working,
			$allEvents,
			$now,
			horizonDays: $policy['planningDays'],
			dailyCap: $policy['dailyCapMinutes'],
			taskGapMinutes: $policy['taskGapMinutes'],
			eventBufferMinutes: $policy['eventBufferMinutes'],
			dailyTaskCount: $policy['dailyTaskCount'],
			minPartMinutes: $policy['minPartMinutes'],
			released: $released,
			groupsByTask: $groupsByTask,
			priorSpent: $this->config->getSpentToday($userId, $today),
		);
		$scheduleNs = hrtime(true) - $phaseStarted;

		// 3. Per calendar: write its blocks and record the outcome. A task whose
		// blocks move to another calendar is still placed, not removed.
		$stillPlaced = [];
		foreach ($recons as $recon) {
			foreach ($recon->toPut as $put) {
				$stillPlaced[$put[0]->id] = true;
			}
			foreach ($recon->unchanged as $key) {
				$stillPlaced[IcalCodec::taskUidOfSlotKey($key)] = true;
			}
		}
		$totals = ['put' => 0, 'delete' => 0, 'unchanged' => 0, 'putFailures' => 0, 'deleteFailures' => 0];
		$plannedTasks = [];
		$unscheduledReasons = [];
		$spent = [];
		$applyNs = 0;
		foreach ($recons as $uri => $recon) {
			$calendarId = (string)$calendars[$uri]['id'];
			$tasksById = [];
			foreach ($plans[$uri]['tasks'] as $task) {
				$tasksById[$task->id] = $task;
			}
			foreach ($recon->blocks as $taskId => $slots) {
				$plannedTasks[(string)$taskId] = [
					'uri' => (string)($tasksById[$taskId]->objectUri ?? ''),
					'blocks' => array_map(fn ($slot) => [$slot->start->getTimestamp(), $slot->end->getTimestamp()], $slots),
				];
			}
			foreach ($recon->unscheduled as $taskId => $reason) {
				$unscheduledTasks[(string)$taskId] = ['reason' => $reason, 'uri' => (string)($tasksById[$taskId]->objectUri ?? '')];
				if (isset($tasksById[$taskId])) {
					try {
						$this->history->recordUnscheduled($userId, $tasksById[$taskId], $reason, $now);
					} catch (\Throwable $_) {
						// History is optional observation data; never break scheduling.
					}
				}
			}
			$phaseStarted = hrtime(true);
			$applied = $this->apply($userId, $calendarId, $recon, $now, $stillPlaced);
			$applyNs += hrtime(true) - $phaseStarted;
			foreach ($recon->spent as $block) {
				if (isset($applied['done'][$block['key']])) {
					$spent[] = $block;
				}
			}
			$totals['put'] += count($recon->toPut);
			$totals['delete'] += count($recon->toDelete);
			$totals['unchanged'] += count($recon->unchanged);
			$totals['putFailures'] += $applied['putFailures'];
			$totals['deleteFailures'] += $applied['deleteFailures'];
			$calendarDiagnostics[$uri]['placed'] = count($recon->toPut) - $applied['putFailures'];
			$calendarDiagnostics[$uri]['removed'] = count($recon->toDelete) - $applied['deleteFailures'];
			$calendarDiagnostics[$uri]['writeFailures'] = [
				'put' => $applied['putFailures'],
				'delete' => $applied['deleteFailures'],
			];
			$calendarDiagnostics[$uri]['unchanged'] = count($recon->unchanged);
			$calendarDiagnostics[$uri]['unscheduled'] = count($recon->unscheduled);
		}
		foreach ($unscheduledTasks as $entry) {
			$unscheduledReasons[$entry['reason']] = ($unscheduledReasons[$entry['reason']] ?? 0) + 1;
		}
		try {
			$this->config->addSpentToday($userId, $today, $spent);
		} catch (\Throwable $e) {
			$this->logger->warning('CalPlan could not remember the time spent today', ['app' => 'calplan', 'exception' => $e]);
		}

		// Lets the event-change listener and cron skip users with nothing to plan.
		$this->config->setPlanActive($userId, $plans !== []);
		// Which tasks could not be placed and why, and when the others are
		// planned, for the Tasks UI.
		$unscheduledChanged = $this->config->setUnscheduledTasks($userId, $unscheduledTasks);
		$this->config->setPlannedTasks($userId, $plannedTasks);
		$this->config->addRewrittenTasks($userId, $rewritten, $now->getTimestamp());

		$report = [
			'runId' => bin2hex(random_bytes(6)),
			'timestamp' => $now->format(DATE_ATOM),
			'trigger' => in_array($trigger, self::TRIGGERS, true) ? $trigger : 'other',
			'processReview' => $processReview,
			'calendars' => count($sources),
			'ignoredCalendars' => $ignoredCount,
			'eventsRead' => $rawEventCount,
			'eventsIgnoredByTitle' => $titleExcludedEventCount,
			'tasksRead' => $taskCount,
			'scheduledTasks' => $scheduledTaskCount,
			'reconciledCalendars' => count($recons),
			'placed' => $totals['put'] - $totals['putFailures'],
			'removed' => $totals['delete'] - $totals['deleteFailures'],
			'plannedWrites' => ['put' => $totals['put'], 'delete' => $totals['delete']],
			'unchanged' => $totals['unchanged'],
			'unscheduled' => count($unscheduledTasks),
			'unscheduledReasons' => $unscheduledReasons,
			'reviewed' => count($rewritten),
			'writeFailures' => ['put' => $totals['putFailures'], 'delete' => $totals['deleteFailures']],
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
		$this->lastReport = $report;
		// A background run that changed nothing is not worth one of the ten
		// diagnostics entries (they would cover less than an hour).
		$changed = $totals['put'] + $totals['delete'] + $totals['putFailures'] + $totals['deleteFailures'] + count($rewritten) > 0 || $unscheduledChanged;
		if ($trigger !== 'background' || $changed) {
			try {
				$this->config->recordReconcileDiagnostic($userId, $report);
			} catch (\Throwable $_) {
				// Diagnostics must never break scheduling.
			}
		}

		if ($this->config->isPerformanceLoggingEnabled()) {
			$this->logger->info('CalPlan reconcile metrics', [
				'app' => 'calplan',
				'user' => $userId,
				'calendars' => count($sources),
				'events' => count($allEvents),
				'tasks' => $taskCount,
				'scheduled_tasks' => $scheduledTaskCount,
				'reconciled_calendars' => count($recons),
				'planned_puts' => $totals['put'],
				'planned_deletes' => $totals['delete'],
				'unchanged' => $totals['unchanged'],
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
		return array_values($recons);
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
			fn ($uri) => !isset($ignored[(string)$uri]),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * *task* with *categories* written to its VTODO, or unchanged when the
	 * write fails. A rewritten task is noted in *rewritten* (UID => object URI).
	 *
	 * @param string[] $categories
	 * @param array<string, string> $rewritten
	 */
	private function rewriteCategories(string $calendarId, Task $task, array $categories, array &$rewritten): Task {
		if ($categories === $task->categories) {
			return $task;
		}
		try {
			$this->repo->updateTaskCategories($calendarId, $task, $categories);
			$rewritten[(string)$task->id] = (string)$task->objectUri;
			return $task->withCategories($categories);
		} catch (\Throwable $e) {
			$this->logger->warning('CalPlan could not update task review categories', [
				'app' => 'calplan',
				'exception' => $e,
			]);
			return $task;
		}
	}

	/**
	 * Review the tasks whose block ended at least the grace period ago: add
	 * the review category and, when automatic rescheduling is Off, remove the
	 * scheduling category so the slot is cleaned up and the task stays paused
	 * until Auto-schedule is explicitly enabled again. Their blocks are
	 * *released*: a task to schedule still is planned anew (until then, a
	 * started block stays where it is). A restarted task (see reconcileUser)
	 * just loses its old review category.
	 *
	 * @param Task[] $tasks
	 * @param CalendarEvent[] $events
	 * @param array<string, true> $restarted
	 * @param array<string, true> $released
	 * @param array<string, string> $rewritten
	 * @return Task[]
	 */
	private function reviewExpiredTasks(string $userId, string $calendarId, array $tasks, array $events, array $busyEvents, \DateTimeImmutable $now, array &$restarted, array &$released, array &$rewritten): array {
		// A split task is reviewed when its last part has ended; a block an
		// event took did not happen, so it is planned anew, not reviewed.
		$lastBlock = [];
		foreach ($events as $event) {
			$uid = $event->autoScheduleTaskUid;
			if ($uid !== null && (!isset($lastBlock[$uid]) || $event->end > $lastBlock[$uid]->end)) {
				$lastBlock[$uid] = $event;
			}
		}
		$lastEnd = [];
		foreach ($lastBlock as $uid => $block) {
			if (Reconciler::takenAt($block, $busyEvents) === null) {
				$lastEnd[$uid] = $block->end;
			}
		}
		$autoReschedule = $this->config->getAutoRescheduleMinutes($userId) > 0;
		$graceMinutes = $this->config->reviewGraceMinutes($userId);
		$out = [];
		foreach ($tasks as $task) {
			if (isset($restarted[$task->id])) {
				unset($restarted[$task->id]);
				$this->config->setTaskRestarted($userId, $task->id, false);
				if ($task->autoSchedule && !$task->isDone()) {
					$out[] = $this->rewriteCategories($calendarId, $task, $task->categoriesWithoutReview(), $rewritten);
					continue;
				}
			}
			$expired = isset($lastEnd[$task->id]) && self::isPastReviewGrace($lastEnd[$task->id], $now, $graceMinutes);
			if ($expired) {
				$released[$task->id] = true;
			}
			if ($task->isDone() || !($expired || (!$autoReschedule && $task->needsReview()))) {
				$out[] = $task;
				continue;
			}
			$reviewed = $this->rewriteCategories($calendarId, $task, $task->reviewCategories($autoReschedule), $rewritten);
			if (!$autoReschedule && $reviewed !== $task) {
				$this->config->setTaskPaused($userId, $task->id, true);
			}
			$out[] = $reviewed;
		}
		return $out;
	}

	public static function isPastReviewGrace(\DateTimeImmutable $slotEnd, \DateTimeImmutable $now, int $graceMinutes): bool {
		return PlanState::plusMinutes($slotEnd, max(0, $graceMinutes)) <= $now;
	}

	private static function elapsedMs(int $started): float {
		return self::nsToMs(hrtime(true) - $started);
	}

	private static function nsToMs(int $nanoseconds): float {
		return round($nanoseconds / 1_000_000, 2);
	}

	/**
	 * Write one calendar's blocks. *stillPlaced* holds the tasks that keep a
	 * block somewhere after this run (any calendar): their removed blocks are
	 * not logged as removals.
	 *
	 * @param array<string, true> $stillPlaced
	 * @return array{putFailures:int, deleteFailures:int, done: array<string, true>} done = block keys written or deleted
	 */
	private function apply(string $userId, string $calendarId, Reconciliation $recon, \DateTimeImmutable $now, array $stillPlaced): array {
		$putFailures = 0;
		$deleteFailures = 0;
		$done = [];
		$placedBlocks = [];
		foreach ($recon->toPut as [$task, $slot, $part, $partCount]) {
			$key = IcalCodec::slotKey($task->id, $part);
			// Best-effort: a single bad write must not abort the whole reconcile
			// and leave every other slot un-upgraded. Count it and let the next
			// reconcile retry (plan 0.9.1).
			try {
				$this->repo->putSlot($calendarId, $task, $slot, $now, $part, $partCount, $recon->objectUris[$key] ?? null);
			} catch (\Throwable $e) {
				$putFailures++;
				$this->logger->warning('CalPlan could not write a block', ['app' => 'calplan', 'exception' => $e]);
				continue;
			}
			$done[$key] = true;
			$placedBlocks[$task->id] = $task;
		}
		// One record per task with all its blocks, even when only some parts moved.
		foreach ($placedBlocks as $taskId => $task) {
			try {
				$this->history->recordScheduled($userId, $task, $recon->blocks[$taskId] ?? [], $now);
			} catch (\Throwable $_) {
				// Optional observation data must never change the calendar outcome.
			}
		}
		$removed = [];
		foreach ($recon->toDelete as $slotKey) {
			try {
				$this->repo->deleteSlot($calendarId, $recon->objectUris[$slotKey] ?? CalendarRepository::slotObjectUri($slotKey));
			} catch (\Throwable $e) {
				$deleteFailures++;
				$this->logger->warning('CalPlan could not remove a block', ['app' => 'calplan', 'exception' => $e]);
				continue;
			}
			$done[$slotKey] = true;
			$taskUid = IcalCodec::taskUidOfSlotKey($slotKey);
			if (!isset($stillPlaced[$taskUid])) {
				$removed[$taskUid] = true;
			}
		}
		foreach (array_keys($removed) as $taskUid) {
			try {
				$this->history->recordRemoved($userId, (string)$taskUid, $now);
			} catch (\Throwable $_) {
				// Optional observation data must never change the calendar outcome.
			}
		}
		return ['putFailures' => $putFailures, 'deleteFailures' => $deleteFailures, 'done' => $done];
	}
}
