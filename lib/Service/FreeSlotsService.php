<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Free-slot finder. Given working-hour windows and busy calendar blocks,
 * compute the free Slots a task could be placed into — the classic
 * interval-scheduling step of subtracting busy intervals from an availability
 * window. Pure: no I/O, deterministic.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\WorkingHours;

class FreeSlotsService {
	/** Round *dt* up to the next granularity boundary (10:07 → 10:15). */
	public static function snapUp(\DateTimeImmutable $dt, int $granularity = Defaults::SLOT_GRANULARITY_MINUTES): \DateTimeImmutable {
		$dt = $dt->setTime((int)$dt->format('H'), (int)$dt->format('i'), (int)$dt->format('s'), 0);
		$totalSec = ((int)$dt->format('H')) * 3600 + ((int)$dt->format('i')) * 60 + (int)$dt->format('s');
		$grid = $granularity * 60;
		$rem = $totalSec % $grid;
		if ($rem > 0) {
			$dt = $dt->modify('+' . ($grid - $rem) . ' seconds');
		}
		return $dt;
	}

	/** Round *dt* down to the previous granularity boundary (10:07 → 10:00). */
	public static function snapDown(\DateTimeImmutable $dt, int $granularity = Defaults::SLOT_GRANULARITY_MINUTES): \DateTimeImmutable {
		$dt = $dt->setTime((int)$dt->format('H'), (int)$dt->format('i'), (int)$dt->format('s'), 0);
		$totalSec = ((int)$dt->format('H')) * 3600 + ((int)$dt->format('i')) * 60 + (int)$dt->format('s');
		$grid = $granularity * 60;
		$rem = $totalSec % $grid;
		if ($rem > 0) {
			$dt = $dt->modify('-' . $rem . ' seconds');
		}
		return $dt;
	}

	/** The working window for *day* (midnight), clamped to never start before *fromDt*. */
	public static function dayWindow(\DateTimeImmutable $day, WorkingHours $working, \DateTimeImmutable $fromDt): ?Slot {
		// ISO-8601 'N': 1 (Mon) … 7 (Sun) → 0 … 6.
		$weekday = (int)$day->format('N') - 1;
		$wd = $working->forWeekday($weekday);
		if ($wd === null) {
			return null;
		}
		$start = $day->modify('+' . $wd->startMinutes . ' minutes');
		$end = $day->modify('+' . $wd->endMinutes . ' minutes');
		if ($fromDt > $start) {
			$start = $fromDt;
		}
		if ($start >= $end) {
			return null;
		}
		return new Slot($start, $end);
	}

	/** Subtract busy blocks from *window*, returning the remaining free slots. */
	public static function subtractBusy(Slot $window, array $busy): array {
		$clips = [];
		foreach ($busy as $b) {
			if (!($b instanceof BusyBlock)) {
				continue;
			}
			if ($b->end <= $window->start || $b->start >= $window->end) {
				continue;
			}
			$clips[] = new Slot(
				$b->start > $window->start ? $b->start : $window->start,
				$b->end < $window->end ? $b->end : $window->end,
			);
		}
		if ($clips === []) {
			return [$window];
		}
		usort($clips, fn (Slot $a, Slot $b) => $a->start <=> $b->start);
		$free = [];
		$cursor = $window->start;
		foreach ($clips as $c) {
			if ($c->start > $cursor) {
				$free[] = new Slot($cursor, $c->start);
			}
			if ($c->end > $cursor) {
				$cursor = $c->end;
			}
		}
		if ($cursor < $window->end) {
			$free[] = new Slot($cursor, $window->end);
		}
		return $free;
	}

	/**
	 * All free working-hour slots from *fromDt* for *horizonDays*. Each returned
	 * slot is snapped to the grid and lasts at least *minMinutes*. Non-working days
	 * and the part of today before *fromDt* are skipped.
	 *
	 * @param BusyBlock[] $busy
	 * @return Slot[]
	 */
	public static function freeSlots(WorkingHours $working, array $busy, \DateTimeImmutable $fromDt, int $horizonDays, int $minMinutes): array {
		$result = [];
		for ($i = 0; $i < $horizonDays; $i++) {
			$day = $fromDt->setTime(0, 0, 0)->modify('+' . $i . ' days');
			$window = self::dayWindow($day, $working, $fromDt);
			if ($window === null) {
				continue;
			}
			foreach (self::subtractBusy($window, $busy) as $s) {
				$snapped = new Slot(self::snapUp($s->start), self::snapDown($s->end));
				if ($snapped->start < $snapped->end && $snapped->durationMinutes() >= $minMinutes) {
					$result[] = $snapped;
				}
			}
		}
		return $result;
	}
}