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
use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingHours;

class Reconciler {
	public function __construct(
		private SchedulerService $scheduler,
	) {
	}

	/** @param CalendarEvent[] $events @return array<string, CalendarEvent> our own placed blocks, keyed by task uid */
	private function existingPlacements(array $events): array {
		$out = [];
		foreach ($events as $e) {
			if ($e->autoScheduleTaskUid !== null) {
				$out[$e->autoScheduleTaskUid] = $e;
			}
		}
		return $out;
	}

	/** @param CalendarEvent[] $events @return BusyBlock[] real busy only -- our own slots are re-derived and TRANSPARENT events are free (plan 1.5.1) */
	private function realBusy(array $events): array {
		$busy = [];
		foreach ($events as $e) {
			if ($e->autoScheduleTaskUid !== null) {
				continue; // our own working-slot events are re-derived, not busy
			}
			if (($e->transp ?? null) === 'TRANSPARENT') {
				continue; // a transparent event does not block (plan 1.5.1)
			}
			$busy[] = $e->toBusy();
		}
		return $busy;
	}

	/**
	 * @param Task[]         $tasks
	 * @param CalendarEvent[] $events
	 */
	public function reconcile(array $tasks, WorkingHours $working, array $events, \DateTimeImmutable $now, int $horizonDays = Defaults::LOOKAHEAD_DAYS, int $dailyCap = Defaults::DEFAULT_DAILY_CAP_MINUTES, array $extraBusyEvents = [], int $taskGapMinutes = 0, int $eventBufferMinutes = 0, int $dailyTaskCount = 0, array &$dailyCountUsed = []): Reconciliation {
		// Busy = this calendar's real events + other calendars' real events (plan 1.5.1);
		// our own slots and TRANSPARENT events are dropped by realBusy().
		$busy = array_merge($this->realBusy($events), $this->realBusy($extraBusyEvents));
		if ($eventBufferMinutes > 0) {
			$busy = array_map(fn (BusyBlock $block) => new BusyBlock(
				$block->start->modify('-' . $eventBufferMinutes . ' minutes'),
				$block->end->modify('+' . $eventBufferMinutes . ' minutes'),
			), $busy);
		}
		$outcomes = $this->scheduler->scheduleDetailed($tasks, $working, $busy, $now, $horizonDays, $dailyCap, $taskGapMinutes, $dailyTaskCount, $dailyCountUsed);
		$desired = [];
		foreach ($outcomes as $taskId => $outcome) {
			$desired[$taskId] = $outcome->slot;
		}
		$existing = $this->existingPlacements($events);

		$byId = [];
		foreach ($tasks as $t) {
			$byId[$t->id] = $t;
		}

		$recon = new Reconciliation();
		foreach ($desired as $tid => $slot) {
			if ($slot === null) {
				$reason = $outcomes[$tid]->reason ?? 'no_capacity_within_horizon';
				$recon->unscheduled[$tid] = $reason;
				continue;
			}
			$prev = $existing[$tid] ?? null;
			// Re-PUT when the time changed OR the mirrored content (notes,
			// priority, %, location, url, title) changed, so editing a task's
			// content re-mirrors into its slot (plan 1.5.11).
			if ($prev === null || !$this->placementMatches($prev, $slot, $byId[$tid] ?? null, $now)) {
				if (isset($byId[$tid])) {
					$recon->toPut[] = [$byId[$tid], $slot];
				}
			} else {
				$recon->unchanged[] = $tid;
			}
		}

		// Blocks to remove: task no longer eligible, or eligible but unscheduled.
		foreach ($existing as $tid => $_) {
			if (!isset($desired[$tid]) || $desired[$tid] === null) {
				$recon->toDelete[] = $tid;
			}
		}
		return $recon;
	}

	private function placementMatches(CalendarEvent $existing, Slot $desired, ?Task $task, ?\DateTimeImmutable $now = null): bool {
		if ($existing->start != $desired->start || $existing->end != $desired->end) {
			return false;
		}
		if ($task === null) {
			return false;
		}
		return $this->contentMatches($existing, $task, $now);
	}

	private function contentMatches(CalendarEvent $existing, Task $task, ?\DateTimeImmutable $now = null): bool {
		$expected = IcalCodec::slotContent($task, $now);
		return $existing->summary === $expected['summary']
			&& $existing->description === $expected['description']
			&& $existing->location === $expected['location']
			&& $existing->url === $expected['url']
			&& $existing->priority === $expected['priority'];
	}
}