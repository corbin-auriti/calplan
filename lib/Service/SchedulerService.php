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
		$due = $task->schedulingDue();
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
		return $task->schedulingWeight() / ($this->daysUntilDue($task, $now) + 1.0);
	}

	/**
	 * Scheduling precedence tier: 0 = DUE already passed (most overdue), 1 =
	 * DTSTART already passed (started but not yet due), 2 = on track / future.
	 * Lower tier is scheduled first. A missed DTSTART is overdue-ish but never
	 * as overdue as a missed DUE (plan 1.6.3). A subtask's tree is ranked by
	 * its top task's dates (Task::schedulingDue/schedulingStart).
	 */
	public function urgencyTier(Task $task, \DateTimeImmutable $now): int {
		$due = $task->schedulingDue();
		if ($due !== null && $due <= $now) {
			return 0;
		}
		$start = $task->schedulingStart();
		if ($start !== null && $start <= $now) {
			return 1;
		}
		return 2;
	}

	/** @param Task[] $tasks */
	private function eligible(array $tasks): array {
		// A task with subtasks still to schedule is a container: they get the
		// blocks (the Tasks sidebar switch flags them together), not the parent.
		// Once none is left (all done, or none flagged) it is planned itself.
		return array_values(array_filter($tasks, fn (Task $t) => $t->autoSchedule && !$t->isDone() && !$t->hasScheduledSubtasks));
	}

	/**
	 * Where *task* can go in *slot*: a block of *minutes* starting at the
	 * slot's start or later - never before the task's DTSTART (rounded up to
	 * the 15-minute grid) or *notBefore* - that fits in the slot, ends by the DUE when
	 * *respectDue*, and stays within the day's cap, the blocks-per-day limit and
	 * the limits of the task's groups (Task::$limitGroups). Null otherwise; a
	 * block-count or group-limit rejection is noted in *why*.
	 *
	 * @param array{count: bool, limit: bool} $why
	 */
	private function tryFit(PlanState $st, Slot $slot, Task $task, int $minutes, bool $respectDue, ?\DateTimeImmutable $notBefore, array &$why): ?Slot {
		$start = $this->earliestStart($st, $slot, $task, $notBefore);
		if ($start >= $slot->end) {
			return null;
		}
		$end = PlanState::plusMinutes($start, $minutes);
		if ($end > $slot->end) {
			return null;
		}
		$due = $task->schedulingDue();
		if ($respectDue && $due !== null && $end > $due) {
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
		foreach ($task->limitGroups as $group) {
			if (($group['minutes'] > 0 && ($st->groupMinutes[$group['id']][$day] ?? 0) + $minutes > $group['minutes'])
				|| ($group['blocks'] > 0 && ($st->groupBlocks[$group['id']][$day] ?? 0) >= $group['blocks'])) {
				$why['limit'] = true;
				return null;
			}
		}
		return new Slot($start, $end);
	}

	/**
	 * The first moment in *slot* at which *task* may start. Free slots start on
	 * the 15-minute grid or right after a placed block, so blocks follow each
	 * other without gaps; a DTSTART off the grid (10:07) is rounded up to it.
	 * A subtask also waits for its top task's DTSTART (Task::scheduledFrom).
	 */
	private function earliestStart(PlanState $st, Slot $slot, Task $task, ?\DateTimeImmutable $notBefore): \DateTimeImmutable {
		$start = $slot->start;
		$from = $task->scheduledFrom();
		if ($from !== null && $from > $start) {
			$start = FreeSlotsService::snapUp($from->setTimezone($st->tz));
		}
		if ($notBefore !== null && $notBefore > $start) {
			$start = $notBefore;
		}
		return $start->setTimezone($st->tz);
	}

	/**
	 * The earliest whole block for *task* across the shared working-hours pool
	 * and the task's own DTSTART windows (1.5.5), or null.
	 *
	 * @param Slot[] $extra per-task DTSTART windows
	 * @param array{count: bool, limit: bool} $why
	 */
	private function earliestFit(PlanState $st, array $extra, Task $task, bool $respectDue, ?\DateTimeImmutable $notBefore, array &$why, bool $withPool = true): ?Slot {
		$best = null;
		// The pool is ordered by start, so its first fit is its earliest one.
		foreach ($withPool ? $st->slots : [] as $slot) {
			$best = $this->tryFit($st, $slot, $task, $task->estimatedMinutes, $respectDue, $notBefore, $why);
			if ($best !== null) {
				break;
			}
		}
		foreach ($extra as $slot) {
			$fit = $this->tryFit($st, $slot, $task, $task->estimatedMinutes, $respectDue, $notBefore, $why);
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
	 * @return Slot[] windows at least as long as the task, or as one part of it when it may be split
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
		$needed = $task->canSplit ? min($task->estimatedMinutes, $st->minPartMinutes) : $task->estimatedMinutes;
		$out = [];
		foreach (FreeSlotsService::subtractBusy(new Slot($from, $dayEnd), array_merge($st->busy, $st->placed)) as $s) {
			$snapped = new Slot(FreeSlotsService::snapUp($s->start), FreeSlotsService::snapDown($s->end));
			if ($snapped->start < $snapped->end && $snapped->durationMinutes() >= $needed) {
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
	 * @param array{count: bool, limit: bool} $why
	 */
	private function place(PlanState $st, Task $task, array $extra, ?\DateTimeImmutable $notBefore, array &$why, bool $withPool = true): ?Slot {
		$due = $task->schedulingDue();
		$block = $this->earliestFit($st, $extra, $task, $due !== null && $due > $st->now, $notBefore, $why, $withPool);
		if ($block !== null) {
			$st->charge($st->day($block->start), $task->estimatedMinutes, self::groupIds($task));
			$st->occupy($block);
		}
		return $block;
	}

	/**
	 * Fill the earliest free time with a task that (or whose ancestor) allows
	 * splitting: one whole block when it fits where it can first start, else
	 * parts, so the day's caps and limits are used up before the next day. Parts
	 * are at least the minimum part (a task shorter than that stays whole), on
	 * the 15-minute grid, and never leave a shorter remainder; each part respects
	 * DTSTART, DUE, the daily caps and the task's limit groups exactly like a
	 * whole block, and counts as one block. Parts may also use the task's
	 * DTSTART windows (*extra*). As in place(), a reachable DUE is never
	 * overrun, while an overdue or undated task may roll past it. All or
	 * nothing: the state only changes when every minute found a place.
	 *
	 * @param Slot[] $extra
	 * @param array{count: bool, limit: bool} $why
	 * @return Slot[]|null the blocks in time order (one for a whole block), or null
	 */
	private function placeInParts(PlanState $st, Task $task, ?\DateTimeImmutable $notBefore, array $extra, array &$why): ?array {
		if (!$task->canSplit) {
			return null;
		}
		$due = $task->schedulingDue();
		$respectDue = $due !== null && $due > $st->now;
		$trial = clone $st;
		$pool = $extra === [] ? $trial->slots : self::byStart(array_merge($trial->slots, $extra));
		$parts = [];
		$remaining = $task->estimatedMinutes;
		while ($remaining > 0) {
			// Parts follow each other: each starts after the previous one.
			$part = $this->nextPart($trial, $pool, $task, $remaining, $respectDue, $parts !== [] ? end($parts)->end : $notBefore, $why);
			if ($part === null) {
				return null;
			}
			$minutes = PlanState::minutesBetween($part->start, $part->end);
			$parts[] = $part;
			$trial->charge($trial->day($part->start), $minutes, self::groupIds($task));
			$trial->occupy($part);
			$pool = PlanState::without($pool, $part, $trial->taskGapMinutes);
			$remaining -= $minutes;
		}
		$st->adopt($trial);
		return $parts;
	}

	/**
	 * The earliest part of *task* that fits in *pool*, or null. A part takes as
	 * much of its slot as the day's caps and the task's limit groups allow, up
	 * to what remains of the task.
	 *
	 * @param Slot[] $pool
	 * @param array{count: bool, limit: bool} $why
	 */
	private function nextPart(PlanState $st, array $pool, Task $task, int $remaining, bool $respectDue, ?\DateTimeImmutable $notBefore, array &$why): ?Slot {
		$min = $st->minPartMinutes;
		$grid = Defaults::SLOT_GRANULARITY_MINUTES;
		$due = $task->schedulingDue();
		$smallest = min($min, $remaining);
		foreach ($pool as $slot) {
			if ($notBefore !== null && $slot->end <= $notBefore) {
				continue;
			}
			$start = $this->earliestStart($st, $slot, $task, $notBefore);
			$end = $respectDue && $due !== null && $due < $slot->end ? $due : $slot->end;
			$room = PlanState::minutesBetween($start, $end);
			if ($room < $smallest) {
				continue; // no time here, before the deadline
			}
			$day = $st->day($start);
			if ($st->dailyTaskCount > 0 && ($st->dailyBlocks[$day] ?? 0) >= $st->dailyTaskCount) {
				$why['count'] = true;
				continue;
			}
			$room = min($room, $st->dailyCap - ($st->dailyMinutes[$day] ?? 0));
			$timeRoom = $room;
			foreach ($task->limitGroups as $group) {
				if ($group['blocks'] > 0 && ($st->groupBlocks[$group['id']][$day] ?? 0) >= $group['blocks']) {
					$why['limit'] = true;
					continue 2;
				}
				if ($group['minutes'] > 0) {
					$room = min($room, $group['minutes'] - ($st->groupMinutes[$group['id']][$day] ?? 0));
				}
			}
			if ($room >= $remaining) {
				$minutes = $remaining; // the rest fits whole, whatever its length
			} else {
				$minutes = intdiv(max(0, $room), $grid) * $grid;
				if ($remaining - $minutes < $min) {
					// Never leave a remainder shorter than a part; stay on the grid.
					$minutes = intdiv($remaining - $min, $grid) * $grid;
				}
			}
			// A part, or the whole of a task shorter than a part.
			if ($minutes >= $min || ($minutes > 0 && $minutes === $remaining)) {
				return new Slot($start, PlanState::plusMinutes($start, $minutes));
			}
			if ($timeRoom >= $smallest && $room < $smallest) {
				$why['limit'] = true; // only the task's daily limit stood in the way
			}
		}
		return null;
	}

	/**
	 * Placement order. A task tree - a top task with all its subtasks - is one
	 * unit, ranked by the top task's due date, start and priority and placed
	 * in the tree's depth-first sequence (Task::$sequence: Tasks' manual
	 * order); a task without parent is a unit of its own. Units: missed
	 * deadline first (a missed DUE outranks a missed DTSTART, which outranks
	 * every on-track unit, plan 1.6.3), then weighted EDF by score desc, then
	 * the exact deadline, then SPT (shorter first; a tree by its total).
	 *
	 * @param Task[] $candidates
	 * @return Task[]
	 */
	private function placementOrder(array $candidates, \DateTimeImmutable $now): array {
		$units = [];
		foreach ($candidates as $task) {
			// "\0" keeps a lone task's key apart from any tree's top-task UID.
			$units["\0" . ($task->rootUid() ?? "\0" . $task->id)][] = $task;
		}
		$ranked = [];
		foreach ($units as $key => $unit) {
			usort($unit, fn (Task $a, Task $b) => self::compareSequence($a->sequence, $b->sequence));
			$ranked[] = [
				'key' => (string)$key,
				// Every task of a unit has the same scheduling due date, start and priority.
				'lead' => $unit[0],
				'tasks' => $unit,
				'minutes' => array_sum(array_map(fn (Task $t) => $t->estimatedMinutes, $unit)),
			];
		}
		usort($ranked, function (array $a, array $b) use ($now): int {
			$byTier = $this->urgencyTier($a['lead'], $now) <=> $this->urgencyTier($b['lead'], $now);
			if ($byTier !== 0) {
				return $byTier;
			}
			$byScore = $this->rankingScore($b['lead'], $now) <=> $this->rankingScore($a['lead'], $now);
			if ($byScore !== 0) {
				return $byScore;
			}
			$byDue = ($a['lead']->schedulingDue()?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['lead']->schedulingDue()?->getTimestamp() ?? PHP_INT_MAX);
			if ($byDue !== 0) {
				return $byDue;
			}
			$byLength = $a['minutes'] <=> $b['minutes'];
			return $byLength !== 0 ? $byLength : strcmp($a['key'], $b['key']);
		});
		$out = [];
		foreach ($ranked as $unit) {
			foreach ($unit['tasks'] as $task) {
				$out[] = $task;
			}
		}
		return $out;
	}

	/** Lexicographic order of two sibling-position paths (a prefix comes first). */
	private static function compareSequence(array $a, array $b): int {
		$n = min(count($a), count($b));
		for ($i = 0; $i < $n; $i++) {
			if ($a[$i] !== $b[$i]) {
				return $a[$i] <=> $b[$i];
			}
		}
		return count($a) <=> count($b);
	}

	/** @return string[] the ids of the limit groups that bind *task* */
	private static function groupIds(Task $task): array {
		return array_map(fn (array $group) => $group['id'], $task->limitGroups);
	}

	/** @param Slot[] $slots @return Slot[] ordered by start */
	private static function byStart(array $slots): array {
		usort($slots, fn (Slot $a, Slot $b) => $a->start <=> $b->start);
		return $slots;
	}

	/**
	 * Compute detailed placement outcomes, including a stable reason when a task
	 * cannot be placed. The compatibility schedule() method below unwraps slots.
	 *
	 * *pinned* holds, by task id, blocks that have already started: they stay
	 * as they are (a task in progress is not moved away while it is being
	 * worked on, and the expired-block review can see it end), hold their time
	 * and count against their day's limits. For a task that may be split,
	 * only what they do not cover is placed, after them. *spent* is block time
	 * already used today by blocks that no longer exist or are being removed
	 * (completed tasks, moved blocks): it counts against today's limits too.
	 *
	 * @param Task[]      $tasks
	 * @param BusyBlock[] $busy
	 * @param array<string, Slot[]> $pinned
	 * @param list<array{day: string, minutes: int, groups: string[]}> $spent
	 * @return array<string, PlacementResult>
	 */
	public function scheduleDetailed(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0, int $minPartMinutes = Defaults::MIN_PART_MINUTES, array $pinned = [], array $spent = []): array {
		$tz = $now->getTimezone();
		// The same instants in the user's timezone: days are always the user's.
		$tasks = array_map(fn (Task $t) => $t->withTimesIn($tz), $tasks);
		$candidates = $this->placementOrder($this->eligible($tasks), $now);

		$minEst = Defaults::DEFAULT_ESTIMATE_MINUTES;
		foreach ($candidates as $t) {
			// A splittable task can use gaps as short as one part.
			$minEst = min($minEst, $t->estimatedMinutes, $t->canSplit ? $minPartMinutes : PHP_INT_MAX);
		}
		$st = new PlanState($now, $working, $busy, $horizonDays, $dailyCap, $dailyTaskCount, $taskGapMinutes, $minPartMinutes,
			FreeSlotsService::freeSlots($working, $busy, $now, $horizonDays, $minEst));
		foreach ($spent as $entry) {
			$st->charge($entry['day'], $entry['minutes'], $entry['groups']);
		}
		$pins = [];
		foreach ($candidates as $task) {
			foreach (self::byStart($pinned[$task->id] ?? []) as $block) {
				$block = new Slot($block->start->setTimezone($tz), $block->end->setTimezone($tz));
				$pins[$task->id][] = $block;
				$st->charge($st->day($block->start), PlanState::minutesBetween($block->start, $block->end), self::groupIds($task));
				$st->occupy($block);
			}
		}

		// Subtasks of a common parent run in sequence, in the ranking order: a
		// task never starts before the end of the last block already placed
		// under any of its ancestors (so a later lesson cannot fill a gap before
		// an earlier one). Tasks without a parent stay free to fill any gap.
		$sequenceEnd = [];
		$outcomes = [];
		foreach ($candidates as $task) {
			$why = ['count' => false, 'limit' => false];
			$notBefore = null;
			foreach ($task->ancestors as $ancestor) {
				if (isset($sequenceEnd[$ancestor]) && ($notBefore === null || $sequenceEnd[$ancestor] > $notBefore)) {
					$notBefore = $sequenceEnd[$ancestor];
				}
			}
			$blocks = $this->placeTask($st, $task, $pins[$task->id] ?? [], $notBefore, $why, $complete);
			if ($blocks !== []) {
				$lastEnd = end($blocks)->end;
				foreach ($task->ancestors as $ancestor) {
					if (!isset($sequenceEnd[$ancestor]) || $lastEnd > $sequenceEnd[$ancestor]) {
						$sequenceEnd[$ancestor] = $lastEnd;
					}
				}
			}
			$reason = $complete ? null : $this->unscheduledReason($task, $st, $why);
			$outcomes[$task->id] = new PlacementResult($blocks[0] ?? null, $reason, count($blocks) > 1 ? $blocks : []);
		}
		return $outcomes;
	}

	/**
	 * The blocks of *task*: its started blocks (*pins*), kept as they are, and
	 * new ones. *complete* tells whether they cover the task: a task that may
	 * be split gets its remainder placed after its started parts, and keeps
	 * those even when the remainder finds no room.
	 *
	 * @param Slot[] $pins
	 * @param array{count: bool, limit: bool} $why
	 * @return Slot[] in time order
	 */
	private function placeTask(PlanState $st, Task $task, array $pins, ?\DateTimeImmutable $notBefore, array &$why, ?bool &$complete): array {
		$complete = true;
		if ($pins !== []) {
			$covered = 0;
			foreach ($pins as $pin) {
				$covered += PlanState::minutesBetween($pin->start, $pin->end);
			}
			$remaining = $task->estimatedMinutes - $covered;
			if (!$task->canSplit || $remaining <= 0) {
				return $pins;
			}
			$rest = $task->withEstimatedMinutes($remaining);
			$after = end($pins)->end;
			$more = $this->placeInParts($st, $rest, $notBefore !== null && $notBefore > $after ? $notBefore : $after, $this->dtstartSlots($st, $rest), $why);
			$complete = $more !== null;
			return array_merge($pins, $more ?? []);
		}
		$extra = $this->dtstartSlots($st, $task);
		if ($task->canSplit) {
			// A splittable task fills the earliest free time, split where it does
			// not fit whole - a whole block in a window its DTSTART opens (1.5.5)
			// comes first, as for every other task; otherwise the window's time
			// is filled too.
			$block = $extra !== [] ? $this->place($st, $task, $extra, $notBefore, $why, false) : null;
			$blocks = $block !== null ? [$block] : ($this->placeInParts($st, $task, $notBefore, $extra, $why) ?? []);
		} else {
			$block = $this->place($st, $task, $extra, $notBefore, $why);
			$blocks = $block !== null ? [$block] : [];
		}
		$complete = $blocks !== [];
		return $blocks;
	}

	/** @return array<string, Slot|null> */
	public function schedule(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0): array {
		$out = [];
		foreach ($this->scheduleDetailed($tasks, $working, $busy, $now, $horizonDays, $dailyCap, $taskGapMinutes, $dailyTaskCount) as $taskId => $result) {
			$out[(string)$taskId] = $result->slot;
		}
		return $out;
	}

	/** @param array{count: bool, limit: bool} $why */
	private function unscheduledReason(Task $task, PlanState $st, array $why): string {
		// Rejections by the block count or a daily limit are only noted where
		// the time itself would have fitted (before a reachable deadline).
		if ($why['count']) {
			return 'daily_task_count_reached';
		}
		if ($why['limit']) {
			return 'task_daily_limit_reached';
		}
		$horizonEnd = $st->now->setTime(0, 0)->modify('+' . $st->horizonDays . ' days');
		$from = $task->scheduledFrom();
		if ($from !== null && $from >= $horizonEnd) {
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
		// Splitting gets around both lengths, so they cannot be the cause then.
		if (!$task->canSplit) {
			if ($task->estimatedMinutes > $st->dailyCap) {
				return 'duration_exceeds_daily_cap';
			}
			if ($task->estimatedMinutes > $maxWindow && $task->start === null) {
				return 'duration_exceeds_openings';
			}
		}
		$due = $task->schedulingDue();
		if ($due !== null && $due > $st->now) {
			return 'no_fit_before_due';
		}
		return 'no_capacity_within_horizon';
	}
}
