<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Scheduling core: rank tasks (weighted-EDF: score = weight/(days_until_due+1))
 * and place each into the earliest free slot that fits duration, due date, the
 * daily cap, AND the task's DTSTART. Overdue/no-due tasks may roll forward when
 * nothing fits under the due check. This is a practical greedy heuristic informed
 * by scheduling theory, not the exact Moore–Hodgson algorithm. Pure,
 * deterministic, and idempotent.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\PlacementResult;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingHours;

class SchedulerService {
	/** Whole calendar days, in the user's timezone (*now*'s), from today to the task's due day; far-future if none. */
	public function daysUntilDue(Task $task, \DateTimeImmutable $now): float {
		$due = $task->due;
		if ($due === null) {
			return (float)Defaults::NO_DUE_HORIZON_DAYS;
		}
		$dueDay = $due->setTimezone($now->getTimezone())->setTime(0, 0, 0);
		$nowDay = $now->setTime(0, 0, 0);
		// diff() counts wall-clock days between two midnights of one timezone,
		// so a DST change in between does not lose or add a day.
		$days = (int)$nowDay->diff($dueDay)->format('%r%a');
		return max(0.0, (float)$days);
	}

	/** Higher = place first. Weighted Earliest-Deadline-First. */
	public function rankingScore(Task $task, \DateTimeImmutable $now): float {
		return $task->weight() / ($this->daysUntilDue($task, $now) + 1.0);
	}

	/**
	 * Scheduling precedence tier: 0 = DUE already passed (most overdue), 1 =
	 * DTSTART already passed (started but not yet due), 2 = on track / future.
	 * Lower tier is scheduled first. A missed DTSTART is overdue-ish but never
	 * as overdue as a missed DUE (plan 1.6.3).
	 */
	public function urgencyTier(Task $task, \DateTimeImmutable $now): int {
		if ($task->due !== null && $task->due <= $now) {
			return 0;
		}
		if ($task->start !== null && $task->start <= $now) {
			return 1;
		}
		return 2;
	}

	/** @param Task[] $tasks */
	private function eligible(array $tasks): array {
		return array_values(array_filter($tasks, fn (Task $t) => $t->autoSchedule && !$t->isDone()));
	}

	/**
	 * Where *task* can go in *slot*: a block of *minutes* starting at the
	 * slot's start or later - never before the task's DTSTART (rounded up to
	 * the 15-minute grid) - that fits in the slot, ends by the DUE when
	 * *respectDue*, and stays within the day's cap and the blocks-per-day
	 * limit. Null otherwise; a block-count rejection is noted in *why*.
	 *
	 * @param array{count: bool} $why
	 */
	private function tryFit(PlanState $st, Slot $slot, Task $task, int $minutes, bool $respectDue, array &$why): ?Slot {
		$start = $this->earliestStart($st, $slot, $task);
		if ($start >= $slot->end) {
			return null;
		}
		$end = PlanState::plusMinutes($start, $minutes);
		if ($end > $slot->end) {
			return null;
		}
		if ($respectDue && $task->due !== null && $end > $task->due) {
			return null;
		}
		$day = $st->day($start);
		if (($st->dailyMinutes[$day] ?? 0) + $minutes > $st->dailyCap) {
			return null;
		}
		if ($st->dailyTaskCount > 0 && ($st->dailyBlocks[$day] ?? 0) >= $st->dailyTaskCount) {
			$why['count'] = true;
			return null;
		}
		return new Slot($start, $end);
	}

	/**
	 * The first moment in *slot* at which *task* may start. Free slots start on
	 * the 15-minute grid or right after a placed block, so blocks follow each
	 * other without gaps; a DTSTART off the grid (10:07) is rounded up to it.
	 */
	private function earliestStart(PlanState $st, Slot $slot, Task $task): \DateTimeImmutable {
		$start = $slot->start;
		if ($task->start !== null && $task->start > $start) {
			$start = FreeSlotsService::snapUp($task->start->setTimezone($st->tz));
		}
		return $start->setTimezone($st->tz);
	}

	/**
	 * The earliest whole block for *task* across the shared working-hours pool
	 * and the task's own DTSTART windows (1.5.5), or null.
	 *
	 * @param Slot[] $extra per-task DTSTART windows
	 * @param array{count: bool} $why
	 */
	private function earliestFit(PlanState $st, array $extra, Task $task, bool $respectDue, array &$why): ?Slot {
		$best = null;
		// The pool is ordered by start, so its first fit is its earliest one.
		foreach ($st->slots as $slot) {
			$best = $this->tryFit($st, $slot, $task, $task->estimatedMinutes, $respectDue, $why);
			if ($best !== null) {
				break;
			}
		}
		foreach ($extra as $slot) {
			$fit = $this->tryFit($st, $slot, $task, $task->estimatedMinutes, $respectDue, $why);
			if ($fit !== null && ($best === null || $fit->start < $best->start)) {
				$best = $fit;
			}
		}
		return $best;
	}

	/**
	 * 1.5.5 — DTSTART overrides working hours. A task with a timed DTSTART on
	 * a working day may also use the rest of that day from its start: a
	 * DTSTART outside the day's working hours (e.g. 18:00 with hours 09-17)
	 * opens a window from it to the end of the day (busy time and the blocks
	 * placed so far subtracted); so does one inside them that leaves too little
	 * working time for the task. Never before *now* (a DTSTART earlier today
	 * opens the window from now), never on an unchecked day, past the planning
	 * window, or on another day than the DTSTART's.
	 *
	 * @return Slot[] windows at least as long as the task
	 */
	private function dtstartSlots(PlanState $st, Task $task): array {
		// An all-day start only means "from that day": no window outside working hours.
		if ($task->start === null || $task->startAllDay) {
			return [];
		}
		$dtstart = $task->start->setTimezone($st->tz);
		$day = $dtstart->setTime(0, 0, 0);
		$wd = $st->working->forWeekday((int)$day->format('N') - 1);
		$today = $st->now->setTime(0, 0, 0);
		if ($wd === null || $day < $today || $day >= $today->modify('+' . $st->horizonDays . ' days')) {
			return [];
		}
		$workStart = $day->modify('+' . $wd->startMinutes . ' minutes');
		$workEnd = $day->modify('+' . $wd->endMinutes . ' minutes');
		if ($dtstart >= $workStart && $dtstart < $workEnd
			&& PlanState::minutesBetween($dtstart, $workEnd) >= $task->estimatedMinutes) {
			return []; // the shared working-hours pool covers it
		}
		$from = FreeSlotsService::snapUp($dtstart > $st->now ? $dtstart : $st->now->setTimezone($st->tz));
		$dayEnd = FreeSlotsService::snapDown($day->setTime(23, 59, 0));
		if ($from >= $dayEnd || $from->format('Y-m-d') !== $day->format('Y-m-d')) {
			return [];
		}
		$out = [];
		foreach (FreeSlotsService::subtractBusy(new Slot($from, $dayEnd), array_merge($st->busy, $st->placed)) as $s) {
			$snapped = new Slot(FreeSlotsService::snapUp($s->start), FreeSlotsService::snapDown($s->end));
			if ($snapped->start < $snapped->end && $snapped->durationMinutes() >= $task->estimatedMinutes) {
				$out[] = $snapped;
			}
		}
		return $out;
	}

	/**
	 * Place *task* whole in the earliest fitting slot (pool or DTSTART window)
	 * and take that time. A future DUE is a hard limit: a task that cannot end
	 * by it is skipped (null), so no block ever overruns a reachable due date.
	 * An overdue or undated task rolls forward to the earliest fit (free slots
	 * start at now, never in the past).
	 *
	 * @param Slot[] $extra
	 * @param array{count: bool} $why
	 */
	private function place(PlanState $st, Task $task, array $extra, array &$why): ?Slot {
		$block = $this->earliestFit($st, $extra, $task, $task->due !== null && $task->due > $st->now, $why);
		if ($block !== null) {
			$st->charge($st->day($block->start), $task->estimatedMinutes);
			$st->occupy($block);
		}
		return $block;
	}

	/**
	 * Placement order: missed deadline first (a missed DUE outranks a missed
	 * DTSTART, which outranks every on-track task, plan 1.6.3), then weighted
	 * EDF by score desc, then the exact deadline, then SPT (shorter first),
	 * then the UID, so that equal tasks always come in the same order.
	 *
	 * @param Task[] $candidates
	 * @return Task[]
	 */
	private function placementOrder(array $candidates, \DateTimeImmutable $now): array {
		usort($candidates, function (Task $a, Task $b) use ($now): int {
			$byTier = $this->urgencyTier($a, $now) <=> $this->urgencyTier($b, $now);
			if ($byTier !== 0) {
				return $byTier;
			}
			$byScore = $this->rankingScore($b, $now) <=> $this->rankingScore($a, $now);
			if ($byScore !== 0) {
				return $byScore;
			}
			$byDue = ($a->due?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->due?->getTimestamp() ?? PHP_INT_MAX);
			if ($byDue !== 0) {
				return $byDue;
			}
			$byLength = $a->estimatedMinutes <=> $b->estimatedMinutes;
			return $byLength !== 0 ? $byLength : strcmp($a->id, $b->id);
		});
		return $candidates;
	}

	/**
	 * Compute detailed placement outcomes, including a stable reason when a task
	 * cannot be placed. The compatibility schedule() method below unwraps slots.
	 *
	 * *pinned* holds, by task id, a block that has already started: it stays
	 * as it is (a task in progress is not moved away while it is being worked
	 * on, and the expired-block review can see it end), holds its time and
	 * counts against its day's limits. *spent* is block time already used
	 * today by blocks that no longer exist or are being removed (completed
	 * tasks, moved blocks): it counts against today's limits too.
	 *
	 * @param Task[]      $tasks
	 * @param BusyBlock[] $busy
	 * @param array<string, Slot> $pinned
	 * @param list<array{day: string, minutes: int}> $spent
	 * @return array<string, PlacementResult>
	 */
	public function scheduleDetailed(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0, array $pinned = [], array $spent = []): array {
		$tz = $now->getTimezone();
		// The same instants in the user's timezone: days are always the user's.
		$tasks = array_map(fn (Task $t) => $t->withTimesIn($tz), $tasks);
		$candidates = $this->placementOrder($this->eligible($tasks), $now);

		$minEst = Defaults::DEFAULT_ESTIMATE_MINUTES;
		foreach ($candidates as $t) {
			$minEst = min($minEst, $t->estimatedMinutes);
		}
		$st = new PlanState($now, $working, $busy, $horizonDays, $dailyCap, $dailyTaskCount, $taskGapMinutes,
			FreeSlotsService::freeSlots($working, $busy, $now, $horizonDays, $minEst));
		foreach ($spent as $entry) {
			$st->charge($entry['day'], $entry['minutes']);
		}
		$pins = [];
		foreach ($candidates as $task) {
			if (isset($pinned[$task->id])) {
				$block = new Slot($pinned[$task->id]->start->setTimezone($tz), $pinned[$task->id]->end->setTimezone($tz));
				$pins[$task->id] = $block;
				$st->charge($st->day($block->start), PlanState::minutesBetween($block->start, $block->end));
				$st->occupy($block);
			}
		}

		$outcomes = [];
		foreach ($candidates as $task) {
			$why = ['count' => false];
			$block = $pins[$task->id] ?? $this->place($st, $task, $this->dtstartSlots($st, $task), $why);
			$outcomes[$task->id] = new PlacementResult($block, $block === null ? $this->unscheduledReason($task, $st, $why) : null);
		}
		return $outcomes;
	}

	/** @return array<string, Slot|null> */
	public function schedule(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0): array {
		$out = [];
		foreach ($this->scheduleDetailed($tasks, $working, $busy, $now, $horizonDays, $dailyCap, $taskGapMinutes, $dailyTaskCount) as $taskId => $result) {
			$out[(string)$taskId] = $result->slot;
		}
		return $out;
	}

	/** @param array{count: bool} $why */
	private function unscheduledReason(Task $task, PlanState $st, array $why): string {
		// A rejection by the block count is only noted where the time itself
		// would have fitted (before a reachable deadline).
		if ($why['count']) {
			return 'daily_task_count_reached';
		}
		$horizonEnd = $st->now->setTime(0, 0)->modify('+' . $st->horizonDays . ' days');
		if ($task->start !== null && $task->start >= $horizonEnd) {
			return 'starts_after_horizon';
		}
		$maxWindow = 0;
		for ($weekday = 0; $weekday < 7; $weekday++) {
			$day = $st->working->forWeekday($weekday);
			if ($day !== null) {
				$maxWindow = max($maxWindow, $day->endMinutes - $day->startMinutes);
			}
		}
		if ($maxWindow === 0) {
			return 'no_working_hours';
		}
		if ($task->estimatedMinutes > $st->dailyCap) {
			return 'duration_exceeds_daily_cap';
		}
		if ($task->estimatedMinutes > $maxWindow && $task->start === null) {
			return 'duration_exceeds_openings';
		}
		if ($task->due !== null && $task->due > $st->now) {
			return 'no_fit_before_due';
		}
		return 'no_capacity_within_horizon';
	}
}
