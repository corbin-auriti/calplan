<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Service\Reconciler;
use OCA\AutoSchedule\Service\SchedulerService;

class ReconcilerTest extends AutoScheduleTestCase {
	private Reconciler $reconciler;

	protected function setUp(): void {
		parent::setUp();
		$this->reconciler = new Reconciler(new SchedulerService());
	}

	public function testReconcilePutsSlotForNewFlaggedTask(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $recon->toPut);
		$this->assertEmpty($recon->toDelete);
		[$task, $slot] = $recon->toPut[0];
		$this->assertSame('T', $task->id);
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileIsIdempotentSecondRunHasNoChanges(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		$first = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// Feed our own placed slot back in as an existing event.
		// Feed our own placed slot back, including the mirrored content a
		// live slot would carry (summary '🔒 T' — priority marks moved into
		// the DESCRIPTION in 0.9.2; structured DESCRIPTION, priority=5) so it
		// stays unchanged.
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5", null, null, 5)];
		$second = $this->reconciler->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertFalse($second->hasChanges());
		$this->assertSame(['T'], $second->unchanged);
	}

	public function testReconcileDeletesBlockWhenTaskFlagRemoved(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		// A previously-placed slot exists, but no eligible task remains.
		$stale = new CalendarEvent('calplan-T', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 09:30:00'), 'T');
		$recon = $this->reconciler->reconcile([], $this->allWeek(), [$stale], $now);
		$this->assertSame(['T'], $recon->toDelete);
		$this->assertEmpty($recon->toPut);
	}

	public function testReconcileCarriesUnscheduledReason(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 60, due: $this->utc('2026-08-19 09:15:00'));
		$recon = $this->reconciler->reconcile([$task], $this->allWeek(), [], $now);
		$this->assertSame(['T' => 'no_fit_before_due'], $recon->unscheduled);
	}

	public function testReconcileMovesBlockWhenNewBusyEventAppears(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// Existing real busy meeting 09:00–10:00.
		$meeting = new CalendarEvent('mtg-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [$meeting], $now);
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
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [$own], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}
	public function testReconcileReputsSlotWhenNotesChange(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: 'old notes');
		$first = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// Existing slot carries the OLD notes — same time, same content → unchanged.
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 5\n\n---Notes---\nold notes", null, null, 5)];
		$second = $this->reconciler->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);
		$this->assertEmpty($second->toPut);

		// Same time, but the task notes changed → must re-PUT (plan 1.5.11).
		$t2 = $this->task('T', estimatedMinutes: 30, description: 'new notes');
		$third = $this->reconciler->reconcile([$t2], $this->allWeek(), $existing, $now);
		$this->assertCount(1, $third->toPut);
		$this->assertEmpty($third->unchanged);
	}

	public function testReconcileReputsSlotWhenPriorityChanges(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, priority: 1);
		$first = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		[, $slot] = $first->toPut[0];
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nPriority: 1", null, null, 1)];
		$second = $this->reconciler->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);

		$t2 = $this->task('T', estimatedMinutes: 30, priority: 5);
		$third = $this->reconciler->reconcile([$t2], $this->allWeek(), $existing, $now);
		$this->assertCount(1, $third->toPut);
	}

	public function testReconcileDeletesSlotAtOneHundredPercent(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: 'notes', percentComplete: 60);
		$first = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		[, $slot] = $first->toPut[0];
		// slotContent now writes a structured DESCRIPTION block (plan 0.9.2).
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', "---Task---\nTitle: T\nCompletion: 60%\nPriority: 5\n\n---Notes---\nnotes", null, null, 5)];
		$second = $this->reconciler->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $second->unchanged);

		// Some CalDAV clients write PERCENT-COMPLETE before/without STATUS.
		// 100% is terminal by itself and must remove the derived slot.
		$t2 = $this->task('T', estimatedMinutes: 30, description: 'notes', percentComplete: 100);
		$this->assertTrue($t2->isDone());
		$third = $this->reconciler->reconcile([], $this->allWeek(), $existing, $now);
		$this->assertSame(['T'], $third->toDelete);
		$this->assertEmpty($third->toPut);
	}

	public function testReconcileHonorsTransparentEventAsFree(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// A transparent event 09:00-10:00 must NOT block: slot lands at 09:00.
		$free = new CalendarEvent('free-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), transp: 'TRANSPARENT');
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [$free], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 09:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileTreatsOpaqueEventAsBusy(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// An opaque event 09:00-10:00 blocks: slot pushed to 10:00.
		$busy = new CalendarEvent('mtg-1', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'), transp: 'OPAQUE');
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [$busy], $now);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:00:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testEventBufferInflatesRealBusyTime(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$task = $this->task('T', estimatedMinutes: 30);
		$meeting = new CalendarEvent('meeting', $this->utc('2026-08-19 09:30:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconciler->reconcile([$task], $this->allWeek(), [$meeting], $now, eventBufferMinutes: 30);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:30:00', $slot->start->format('Y-m-d H:i:s'));
	}

	public function testReconcileUsesCrossCalendarBusy(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30);
		// A busy block on ANOTHER calendar (extraBusyEvents) must be respected:
		// the slot is pushed past it, not placed over it (plan 1.5.1).
		$elsewhere = new CalendarEvent('other-cal-mtg', $this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 10:00:00'));
		$recon = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now, extraBusyEvents: [$elsewhere]);
		$this->assertCount(1, $recon->toPut);
		[, $slot] = $recon->toPut[0];
		$this->assertSame('2026-08-19 10:00:00', $slot->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:30:00', $slot->end->format('Y-m-d H:i:s'));
	}

	public function testDailyTaskCountIsSharedAcrossCalendarReconciliations(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$dailyCountUsed = [];
		$first = $this->reconciler->reconcile(
			[$this->task('A')], $this->allWeek(), [], $now,
			dailyTaskCount: 1, dailyCountUsed: $dailyCountUsed,
		);
		$second = $this->reconciler->reconcile(
			[$this->task('B')], $this->allWeek(), [], $now,
			dailyTaskCount: 1, dailyCountUsed: $dailyCountUsed,
		);
		$this->assertSame('2026-08-19', $first->toPut[0][1]->start->format('Y-m-d'));
		$this->assertSame('2026-08-20', $second->toPut[0][1]->start->format('Y-m-d'));
	}
	public function testReconcileIdempotentWithNextAction(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$t = $this->task('T', estimatedMinutes: 30, description: "Next: draft it\nnotes");
		$first = $this->reconciler->reconcile([$t], $this->allWeek(), [], $now);
		$this->assertCount(1, $first->toPut);
		[, $slot] = $first->toPut[0];

		// The live slot DESCRIPTION carries the Next action line (promoted out of
		// the notes) + the Title line, and the notes without the Next: line.
		// Feeding this exact content back in stays unchanged -> reconcile is
		// idempotent even with the new lines (plan 0.9.6).
		$desc = "---Task---\nTitle: T\nPriority: 5\n👣 First step: draft it\n\n---Notes---\nnotes";
		$existing = [new CalendarEvent('calplan-T', $slot->start, $slot->end, 'T', '🔒 T', $desc, null, null, 5)];
		$second = $this->reconciler->reconcile([$t], $this->allWeek(), $existing, $now);
		$this->assertFalse($second->hasChanges());
		$this->assertSame(['T'], $second->unchanged);
	}
}