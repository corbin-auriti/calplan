<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\WorkingHours;

/**
 * The state of one scheduling pass (SchedulerService::scheduleDetailed): the
 * free working-hours pool and what each day has used of the daily cap and the
 * blocks-per-day limit. Days are always the user's days, in the timezone of
 * *now*.
 *
 * @internal
 */
final class PlanState {
	/** @var Slot[] free working-hours slots, ordered by start */
	public array $slots;
	/** @var array<string,int> block minutes per day */
	public array $dailyMinutes = [];
	/** @var array<string,int> blocks per day */
	public array $dailyBlocks = [];
	/** @var BusyBlock[] blocks placed so far, widened by the task gap (for DTSTART windows) */
	public array $placed = [];

	public readonly \DateTimeZone $tz;

	/** @param Slot[] $slots @param BusyBlock[] $busy */
	public function __construct(
		public readonly \DateTimeImmutable $now,
		public readonly WorkingHours $working,
		public readonly array $busy,
		public readonly int $horizonDays,
		public readonly int $dailyCap,
		public readonly int $dailyTaskCount,
		public readonly int $taskGapMinutes,
		array $slots,
	) {
		$this->tz = $now->getTimezone();
		$this->slots = $slots;
	}

	/** The user's day of *t* (Y-m-d). */
	public function day(\DateTimeImmutable $t): string {
		return $t->setTimezone($this->tz)->format('Y-m-d');
	}

	/** Count a block of *minutes* on *day* against the daily cap and the blocks-per-day limit. */
	public function charge(string $day, int $minutes): void {
		$this->dailyMinutes[$day] = ($this->dailyMinutes[$day] ?? 0) + $minutes;
		$this->dailyBlocks[$day] = ($this->dailyBlocks[$day] ?? 0) + 1;
	}

	/** Take *block* (plus the task gap on both sides) out of the free pool and note it as placed. */
	public function occupy(Slot $block): void {
		$this->slots = self::without($this->slots, $block, $this->taskGapMinutes);
		$this->placed[] = new BusyBlock(
			self::plusMinutes($block->start, -$this->taskGapMinutes),
			self::plusMinutes($block->end, $this->taskGapMinutes),
		);
	}

	/**
	 * *slots* without [block start - gap, block end + gap]: every slot it
	 * overlaps keeps only its usable rest (at least one grid step).
	 *
	 * @param Slot[] $slots
	 * @return Slot[]
	 */
	public static function without(array $slots, Slot $block, int $gapMinutes): array {
		$from = self::plusMinutes($block->start, -$gapMinutes);
		$to = self::plusMinutes($block->end, $gapMinutes);
		$kept = [];
		foreach ($slots as $slot) {
			if ($slot->end <= $from || $slot->start >= $to) {
				$kept[] = $slot;
				continue;
			}
			$tz = $slot->start->getTimezone();
			foreach ([new Slot($slot->start, $from->setTimezone($tz)), new Slot($to->setTimezone($tz), $slot->end)] as $rest) {
				if ($rest->durationMinutes() >= Defaults::SLOT_GRANULARITY_MINUTES) {
					$kept[] = $rest;
				}
			}
		}
		return $kept;
	}

	/**
	 * *t* moved by *minutes* of elapsed time. Unlike modify('+N minutes'), which
	 * works in wall-clock time, a block crossing a DST change keeps its length.
	 */
	public static function plusMinutes(\DateTimeImmutable $t, int $minutes): \DateTimeImmutable {
		return $minutes === 0 ? $t : $t->setTimestamp($t->getTimestamp() + $minutes * 60);
	}

	/** Whole minutes from *from* to *to*. */
	public static function minutesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int {
		return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
	}
}
