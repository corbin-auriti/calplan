<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingHours;
use OCA\AutoSchedule\Service\Reconciler;
use OCA\AutoSchedule\Service\SchedulerService;

class ReconcilerTest extends AutoScheduleTestCase {
	private Reconciler $reconciler;

	protected function setUp(): void {
		parent::setUp();
		$this->reconciler = new Reconciler(new SchedulerService());
	}

	/**
	 * One calendar planned alone: *events* are its own, *extraBusyEvents*
	 * other calendars' busy time.
	 *
	 * @param Task[] $tasks
	 * @param CalendarEvent[] $events
	 * @param CalendarEvent[] $extraBusyEvents
	 * @param array<string, true> $released
	 */
	private function reconcile(array $tasks, WorkingHours $working, array $events, \DateTimeImmutable $now, int $eventBufferMinutes = 0, array $extraBusyEvents = [], array $released = [], int $dailyCap = 300, array $priorSpent = []): Reconciliation {
		return $this->reconciler->reconcileCalendars(
			['cal' => ['tasks' => $tasks, 'events' => $events]],
			$working,
			array_merge($events, $extraBusyEvents),
			$now,
			dailyCap: $dailyCap,
			eventBufferMinutes: $eventBufferMinutes,
			released: $released,
			priorSpent: $priorSpent,
		)['cal'];
	}

	public function testReconcilePutsSlotForNewFlaggedTask(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$recon = $this->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $recon->toPut);
		$this->assertEmpty($recon->toDelete);
		[$task, $slot] = $recon->toPut[0];
		$this->assertSame('T', $task->id);
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileIsIdempotentSecondRunHasNoChanges(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// Feed our own placed slot back in as an existing event.
		// Feed our own placed slot back, including the mirrored content a
		// live slot would carry (summary '🔒 T' — priority marks moved into
		// the DESCRIPTION in 0.9.2; structured DESCRIPTION, priority=5) so it
		// stays unchanged.
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5", null, null, 5)];
		$second = $this->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertFalse($second->hasChanges());
		$this->assertSame(['T'], $second->unchanged);
	}

	public function testCalendarsArePlannedTogetherByUrgency(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// A, listed first, holds an undated low-priority task; B an urgent one due at noon.
		$plans = [
			'a' => ['tasks' => [$this->task('LOW', priority: 9, estimatedMinutes: 60)], 'events' => []],
			'b' => ['tasks' => [$this->task('URGENT', priority: 1, due: $this->utc('2026-08-19 12:00:00'), estimatedMinutes: 60)], 'events' => []],
		];
		$recons = $this->reconciler->reconcileCalendars($plans, $this->allWeek(), [], $now, dailyCap: 60);
		$this->assertSame('2026-08-19 09:00:00', $recons['b']->toPut[0][1]->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-20 09:00:00', $recons['a']->toPut[0][1]->start->format('Y-m-d H:i:s'));
	}

	public function testRecoveryGapAndOwnBlocksSpanCalendars(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$first = $this->task('FIRST', due: $this->utc('2026-08-19 12:00:00'), estimatedMinutes: 60);
		$second = $this->task('SECOND', due: $this->utc('2026-08-19 16:00:00'), estimatedMinutes: 60);
		// B's stored block of SECOND sits at 09:00; it is re-derived, not busy.
		$stale = new CalendarEvent('calplan-SECOND', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), 'SECOND');
		$plans = [
			'a' => ['tasks' => [$first], 'events' => []],
			'b' => ['tasks' => [$second], 'events' => [$stale]],
		];
		$recons = $this->reconciler->reconcileCalendars($plans, $this->allWeek(), [$stale], $now, taskGapMinutes: 30);
		$this->assertSame('2026-08-19 09:00:00', $recons['a']->toPut[0][1]->start->format('Y-m-d H:i:s'));
		// 30-minute recovery after A's block, though it is another calendar's.
		$this->assertSame('2026-08-19 10:30:00', $recons['b']->toPut[0][1]->start->format('Y-m-d H:i:s'));
		$this->assertSame([], $recons['b']->toDelete);
	}

	public function testReconcileDeletesBlockWhenTaskFlagRemoved(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// A previously-placed slot exists, but no eligible task remains.
		$stale = new CalendarEvent('calplan-T', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 09:30:00'), 'T');
		$recon = $this->reconcile([], $this->allWeek(), [$stale], $now);
		$this->assertSame(['T'], $recon->toDelete);
		$this->assertEmpty($recon->toPut);
	}

	public function testReconcileCarriesUnscheduledReason(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-19 09:15:00'));
		$recon = $this->reconcile([$task], $this->allWeek(), [], $now);
		$this->assertSame(['T' => 'no_fit_before_due'], $recon->unscheduled);
	}

	public function testReconcileMovesBlockWhenNewBusyEventAppears(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// Existing real busy meeting 09:00–10:00.
		$meeting = new CalendarEvent('mtg-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconcile([$t], $this->allWeek(), [$meeting], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		// Moved to right after the meeting.
		$this->assertSame('2026-08-19 10:00:00', $slot->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:30:00', $slot->end->format('Y-m-d H:i:s'));
	}

	public function testReconcileIgnoresOurOwnBlocksWhenComputingBusy(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// Our own earlier slot must not be counted as busy.
		$own = new CalendarEvent('calplan-other', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 09:30:00'), 'other');
		$recon = $this->reconcile([$t], $this->allWeek(), [$own], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}
	public function testReconcileReputsSlotWhenNotesChange(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: 'old notes');
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// Existing slot carries the OLD notes — same time, same content → unchanged.
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5\n\n---Notes---\nold notes", null, null, 5)];
		$second = $this->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);
		$this->assertEmpty($second->toPut);

		// Same time, but the task notes changed → must re-PUT (plan 1.5.11).
		$t2 = $this->task('T', estimatedMinutes: 30, description: 'new notes');
		$third = $this->reconcile([$t2], $this->allWeek(), $existing, $now);
		$this->assertCount(1, $third->toPut);
		$this->assertEmpty($third->unchanged);
	}

	public function testReconcileReputsSlotWhenPriorityChanges(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, priority: 1);
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		[, $slot] = $first->toPut[0];
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 1", null, null, 1)];
		$second = $this->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);

		$t2 = $this->task('T', estimatedMinutes: 30, priority: 5);
		$third = $this->reconcile([$t2], $this->allWeek(), $existing, $now);
		$this->assertCount(1, $third->toPut);
	}

	public function testReconcileDeletesSlotAtOneHundredPercent(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: 'notes', percentComplete: 60);
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		[, $slot] = $first->toPut[0];
		// slotContent now writes a structured DESCRIPTION block (plan 0.9.2).
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nCompletion: 60%\nPriority: 5\n\n---Notes---\nnotes", null, null, 5)];
		$second = $this->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);

		// Some CalDAV clients write PERCENT-COMPLETE before/without STATUS.
		// 100% is terminal by itself and must remove the derived slot.
		$t2 = $this->task('T', estimatedMinutes: 30, description: 'notes', percentComplete: 100);
		$this->assertTrue($t2->isDone());
		$third = $this->reconcile([], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $third->toDelete);
		$this->assertEmpty($third->toPut);
	}

	public function testReconcileHonorsTransparentEventAsFree(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// A transparent event 09:00-10:00 must NOT block: slot lands at 09:00.
		$free = new CalendarEvent('free-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), transp: 'TRANSPARENT');
		$recon = $this->reconcile([$t], $this->allWeek(), [$free], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileTreatsOpaqueEventAsBusy(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// An opaque event 09:00-10:00 blocks: slot pushed to 10:00.
		$busy = new CalendarEvent('mtg-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), transp: 'OPAQUE');
		$recon = $this->reconcile([$t], $this->allWeek(), [$busy], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testEventBufferInflatesRealBusyTime(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 30);
		$meeting = new CalendarEvent('meeting', $this->utc('2026-08-19 09:30:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconcile([$task], $this->allWeek(), [$meeting], $now, eventBufferMinutes: 30);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:30:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileUsesCrossCalendarBusy(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// A busy block on ANOTHER calendar (extraBusyEvents) must be respected:
		// the slot is pushed past it, not placed over it (plan 1.5.1).
		$elsewhere = new CalendarEvent('other-cal-mtg', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconcile([$t], $this->allWeek(), [], $now, extraBusyEvents: [$elsewhere]);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:00:00', $slot->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:30:00', $slot->end->format('Y-m-d H:i:s'));
	}

	public function testDailyTaskCountSpansCalendars(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$recons = $this->reconciler->reconcileCalendars([
			'a' => ['tasks' => [$this->task('A', due: $this->utc('2026-08-25 12:00:00'))], 'events' => []],
			'b' => ['tasks' => [$this->task('B', due: $this->utc('2026-08-26 12:00:00'))], 'events' => []],
		], $this->allWeek(), [], $now, dailyTaskCount: 1);
		$this->assertSame('2026-08-19', $recons['a']->toPut[0][1]->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $recons['b']->toPut[0][1]->start->format('Y-m-d'));
	}

	public function testNumericTaskUidsWorkAsBlockKeys(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('4529016286593164873', estimatedMinutes: 30);
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];
		$existing = [new CalendarEvent('calplan-4529016286593164873', $slot->start, $slot->end, '4529016286593164873', '🔒 T', "---Task---\nTitle: T\nPriority: 5", null, null, 5)];
		$this->assertSame(['4529016286593164873'], $this->reconcile([$t], $this->allWeek(), $existing, $now)->unchanged);
		$gone = $this->reconcile([], $this->allWeek(), $existing, $now);
		$this->assertSame(['4529016286593164873'], $gone->toDelete);
		$this->assertIsString($gone->toDelete[0]);
	}

	public function testAStartedBlockStaysWhereItIs(): void {
		// 09:00-09:30 started; at 09:20 the free time begins at 09:30.
		$now = $this->utc('2026-08-19 09:20:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$started = new CalendarEvent('calplan-T', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 09:30:00'), 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5", null, null, 5);
		$recon = $this->reconcile([$t], $this->allWeek(), [$started], $now);
		$this->assertFalse($recon->hasChanges());
		$this->assertSame(['T'], $recon->unchanged);
		// Even once it has ended, until the review releases it.
		$later = $this->utc('2026-08-19 09:40:00');
		$this->assertSame(['T'], $this->reconcile([$t], $this->allWeek(), [$started], $later)->unchanged);
		$released = $this->reconcile([$t], $this->allWeek(), [$started], $later, released: ['T' => true]);
		$this->assertSame('2026-08-19 09:45:00', $released->toPut[0][1]->start->format('Y-m-d H:i:s'));
	}

	public function testAStartedBlockAnEventNowTakesIsPlannedAnew(): void {
		$now = $this->utc('2026-08-19 09:20:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$started = new CalendarEvent('calplan-T', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 09:30:00'), 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5", null, null, 5);
		// A meeting moved over the block from 09:10: the block did not happen.
		$meeting = new CalendarEvent('mtg', $this->utc('2026-08-19 09:10:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconcile([$t], $this->allWeek(), [$started], $now, extraBusyEvents: [$meeting]);
		$this->assertSame('2026-08-19 10:00:00', $recon->toPut[0][1]->start->format('Y-m-d H:i:s'));
		// Only its 10 minutes before the meeting were spent.
		$this->assertSame(10, $recon->spent[0]['minutes']);
		// A transparent event does not take it.
		$free = new CalendarEvent('free', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), transp: 'TRANSPARENT');
		$this->assertSame(['T'], $this->reconcile([$t], $this->allWeek(), [$started], $now, extraBusyEvents: [$free])->unchanged);
	}

	public function testTimeSpentTodayCountsAgainstTheDailyCap(): void {
		$now = $this->utc('2026-08-19 10:30:00');
		// DONE's block 09:00-10:00 leaves with its task: 60 minutes spent today.
		$done = new CalendarEvent('calplan-DONE', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), 'DONE');
		$recon = $this->reconcile([$this->task('A', estimatedMinutes: 60), $this->task('B', estimatedMinutes: 60)], $this->allWeek(), [$done], $now, dailyCap: 120);
		$this->assertSame(['DONE'], $recon->toDelete);
		$days = array_map(fn ($put) => $put[1]->start->format('Y-m-d'), $recon->toPut);
		sort($days);
		$this->assertSame(['2026-08-19', '2026-08-20'], $days);
		$this->assertSame([['task' => 'DONE', 'start' => $done->start->getTimestamp(), 'day' => '2026-08-19', 'minutes' => 60]], $recon->spent);
		// Remembered after the block is gone, it still counts.
		$again = $this->reconcile([$this->task('A', estimatedMinutes: 60), $this->task('B', estimatedMinutes: 60)], $this->allWeek(), [], $now, dailyCap: 120,
			priorSpent: [['task' => 'DONE', 'day' => '2026-08-19', 'minutes' => 60]]);
		$days = array_map(fn ($put) => $put[1]->start->format('Y-m-d'), $again->toPut);
		sort($days);
		$this->assertSame(['2026-08-19', '2026-08-20'], $days);
	}

	public function testABlockIsUpdatedWhereItIsStored(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$imported = new CalendarEvent('calplan-T', $this->utc('2026-08-21 09:00:00'), $this->utc('2026-08-21 09:30:00'), 'T', objectUri: '9f3c-imported.ics');
		$recon = $this->reconcile([$this->task('T')], $this->allWeek(), [$imported], $now);
		$this->assertSame(['T' => '9f3c-imported.ics'], $recon->objectUris);
		$this->assertCount(1, $recon->toPut);
	}
	public function testReconcileIdempotentWithNextAction(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: "Next: draft it\nnotes");
		$first = $this->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// The live slot DESCRIPTION carries the Next action line (promoted out of
		// the notes) + the Title line, and the notes without the Next: line.
		// Feeding this exact content back in stays unchanged -> reconcile is
		// idempotent even with the new lines (plan 0.9.6).
		$desc = "---Task---\nTitle: T\nPriority: 5\n👣 First step: draft it\n\n---Notes---\nnotes";
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', $desc, null, null, 5)];
		$second = $this->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertFalse($second->hasChanges());
		$this->assertSame(['T'], $second->unchanged);
	}
}