<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Service\SchedulerService;

class SchedulerServiceTest extends AutoScheduleTestCase {
	private SchedulerService $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduler = new SchedulerService();
	}

	public function testSchedulePlacesHigherPriorityDueSoonerFirst(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$hi = $this->task('A', 'High', priority: 1, due: $this->utc('2026-08-20 17:00:00'), estimatedMinutes: 30);
		$lo = $this->task('B', 'Low', priority: 5, due: $this->utc('2026-08-29 17:00:00'), estimatedMinutes: 30);

		$placed = $this->scheduler->schedule([$hi, $lo], $this->allWeek(), [], $now);

		$this->assertSame('2026-08-19 09:00:00', $placed['A']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 09:30:00', $placed['B']->start->format('Y-m-d H:i:s'));
	}

	public function testScheduleRespectsDueDateWhenItFits(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// Due at 10:00 today, 60-min task → fits 09:00–10:00 (end == due, OK).
		$t = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-19 10:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-19 09:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:00:00', $placed['T']->end->format('Y-m-d H:i:s'));
	}

	public function testScheduleSkipsWhenFutureDueTooSoonToFit(): void {
		// DUE supersedes calplan: DUE 09:15 is in the FUTURE but too soon for a
		// 60-min task (working window opens 09:00) → must NOT overrun DUE, so the
		// task is skipped (unschedulable) and no slot is written.
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-19 09:15:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertArrayHasKey('T', $placed);
		$this->assertNull($placed['T']);
	}

	public function testOverdueTaskRankedTopAndScheduledAtEarliest(): void {
		// A task whose DUE has passed is overdue: days-until-due clamps to 0 →
		// top priority (highest weighted-EDF score), so it wins the earliest
		// available slot — never skipped, never in the past.
		$now = $this->utc('2026-08-19 08:00:00');
		$overdue = $this->task('O', estimatedMinutes: 30, priority: 5, due: $this->utc('2026-08-18 17:00:00'));
		$normal = $this->task('N', estimatedMinutes: 30, priority: 5, due: $this->utc('2026-08-29 17:00:00'));
		$placed = $this->scheduler->schedule([$overdue, $normal], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['O']);
		$this->assertSame('2026-08-19 09:00:00', $placed['O']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 09:30:00', $placed['N']->start->format('Y-m-d H:i:s'));
	}

	public function testOverdueTaskNeverSkippedEvenWhenDueWellBeforeNow(): void {
		// Overdue tasks always roll forward (never skipped) — the hard-DUE
		// ceiling only applies to FUTURE due dates.
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-15 09:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertGreaterThanOrEqual($now->format('Y-m-d H:i:s'), $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testScheduleNeverPlacesBeforeDtstart(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// Start is *tomorrow* 09:00 → must NOT be placed today.
		$t = $this->task('T', estimatedMinutes: 60, start: $this->utc('2026-08-20 09:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-20 09:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-20 10:00:00', $placed['T']->end->format('Y-m-d H:i:s'));
	}

	public function testSchedulePlacesTodayWhenStartAlreadyPassed(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// Start was yesterday → rolls past start, placed at today's opening.
		$t = $this->task('T', estimatedMinutes: 60, start: $this->utc('2026-08-18 09:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-19 09:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testPastDtstartMidWindowRollsForwardToNow(): void {
		// DTSTART passed AND we are already mid-window (now 10:30) → the slot
		// must roll forward to now, never into the past. Free slots are clamped
		// to $now, so the earliest placement starts exactly at now.
		$now = $this->utc('2026-08-19 10:30:00');
		$t = $this->task('T', estimatedMinutes: 30, start: $this->utc('2026-08-18 09:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-19 10:30:00', $placed['T']->start->format('Y-m-d H:i:s'));
		// Explicit no-past-placement invariant: slotStart ≥ now AND ≥ DTSTART.
		$this->assertGreaterThanOrEqual($now->format('Y-m-d H:i:s'), $placed['T']->start->format('Y-m-d H:i:s'));
		$this->assertGreaterThanOrEqual('2026-08-18 09:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testDtstartClampHandsBackUnusedPrefix(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// A: may start today 09:00. B: may only start at 11:00 → must not steal the
		// 09:00–11:00 window from A; A still gets 09:00.
		$a = $this->task('A', estimatedMinutes: 30, start: $this->utc('2026-08-19 09:00:00'));
		$b = $this->task('B', estimatedMinutes: 30, start: $this->utc('2026-08-19 11:00:00'));
		$placed = $this->scheduler->schedule([$a, $b], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-19 09:00:00', $placed['A']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 11:00:00', $placed['B']->start->format('Y-m-d H:i:s'));
	}

	public function testDailyCapSpillsTasksToNextDay(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [
			$this->task('A', estimatedMinutes: 30, due: $this->utc('2026-08-29 17:00:00')),
			$this->task('B', estimatedMinutes: 30, due: $this->utc('2026-08-29 17:00:00')),
			$this->task('C', estimatedMinutes: 30, due: $this->utc('2026-08-29 17:00:00')),
		];
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, 14, 60);
		$this->assertSame('2026-08-19 09:00:00', $placed['A']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 09:30:00', $placed['B']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-20 09:00:00', $placed['C']->start->format('Y-m-d H:i:s'));
	}

	public function testDoneAndUnflaggedTasksAreExcluded(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$ok = $this->task('OK', estimatedMinutes: 30);
		$done = $this->task('DONE', estimatedMinutes: 30, autoSchedule: true, status: 'COMPLETED');
		$unflagged = $this->task('UNFLAG', estimatedMinutes: 30, autoSchedule: false);
		$placed = $this->scheduler->schedule([$ok, $done, $unflagged], $this->allWeek(), [], $now);
		$this->assertArrayHasKey('OK', $placed);
		$this->assertArrayNotHasKey('DONE', $placed);
		$this->assertArrayNotHasKey('UNFLAG', $placed);
	}

	public function testTasksWithTheSameEveningStartDoNotOverlap(): void {
		$now = $this->utc('2026-08-19 12:00:00');
		$a = new Task(id: 'A', title: 'A', start: $this->utc('2026-08-19 18:00:00'), estimatedMinutes: 60, autoSchedule: true);
		$b = new Task(id: 'B', title: 'B', start: $this->utc('2026-08-19 18:00:00'), estimatedMinutes: 60, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$a, $b], $this->allWeek(), [], $now);
		$this->assertSame(['08-19 18:00-19:00'], $this->blocksOf($outcomes, 'A'));
		$this->assertSame(['08-19 19:00-20:00'], $this->blocksOf($outcomes, 'B'));
	}

	public function testABlockInADtstartWindowLeavesTheWorkingHoursFree(): void {
		$now = $this->utc('2026-08-19 06:00:00');
		// 07:00-10:00 reaches into working hours: the next task must start at 10:00.
		$early = new Task(id: 'EARLY', title: 'Early', start: $this->utc('2026-08-19 07:00:00'), estimatedMinutes: 180, autoSchedule: true, priority: 1);
		$later = new Task(id: 'LATER', title: 'Later', estimatedMinutes: 60, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$early, $later], $this->allWeek(), [], $now);
		$this->assertSame(['08-19 07:00-10:00'], $this->blocksOf($outcomes, 'EARLY'));
		$this->assertSame(['08-19 10:00-11:00'], $this->blocksOf($outcomes, 'LATER'));
	}

	public function testAnAllDayStartIsFromThatDayNotANightBlock(): void {
		$rome = new \DateTimeZone('Europe/Rome');
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', $rome);
		// DTSTART;VALUE=DATE:20260820 as parsed in the user's timezone: Rome's midnight.
		$task = new Task(id: 'DAY', title: 'Day', start: new \DateTimeImmutable('2026-08-20 00:00:00', $rome), estimatedMinutes: 60, autoSchedule: true, startAllDay: true);
		$outcomes = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-20 09:00', $outcomes['DAY']->slot->start->setTimezone($rome)->format('Y-m-d H:i'));
	}

	public function testDtstartInUtcCountsAgainstTheUsersDay(): void {
		$rome = new \DateTimeZone('Europe/Rome');
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', $rome);
		$first = new Task(id: 'T1', title: 'First', estimatedMinutes: 60, autoSchedule: true);
		// 22:30 UTC on the 19th is 00:30 on the 20th in Rome: the 20th's budget, not the 19th's.
		$night = new Task(id: 'T2', title: 'Night', start: new \DateTimeImmutable('2026-08-19 22:30:00', new \DateTimeZone('UTC')), estimatedMinutes: 60, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$first, $night], $this->allWeek(), [], $now, dailyCap: 60);
		$this->assertSame('2026-08-19 09:00', $outcomes['T1']->slot->start->setTimezone($rome)->format('Y-m-d H:i'));
		$this->assertSame('2026-08-20 00:30', $outcomes['T2']->slot->start->setTimezone($rome)->format('Y-m-d H:i'));
	}

	/** @return string[] "MM-DD HH:MM-HH:MM" of the block of *task*, or none */
	private function blocksOf(array $outcomes, string $id): array {
		$slot = $outcomes[$id]->slot;
		return $slot !== null ? [$slot->start->format('m-d H:i') . '-' . $slot->end->format('H:i')] : [];
	}

	public function testNoDueDateIsStillPlaced(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, due: null);
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T'] ?? null);
	}

	public function testDtstartOutsideWorkingHoursStartsAtDtstart(): void {
		// 1.5.5: DTSTART 18:00 is outside working hours (09-17) -> working hours
		// relax for this task; the slot starts at 18:00, NOT rolled to tomorrow 09:00.
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, start: $this->utc('2026-08-19 18:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-19 18:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 18:30:00', $placed['T']->end->format('Y-m-d H:i:s'));
	}

	public function testDtstartInsideWorkingHoursUnchanged(): void {
		// DTSTART 10:00 inside working hours -> no relaxation; clamps within the
		// working window as before.
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, start: $this->utc('2026-08-19 10:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-19 10:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testDtstartOutsideWorkingHoursRespectsBusy(): void {
		// DTSTART 18:00 but busy 18:00-19:00 -> slot starts at 19:00 (busy still
		// respected even when working hours relax).
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, start: $this->utc('2026-08-19 18:00:00'));
		$busy = [new BusyBlock($this->utc('2026-08-19 18:00:00'), $this->utc('2026-08-19 19:00:00'))];
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), $busy, $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-19 19:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testDtstartOutsideWorkingHoursAcrossTimezones(): void {
		// 1.5.5 + TZ: a UTC DTSTART outside working hours in the user's TZ
		// (America/Vancouver 09-17) but inside them in UTC must still relax working
		// hours. 09:00 UTC = 02:00 Vancouver (before 09:00) -> slot starts at 02:00
		// Vancouver, not rolled to the 09:00 working window.
		$van = new \DateTimeZone('America/Vancouver');
		$now = new \DateTimeImmutable('2026-08-20 08:00:00', $van);
		$start = new \DateTimeImmutable('2026-08-21 09:00:00', new \DateTimeZone('UTC')); // 02:00 Vancouver
		$t = $this->task('T', estimatedMinutes: 30, start: $start);
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-21 02:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
	}

	public function testFutureDueLandsInEarliestFreeWindowNotAtDue(): void {
		// 1.5.6 invariant: a future DUE is a hard CEILING, not a target. A 30-min
		// task due 2026-08-25 17:00 with the whole week free lands at the EARLIEST
		// free window (today 09:00), not at the due date.
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, due: $this->utc('2026-08-25 17:00:00'));
		$placed = $this->scheduler->schedule([$t], $this->allWeek(), [], $now);
		$this->assertNotNull($placed['T']);
		$this->assertSame('2026-08-19 09:00:00', $placed['T']->start->format('Y-m-d H:i:s'));
		$this->assertLessThan('2026-08-25 17:00:00', $placed['T']->end->format('Y-m-d H:i:s'));
	}

	public function testDailyCapCanBeConfigured(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [
			$this->task('A', estimatedMinutes: 60),
			$this->task('B', estimatedMinutes: 60),
		];
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, dailyCap: 60);
		$this->assertSame('2026-08-19', $placed['A']->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $placed['B']->start->format('Y-m-d'));
	}

	public function testDailyTaskCountUnlimitedPreservesSameDayPlacement(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [$this->task('A', estimatedMinutes: 30), $this->task('B', estimatedMinutes: 30)];
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, dailyTaskCount: 0);
		$this->assertSame('2026-08-19', $placed['A']->start->format('Y-m-d'));
		$this->assertSame('2026-08-19', $placed['B']->start->format('Y-m-d'));
	}

	public function testDailyTaskCountRollsExcessTasksToNextLocalDay(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [
			$this->task('A', estimatedMinutes: 30),
			$this->task('B', estimatedMinutes: 30),
			$this->task('C', estimatedMinutes: 30),
		];
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, dailyTaskCount: 2);
		$this->assertSame('2026-08-19', $placed['A']->start->format('Y-m-d'));
		$this->assertSame('2026-08-19', $placed['B']->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $placed['C']->start->format('Y-m-d'));
	}

	public function testDailyMinuteAndTaskCountCapsBothRemainHard(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [$this->task('A', estimatedMinutes: 60), $this->task('B', estimatedMinutes: 60)];
		$byMinutes = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, dailyCap: 60, dailyTaskCount: 10);
		$byCount = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, dailyCap: 300, dailyTaskCount: 1);
		$this->assertSame('2026-08-20', $byMinutes['B']->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $byCount['B']->start->format('Y-m-d'));
	}

	public function testDetailedScheduleExplainsDailyTaskCountCapacity(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$due = $this->utc('2026-08-19 17:00:00');
		$tasks = [$this->task('A', due: $due), $this->task('B', due: $due)];
		$result = $this->scheduler->scheduleDetailed($tasks, $this->allWeek(), [], $now, dailyTaskCount: 1);
		$this->assertNotNull($result['A']->slot);
		$this->assertNull($result['B']->slot);
		$this->assertSame('daily_task_count_reached', $result['B']->reason);
	}

	public function testTaskGapIsReservedBetweenPlacedTasks(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [
			$this->task('A', estimatedMinutes: 30),
			$this->task('B', estimatedMinutes: 30),
		];
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), [], $now, taskGapMinutes: 30);
		$this->assertSame('2026-08-19 09:00:00', $placed['A']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:00:00', $placed['B']->start->format('Y-m-d H:i:s'));
	}

	public function testDetailedScheduleExplainsFutureStartBeyondHorizon(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', start: $this->utc('2026-09-10 09:00:00'));
		$result = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now);
		$this->assertNull($result['T']->slot);
		$this->assertSame('starts_after_horizon', $result['T']->reason);
	}

	public function testDetailedScheduleExplainsDurationAboveDailyCap(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 90);
		$result = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now, dailyCap: 60);
		$this->assertNull($result['T']->slot);
		$this->assertSame('duration_exceeds_daily_cap', $result['T']->reason);
	}

	public function testDetailedScheduleExplainsNoFitBeforeDue(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-19 09:15:00'));
		$result = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now);
		$this->assertNull($result['T']->slot);
		$this->assertSame('no_fit_before_due', $result['T']->reason);
	}

	public function testMissedDtstartOutranksOnTrackButLessThanMissedDue(): void {
		// 1.6.3: a task whose DTSTART has passed (but DUE is still future) is
		// 'missed start' -- overdue-ish, scheduled before every on-track task,
		// but AFTER a task whose DUE has already passed (tier 0 > tier 1 > tier 2).
		$now = $this->utc('2026-08-19 08:00:00');
		$missedDue = $this->task('D', estimatedMinutes: 30, priority: 5, due: $this->utc('2026-08-18 17:00:00'));
		$missedStart = $this->task('S', estimatedMinutes: 30, priority: 5, start: $this->utc('2026-08-18 09:00:00'), due: $this->utc('2026-08-29 17:00:00'));
		$onTrack = $this->task('N', estimatedMinutes: 30, priority: 5, due: $this->utc('2026-08-20 09:00:00'));
		$placed = $this->scheduler->schedule([$onTrack, $missedStart, $missedDue], $this->allWeek(), [], $now);
		$this->assertSame('2026-08-19 09:00:00', $placed['D']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 09:30:00', $placed['S']->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:00:00', $placed['N']->start->format('Y-m-d H:i:s'));
	}

	/** Every day from *start* to *end* (minutes after midnight). */
	private function hours(int $start, int $end, int $days = 7): \OCA\AutoSchedule\Model\WorkingHours {
		$out = [];
		for ($w = 0; $w < $days; $w++) {
			$out[] = new \OCA\AutoSchedule\Model\WorkingDay($w, $start, $end);
		}
		return new \OCA\AutoSchedule\Model\WorkingHours($out);
	}

	public function testTheDailyCapHoldsWhenADtstartIsInUtc(): void {
		$ny = new \DateTimeZone('America/New_York');
		$now = new \DateTimeImmutable('2026-08-19 17:00:00', $ny);
		// Tasks writes timed DTSTARTs in UTC: 22:15Z is 18:15 in New York.
		$a = new Task(id: 'A', title: 'A', start: $this->utc('2026-08-19 22:15:00'), estimatedMinutes: 60, autoSchedule: true);
		$b = new Task(id: 'B', title: 'B', estimatedMinutes: 60, autoSchedule: true);
		$c = new Task(id: 'C', title: 'C', estimatedMinutes: 60, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$a, $b, $c], $this->hours(18 * 60, 23 * 60 + 45), [], $now, dailyCap: 120, dailyTaskCount: 2);
		$this->assertSame('2026-08-19 18:15', $outcomes['A']->slot->start->setTimezone($ny)->format('Y-m-d H:i'));
		$this->assertSame('2026-08-19 19:15', $outcomes['B']->slot->start->setTimezone($ny)->format('Y-m-d H:i'));
		$this->assertSame('2026-08-20 18:00', $outcomes['C']->slot->start->setTimezone($ny)->format('Y-m-d H:i'));
	}

	public function testADtstartBeyondThePlanningWindowGetsNoBlock(): void {
		$now = $this->utc('2026-10-20 08:00:00');
		$task = new Task(id: 'FAR', title: 'Far', start: $this->utc('2027-03-10 19:00:00'), estimatedMinutes: 60, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now);
		$this->assertNull($outcomes['FAR']->slot);
		$this->assertSame('starts_after_horizon', $outcomes['FAR']->reason);
	}

	public function testAnEveningDtstartJustPassedKeepsTheEvening(): void {
		$now = $this->utc('2026-08-19 18:05:00');
		$task = new Task(id: 'EVE', title: 'Evening', start: $this->utc('2026-08-19 18:00:00'), estimatedMinutes: 60, autoSchedule: true);
		$this->assertSame(['08-19 18:15-19:15'], $this->blocksOf($this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now), 'EVE'));
	}

	public function testLowPriorityComesAfterMedium(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$due = $this->utc('2026-08-21 17:00:00');
		$placed = $this->scheduler->schedule([
			$this->task('LOW', priority: 9, due: $due), $this->task('MED', priority: 5, due: $due), $this->task('HIGH', priority: 2, due: $due),
		], $this->allWeek(), [], $now);
		$this->assertSame('09:00', $placed['HIGH']->start->format('H:i'));
		$this->assertSame('09:30', $placed['MED']->start->format('H:i'));
		$this->assertSame('10:00', $placed['LOW']->start->format('H:i'));
	}

	public function testDaysUntilDueCountsTheUsersCalendarDays(): void {
		$la = new \DateTimeZone('America/Los_Angeles');
		$now = new \DateTimeImmutable('2026-10-19 10:00:00', $la);
		// Tomorrow 16:00 in Los Angeles, stored in UTC: 23:00Z.
		$task = new Task(id: 'X', title: 'X', due: $this->utc('2026-10-20 23:00:00'));
		$this->assertSame(1.0, $this->scheduler->daysUntilDue($task, $now));
		// Across the spring DST change: two calendar days.
		$rome = new \DateTimeZone('Europe/Rome');
		$task = new Task(id: 'Y', title: 'Y', due: new \DateTimeImmutable('2027-03-29 23:00:00', $rome));
		$this->assertSame(2.0, $this->scheduler->daysUntilDue($task, new \DateTimeImmutable('2027-03-27 01:00:00', $rome)));
	}

	public function testADtstartOnAnUncheckedDayOpensNoWindow(): void {
		$now = $this->utc('2026-10-16 10:00:00'); // a Friday
		$task = new Task(id: 'SUN', title: 'Sunday', start: $this->utc('2026-10-18 10:00:00'), estimatedMinutes: 60, autoSchedule: true);
		$this->assertSame(['10-19 09:00-10:00'], $this->blocksOf($this->scheduler->scheduleDetailed([$task], $this->monFri(), [], $now), 'SUN'));
	}

	public function testADtstartJustBeforeMidnightOpensNoWindowTheNextDay(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = new Task(id: 'LATE', title: 'Late', start: $this->utc('2026-08-21 23:50:00'), estimatedMinutes: 30, autoSchedule: true);
		$this->assertSame(['08-22 09:00-09:30'], $this->blocksOf($this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now), 'LATE'));
	}

	public function testADtstartTooCloseToTheEndOfTheHoursUsesTheEvening(): void {
		$now = $this->utc('2026-08-19 12:00:00');
		$task = new Task(id: 'T', title: 'T', start: $this->utc('2026-08-19 16:30:00'), estimatedMinutes: 60, autoSchedule: true);
		$this->assertSame(['08-19 16:30-17:30'], $this->blocksOf($this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now), 'T'));
	}

	public function testShortBlocksFollowEachOtherWithoutGaps(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = [$this->task('A', estimatedMinutes: 1), $this->task('B', estimatedMinutes: 2), $this->task('C', estimatedMinutes: 7)];
		$outcomes = $this->scheduler->scheduleDetailed($tasks, $this->allWeek(), [], $now);
		$this->assertSame(['08-19 09:00-09:01'], $this->blocksOf($outcomes, 'A'));
		$this->assertSame(['08-19 09:01-09:03'], $this->blocksOf($outcomes, 'B'));
		$this->assertSame(['08-19 09:03-09:10'], $this->blocksOf($outcomes, 'C'));
	}

	public function testAnOffGridDtstartStartsOnTheGrid(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$o = new Task(id: 'O', title: 'O', start: $this->utc('2026-08-19 10:07:00'), estimatedMinutes: 30, autoSchedule: true);
		$p = new Task(id: 'P', title: 'P', start: $this->utc('2026-08-19 10:00:00'), estimatedMinutes: 30, autoSchedule: true);
		$outcomes = $this->scheduler->scheduleDetailed([$o, $p], $this->allWeek(), [], $now);
		$this->assertSame(['08-19 10:15-10:45'], $this->blocksOf($outcomes, 'O'));
		$this->assertSame(['08-19 10:45-11:15'], $this->blocksOf($outcomes, 'P'));
	}

	public function testABlockAcrossTheDstChangeKeepsItsLength(): void {
		$rome = new \DateTimeZone('Europe/Rome');
		$now = new \DateTimeImmutable('2026-10-24 12:00:00', $rome);
		$task = new Task(id: 'N', title: 'Night', start: new \DateTimeImmutable('2026-10-25 01:30:00', $rome), estimatedMinutes: 90, autoSchedule: true);
		$slot = $this->scheduler->scheduleDetailed([$task], $this->allWeek(), [], $now)['N']->slot;
		$this->assertSame(90 * 60, $slot->end->getTimestamp() - $slot->start->getTimestamp());
	}

	public function testAnUndatedTaskComesAfterOneDueInAMonth(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$placed = $this->scheduler->schedule([
			$this->task('UNDATED'), $this->task('DATED', due: $this->utc('2026-09-18 17:00:00')),
		], $this->allWeek(), [], $now, 90, 30);
		$this->assertSame('2026-08-19', $placed['DATED']->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $placed['UNDATED']->start->format('Y-m-d'));
	}

	public function testStartedBlocksHoldTheirTimeAndCount(): void {
		$now = $this->utc('2026-08-19 09:20:00');
		$pinned = ['A' => new \OCA\AutoSchedule\Model\Slot($this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'))];
		$outcomes = $this->scheduler->scheduleDetailed([$this->task('A', estimatedMinutes: 60), $this->task('B', estimatedMinutes: 60)], $this->allWeek(), [], $now,
			dailyTaskCount: 1, pinned: $pinned);
		$this->assertSame(['08-19 09:00-10:00'], $this->blocksOf($outcomes, 'A'));
		$this->assertSame(['08-20 09:00-10:00'], $this->blocksOf($outcomes, 'B'));
	}
}
