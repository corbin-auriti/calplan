<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * The user's working-hours definition, one window per weekday.
 *
 * @psalm-type WorkingDayList = list<WorkingDay>
 */
class WorkingHours {
	/** @param WorkingDay[] $days */
	public function __construct(
		public readonly array $days = [],
	) {
	}

	public function forWeekday(int $weekday): ?WorkingDay {
		foreach ($this->days as $d) {
			if ($d->weekday === $weekday) {
				return $d;
			}
		}
		return null;
	}
}