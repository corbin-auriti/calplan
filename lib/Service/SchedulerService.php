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
	/** Whole days from *now* to the task's due date; far-future if none. */
	public function daysUntilDue(Task $task, \DateTimeImmutable $now): float {
		if ($task->due === null) {
			return (float)Defaults::NO_DUE_HORIZON_DAYS;
		}
		$dueDay = $task->due->setTime(0, 0, 0);
		$nowDay = $now->setTime(0, 0, 0);
		$days = (int)floor(($dueDay->getTimestamp() - $nowDay->getTimestamp()) / 86400);
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

	/** Insert *slot* keeping the list ordered by start time. */
	private function insertSorted(array &$slots, Slot $slot): void {
		$lo = 0;
		$hi = count($slots);
		while ($lo < $hi) {
			$mid = (int)floor(($lo + $hi) / 2);
			if ($slots[$mid]->start < $slot->start) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		array_splice($slots, $lo, 0, [$slot]);
	}

	/**
	 * Does *task* fit in *slot*? Returns the would-be [start,end] placement, or null.
	 * The usable start is clamped to the task's DTSTART (never placed before it);
	 * the placement must fit within the slot, end <= DUE when $respectDue, and not
	 * exceed the per-day cap.
	 *
	 * @param array<string,int> $dailyUsed
	 * @return array{start:\DateTimeImmutable,end:\DateTimeImmutable}|null
	 */
	private function tryFit(Slot $s, Task $task, array $dailyUsed, int $dailyCap, bool $respectDue, array $dailyCountUsed = [], int $dailyTaskCount = 0, ?bool &$countRejected = null): ?array {
		$usableStart = $s->start;
		if ($task->start !== null && $task->start > $usableStart) {
			$usableStart = $task->start;
		}
		if ($usableStart >= $s->end) {
			return null;
		}
		$usableEnd = $usableStart->modify('+' . $task->estimatedMinutes . ' minutes');
		if ($usableEnd > $s->end) {
			return null;
		}
		if ($respectDue && $task->due !== null && $usableEnd > $task->due) {
			return null;
		}
		$dayKey = $usableStart->setTime(0, 0, 0)->format('Y-m-d');
		if (($dailyUsed[$dayKey] ?? 0) + $task->estimatedMinutes > $dailyCap) {
			return null;
		}
		if ($dailyTaskCount > 0 && ($dailyCountUsed[$dayKey] ?? 0) >= $dailyTaskCount) {
			$countRejected = true;
			return null;
		}
		return ['start' => $usableStart, 'end' => $usableEnd];
	}

	/**
	 * Earliest placement across the shared working-hours slots plus per-task
	 * DTSTART-derived slots (1.5.5). Returns the chosen source descriptor or null.
	 *
	 * @param Slot[]            $slots   shared working-hours pool (consumed on use)
	 * @param Slot[]            $extra   per-task DTSTART slots (throwaway)
	 * @param array<string,int> $dailyUsed
	 * @return array{start:\DateTimeImmutable,end:\DateTimeImmutable,shared:bool,i:?int}|null
	 */
	private function earliestSource(array $slots, array $extra, Task $task, array $dailyUsed, int $dailyCap, bool $respectDue, array $dailyCountUsed = [], int $dailyTaskCount = 0, ?bool &$countRejected = null): ?array {
		$best = null;
		foreach ($slots as $i => $s) {
			$place = $this->tryFit($s, $task, $dailyUsed, $dailyCap, $respectDue, $dailyCountUsed, $dailyTaskCount, $countRejected);
			if ($place !== null && ($best === null || $place['start'] < $best['start'])) {
				$best = ['start' => $place['start'], 'end' => $place['end'], 'shared' => true, 'i' => $i];
			}
		}
		foreach ($extra as $s) {
			$place = $this->tryFit($s, $task, $dailyUsed, $dailyCap, $respectDue, $dailyCountUsed, $dailyTaskCount, $countRejected);
			if ($place !== null && ($best === null || $place['start'] < $best['start'])) {
				$best = ['start' => $place['start'], 'end' => $place['end'], 'shared' => false, 'i' => null];
			}
		}
		return $best;
	}

	/**
	 * 1.5.5 — DTSTART overrides working hours. When a task has a DTSTART that
	 * falls OUTSIDE its working-hours window (e.g. 18:00 with hours 09-17), the
	 * slot is allowed to start there: synthesize a per-task window from DTSTART
	 * to the end of DTSTART's day (busy subtracted) and let it compete with the
	 * shared working-hours pool. A PAST DTSTART yields no extra window (the shared
	 * pool already rolls it forward to the next working window, never into the
	 * past); a DTSTART INSIDE working hours yields none either (the shared pool
	 * already covers it, clamped to DTSTART). Never places before $now.
	 *
	 * @param BusyBlock[]    $busy
	 * @return Slot[]
	 */
	private function dtstartSlots(Task $task, \DateTimeImmutable $now, array $busy, WorkingHours $working): array {
		if ($task->start === null) {
			return [];
		}
		// Compare in the user's TZ (the same TZ the shared working-hours pool is
		// computed in) so a UTC/floating DTSTART is judged against the user's local
		// working window, not its own TZ (plan 1.5.5).
		$dtstart = $task->start->setTimezone($now->getTimezone());
		if ($dtstart < $now) {
			return []; // past DTSTART: no relaxation; shared slots roll forward
		}
		$day = $dtstart->setTime(0, 0, 0);
		$wd = $working->forWeekday((int)$day->format('N') - 1);
		$insideWorking = $wd !== null
			&& $dtstart >= $day->modify('+' . $wd->startMinutes . ' minutes')
			&& $dtstart < $day->modify('+' . $wd->endMinutes . ' minutes');
		if ($insideWorking) {
			return []; // shared working-hours pool already covers it
		}
		$start = FreeSlotsService::snapUp($dtstart);
		$dayEnd = $start->setTime(23, 59, 0);
		if ($start >= $dayEnd) {
			return [];
		}
		$out = [];
		foreach (FreeSlotsService::subtractBusy(new Slot($start, $dayEnd), $busy) as $s) {
			$snapped = new Slot($s->start, FreeSlotsService::snapDown($s->end));
			if ($snapped->start < $snapped->end && $snapped->durationMinutes() >= $task->estimatedMinutes) {
				$out[] = $snapped;
			}
		}
		return $out;
	}

	/**
	 * Place *task* in the earliest fitting slot; consume it. Tries to honour the
	 * due date first (slot must end <= DUE). If that is impossible:
	 *  - when DUE is in the future -> DUE supersedes calplan: SKIP (return null),
	 *    so no slot is ever written that overruns a reachable due date;
	 *  - when DUE has already passed (overdue) or is absent -> roll over to the
	 *    earliest fitting slot regardless of DUE, placing the
	 *    overdue task ASAP — never in the past (free slots start at $now).
	 * 1.5.5: a DTSTART outside working hours relaxes working hours for this one
	 * task (dtstartSlots competes with the shared pool); the earliest valid fit
	 * across both wins. Returns the placed Slot, or null if nothing fits.
	 *
	 * @param Slot[]            $slots
	 * @param array<string,int> $dailyUsed
	 * @param BusyBlock[]       $busy
	 */
	private function place(Task $task, array &$slots, array &$dailyUsed, int $dailyCap, \DateTimeImmutable $now, array $busy, WorkingHours $working, int $taskGapMinutes = 0, array &$dailyCountUsed = [], int $dailyTaskCount = 0, ?bool &$countRejected = null): ?Slot {
		$extra = $this->dtstartSlots($task, $now, $busy, $working);
		$best = $this->earliestSource($slots, $extra, $task, $dailyUsed, $dailyCap, true, $dailyCountUsed, $dailyTaskCount, $countRejected);
		if ($best === null) {
			$overdueOrNoDue = $task->due === null || $task->due <= $now;
			if ($overdueOrNoDue) {
				$best = $this->earliestSource($slots, $extra, $task, $dailyUsed, $dailyCap, false, $dailyCountUsed, $dailyTaskCount, $countRejected);
			}
		}
		if ($best === null) {
			return null;
		}

		$usableStart = $best['start'];
		$placedEnd = $best['end'];
		$placed = new Slot($usableStart, $placedEnd);

		$dayKey = $usableStart->setTime(0, 0, 0)->format('Y-m-d');
		$dailyUsed[$dayKey] = ($dailyUsed[$dayKey] ?? 0) + $task->estimatedMinutes;
		$dailyCountUsed[$dayKey] = ($dailyCountUsed[$dayKey] ?? 0) + 1;

		// Consume from the shared pool only; DTSTART slots are per-task throwaway.
		if ($best['shared']) {
			$src = $slots[$best['i']];
			array_splice($slots, (int)$best['i'], 1);
			$previousAvailable = $taskGapMinutes > 0 ? $usableStart->modify('-' . $taskGapMinutes . ' minutes') : $usableStart;
			if ($previousAvailable > $src->start) {
				$prefix = new Slot($src->start, $previousAvailable);
				if ($prefix->durationMinutes() >= Defaults::SLOT_GRANULARITY_MINUTES) {
					$this->insertSorted($slots, $prefix);
				}
			}
			$nextAvailable = $taskGapMinutes > 0 ? $placedEnd->modify('+' . $taskGapMinutes . ' minutes') : $placedEnd;
			if ($src->end > $nextAvailable) {
				$suffix = new Slot($nextAvailable, $src->end);
				if ($suffix->durationMinutes() >= Defaults::SLOT_GRANULARITY_MINUTES) {
					$this->insertSorted($slots, $suffix);
				}
			}
		}
		return $placed;
	}

	/**
	 * Compute detailed placement outcomes, including a stable reason when a task
	 * cannot be placed. The compatibility schedule() method below unwraps slots.
	 *
	 * @param Task[]      $tasks
	 * @param BusyBlock[] $busy
	 * @return array<string, PlacementResult>
	 */
	public function scheduleDetailed(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0, array &$dailyCountUsed = []): array {
		$candidates = $this->eligible($tasks);
		// Missed-deadline tasks first, then weighted-EDF by score desc; SPT tiebreak
		// (shorter jobs first) by estimate asc. A missed DUE (overdue) outranks a
		// missed DTSTART, which outranks every on-track task (plan 1.6.3).
		usort($candidates, function (Task $a, Task $b) use ($now): int {
			$byTier = $this->urgencyTier($a, $now) <=> $this->urgencyTier($b, $now);
			if ($byTier !== 0) {
				return $byTier;
			}
			$byScore = $this->rankingScore($b, $now) <=> $this->rankingScore($a, $now);
			return $byScore !== 0 ? $byScore : ($a->estimatedMinutes <=> $b->estimatedMinutes);
		});

		$minEst = Defaults::DEFAULT_ESTIMATE_MINUTES;
		foreach ($candidates as $t) {
			$minEst = min($minEst, $t->estimatedMinutes);
		}
		$slots = FreeSlotsService::freeSlots($working, $busy, $now, $horizonDays, $minEst);

		$dailyUsed = [];
		$outcomes = [];
		foreach ($candidates as $task) {
			$countRejected = false;
			$slot = $this->place($task, $slots, $dailyUsed, $dailyCap, $now, $busy, $working, $taskGapMinutes, $dailyCountUsed, $dailyTaskCount, $countRejected);
			$reason = $slot === null ? ($countRejected ? 'daily_task_count_reached' : $this->unscheduledReason($task, $working, $now, $horizonDays, $dailyCap)) : null;
			$outcomes[$task->id] = new PlacementResult($slot, $reason);
		}
		return $outcomes;
	}

	/** @return array<string, Slot|null> */
	public function schedule(array $tasks, WorkingHours $working, array $busy, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, int $taskGapMinutes = 0, int $dailyTaskCount = 0): array {
		$out = [];
		$dailyCountUsed = [];
		foreach ($this->scheduleDetailed($tasks, $working, $busy, $now, $horizonDays, $dailyCap, $taskGapMinutes, $dailyTaskCount, $dailyCountUsed) as $taskId => $result) {
			$out[$taskId] = $result->slot;
		}
		return $out;
	}

	private function unscheduledReason(Task $task, WorkingHours $working, \DateTimeImmutable $now, int $horizonDays, int $dailyCap): string {
		$horizonEnd = $now->setTime(0, 0)->modify('+' . $horizonDays . ' days');
		if ($task->start !== null && $task->start >= $horizonEnd) {
			return 'starts_after_horizon';
		}
		$maxWindow = 0;
		for ($weekday = 0; $weekday < 7; $weekday++) {
			$day = $working->forWeekday($weekday);
			if ($day !== null) {
				$maxWindow = max($maxWindow, $day->endMinutes - $day->startMinutes);
			}
		}
		if ($maxWindow === 0) {
			return 'no_working_hours';
		}
		if ($task->estimatedMinutes > $dailyCap) {
			return 'duration_exceeds_daily_cap';
		}
		if ($task->estimatedMinutes > $maxWindow && $task->start === null) {
			return 'duration_exceeds_openings';
		}
		if ($task->due !== null && $task->due > $now) {
			return 'no_fit_before_due';
		}
		return 'no_capacity_within_horizon';
	}
}
