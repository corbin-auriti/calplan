<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The reconciliation layer: turn tasks + current calendar events into an
 * idempotent plan of puts/deletes. Running reconcile twice on the same inputs
 * yields zero operations the second time, so the whole system is safe to re-run
 * on every cron tick.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\PlacementResult;
use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingHours;

class Reconciler {
	public function __construct(
		private SchedulerService $scheduler,
	) {
	}

	/**
	 * Plan every calendar's tasks in ONE scheduling pass, then diff each
	 * calendar's desired blocks against its existing ones. Tasks thus compete
	 * for the same free time by urgency whatever their calendar, the daily caps
	 * and the recovery gap span calendars, and no two blocks overlap. Task UIDs
	 * must be unique across the plans (ReconcileService drops duplicates).
	 *
	 * A block that has already started is kept as it is while its task is
	 * still to be scheduled - unless the task is in *released*: the
	 * expired-block review of this run handled it, so it is planned anew - or
	 * a real event now overlaps it: it did not happen as planned, so its task
	 * is planned anew from now. The time of started blocks that go away (a
	 * completed task's, a released one's; for an overlapped one, only its time
	 * before the event) still counts against today's limits, as does
	 * *priorSpent*, the time of blocks removed earlier today; each
	 * Reconciliation lists in $spent the started blocks it removes, for the
	 * caller to remember.
	 *
	 * @param array<string, array{tasks: Task[], events: CalendarEvent[]}> $plans keyed by calendar
	 * @param CalendarEvent[] $allEvents every calendar's events (the busy time)
	 * @param array<string, true> $released task UIDs whose blocks the review released
	 * @param list<array{task: string, day: string, minutes: int}> $priorSpent
	 * @return array<string, Reconciliation> keyed like $plans
	 */
	public function reconcileCalendars(array $plans, WorkingHours $working, array $allEvents, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $eventBufferMinutes = 0, int $dailyTaskCount = 0, array $released = [], array $priorSpent = []): array {
		$today = $now->format('Y-m-d');
		$allTasks = [];
		$pinned = [];
		$spentNow = [];
		foreach ($plans as $key => $plan) {
			$scheduled = [];
			foreach ($plan['tasks'] as $task) {
				$allTasks[] = $task;
				$scheduled[$task->id] = true;
			}
			$spentNow[$key] = [];
			foreach ($this->existingPlacements($plan['events']) as $taskUid => $event) {
				$taskUid = (string)$taskUid;
				if ($event->start > $now) {
					continue;
				}
				$takenAt = self::takenAt($event, $allEvents);
				if ($takenAt === null && isset($scheduled[$taskUid]) && !isset($released[$taskUid])) {
					$pinned[$taskUid] = new Slot($event->start, $event->end);
				} elseif ($event->start->setTimezone($now->getTimezone())->format('Y-m-d') === $today) {
					// Leaving: what of it lies before now, and before an event
					// that took its time, was spent today.
					$until = $event->end < $now ? $event->end : $now;
					if ($takenAt !== null && $takenAt < $until) {
						$until = $takenAt;
					}
					$spentNow[$key][] = [
						'task' => $taskUid,
						'start' => $event->start->getTimestamp(),
						'day' => $today,
						'minutes' => intdiv(max(0, $until->getTimestamp() - $event->start->getTimestamp()), 60),
					];
				}
			}
		}
		$spent = [];
		foreach (array_merge($priorSpent, ...array_values($spentNow)) as $entry) {
			if ($entry['day'] === $today && $entry['minutes'] > 0) {
				$spent[] = ['day' => $today, 'minutes' => $entry['minutes']];
			}
		}
		$outcomes = $this->scheduler->scheduleDetailed($allTasks, $working, $this->busy($allEvents, $eventBufferMinutes), $now, $horizonDays, $dailyCap, $taskGapMinutes, $dailyTaskCount, $pinned, $spent);
		$out = [];
		foreach ($plans as $key => $plan) {
			$out[$key] = $this->diff($plan['tasks'], $outcomes, $plan['events'], $now);
			$out[$key]->spent = $spentNow[$key];
		}
		return $out;
	}

	/**
	 * The first moment of *block* that a real event (opaque, not one of
	 * CalPlan's blocks) takes, or null when none overlaps it. A started block
	 * an event took did not happen as planned.
	 *
	 * @param CalendarEvent[] $events
	 */
	public static function takenAt(CalendarEvent $block, array $events): ?\DateTimeImmutable {
		$at = null;
		foreach ($events as $e) {
			if ($e->autoScheduleTaskUid !== null || ($e->transp ?? null) === 'TRANSPARENT' || $e->end <= $e->start
				|| $e->start >= $block->end || $e->end <= $block->start) {
				continue;
			}
			$from = $e->start > $block->start ? $e->start : $block->start;
			if ($at === null || $from < $at) {
				$at = $from;
			}
		}
		return $at;
	}

	/**
	 * @param CalendarEvent[] $events
	 * @return array<array-key, CalendarEvent> our own placed blocks, keyed by task UID (a numeric UID is an int key)
	 */
	private function existingPlacements(array $events): array {
		$out = [];
		foreach ($events as $e) {
			if ($e->autoScheduleTaskUid !== null) {
				$out[$e->autoScheduleTaskUid] = $e;
			}
		}
		return $out;
	}

	/**
	 * Busy time: real events only - our own blocks are re-derived and
	 * TRANSPARENT events are free (plan 1.5.1), and so is an event that
	 * lasts no time - widened by the event buffer.
	 *
	 * @param CalendarEvent[] $events
	 * @return BusyBlock[]
	 */
	private function busy(array $events, int $eventBufferMinutes): array {
		$busy = [];
		foreach ($events as $e) {
			if ($e->autoScheduleTaskUid !== null || ($e->transp ?? null) === 'TRANSPARENT' || $e->end <= $e->start) {
				continue;
			}
			$busy[] = $eventBufferMinutes > 0
				? new BusyBlock(PlanState::plusMinutes($e->start, -$eventBufferMinutes), PlanState::plusMinutes($e->end, $eventBufferMinutes), $e->uid)
				: $e->toBusy();
		}
		return $busy;
	}

	/**
	 * The puts/deletes that turn one calendar's existing blocks into the
	 * desired ones of its tasks' *outcomes*.
	 *
	 * @param Task[] $tasks
	 * @param array<array-key, PlacementResult> $outcomes by task id (a numeric UID is an int key)
	 * @param CalendarEvent[] $events
	 */
	private function diff(array $tasks, array $outcomes, array $events, \DateTimeImmutable $now): Reconciliation {
		$existing = $this->existingPlacements($events);

		// toPut entries are [task, slot]; unchanged and toDelete hold task UIDs.
		$recon = new Reconciliation();
		foreach ($existing as $uid => $event) {
			if ($event->objectUri !== null) {
				$recon->objectUris[(string)$uid] = $event->objectUri;
			}
		}
		$desired = [];
		foreach ($tasks as $task) {
			$outcome = $outcomes[$task->id] ?? null;
			if ($outcome === null) {
				continue; // not eligible (e.g. a container): its block goes
			}
			if ($outcome->slot === null) {
				$recon->unscheduled[$task->id] = $outcome->reason ?? 'no_capacity_within_horizon';
				continue;
			}
			$desired[$task->id] = true;
			$prev = $existing[$task->id] ?? null;
			// Re-PUT when the time changed OR the mirrored content (notes,
			// priority, %, location, url, title, class) changed, so editing a
			// task's content re-mirrors into its block (plan 1.5.11).
			if ($prev === null || !$this->placementMatches($prev, $outcome->slot, $task, $now)) {
				$recon->toPut[] = [$task, $outcome->slot];
			} else {
				$recon->unchanged[] = $task->id;
			}
		}

		// Blocks to remove: task no longer eligible, or eligible but unscheduled.
		foreach ($existing as $uid => $_) {
			if (!isset($desired[$uid])) {
				$recon->toDelete[] = (string)$uid;
			}
		}
		return $recon;
	}

	private function placementMatches(CalendarEvent $existing, Slot $desired, Task $task, \DateTimeImmutable $now): bool {
		if ($existing->start != $desired->start || $existing->end != $desired->end) {
			return false;
		}
		$expected = IcalCodec::slotContent($task, $now);
		return $existing->summary === $expected['summary']
			&& $existing->description === $expected['description']
			&& $existing->location === $expected['location']
			&& $existing->url === $expected['url']
			&& $existing->priority === $expected['priority']
			&& $existing->classification === $expected['classification'];
	}
}
