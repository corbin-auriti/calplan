<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Service\FreeSlotsService;

class FreeSlotsServiceTest extends AutoScheduleTestCase {
	public function testSnapUpRoundsToNextBoundary(): void {
		$this->assertSame('2026-08-19 10:15:00', FreeSlotsService::snapUp($this->utc('2026-08-19 10:07:31'))->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:00:00', FreeSlotsService::snapUp($this->utc('2026-08-19 10:00:00'))->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 11:00:00', FreeSlotsService::snapUp($this->utc('2026-08-19 10:45:01'))->format('Y-m-d H:i:s'));
	}

	public function testSnapDownRoundsToPreviousBoundary(): void {
		$this->assertSame('2026-08-19 10:00:00', FreeSlotsService::snapDown($this->utc('2026-08-19 10:07:31'))->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 10:15:00', FreeSlotsService::snapDown($this->utc('2026-08-19 10:29:00'))->format('Y-m-d H:i:s'));
	}

	public function testEmptyCalendarYieldsOneSlotPerWorkingDay(): void {
		$slots = FreeSlotsService::freeSlots($this->allWeek(), [], $this->utc('2026-08-19 08:00:00'), 14, 15);
		$this->assertCount(14, $slots);
		// Today is clamped: window starts at 09:00 (now 08:00 is before opening).
		$this->assertSame('2026-08-19 09:00:00', $slots[0]->start->format('Y-m-d H:i:s'));
	}

	public function testFirstDayIsClampedToNowWhenAfternoon(): void {
		$slots = FreeSlotsService::freeSlots($this->allWeek(), [], $this->utc('2026-08-19 10:30:00'), 1, 15);
		$this->assertCount(1, $slots);
		$this->assertSame('2026-08-19 10:30:00', $slots[0]->start->format('Y-m-d H:i:s'));
	}

	public function testBusyBlockInTheMiddleCarvesTwoFreeSlots(): void {
		$busy = [new BusyBlock($this->utc('2026-08-19 11:00:00'), $this->utc('2026-08-19 12:00:00'))];
		$slots = FreeSlotsService::freeSlots($this->allWeek(), $busy, $this->utc('2026-08-19 08:00:00'), 1, 15);
		$this->assertCount(2, $slots);
		$this->assertSame('2026-08-19 09:00:00', $slots[0]->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 11:00:00', $slots[0]->end->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 12:00:00', $slots[1]->start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-19 17:00:00', $slots[1]->end->format('Y-m-d H:i:s'));
	}

	public function testNonWorkingDayIsSkipped(): void {
		// 2026-08-22 is a Saturday; with Mon–Fri hours it yields no slot that day.
		$slots = FreeSlotsService::freeSlots($this->monFri(), [], $this->utc('2026-08-19 08:00:00'), 7, 15);
		$onSat = array_filter($slots, fn ($s) => $s->start->format('Y-m-d') === '2026-08-22');
		$this->assertSame([], array_values($onSat));
	}

	public function testSlotShorterThanMinMinutesIsDropped(): void {
		// 30 minutes free at the end (16:30–17:00); min 60 → dropped.
		$busy = [new BusyBlock($this->utc('2026-08-19 09:00:00'), $this->utc('2026-08-19 16:30:00'))];
		$slots = FreeSlotsService::freeSlots($this->allWeek(), $busy, $this->utc('2026-08-19 08:00:00'), 1, 60);
		$this->assertSame([], $slots);
	}
}