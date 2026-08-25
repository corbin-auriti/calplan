<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * Working hours for one weekday (0 = Monday … 6 = Sunday), stored as minutes
 * from midnight so the scheduler can build day windows without time-type fuss.
 */
class WorkingDay {
	public function __construct(
		public readonly int $weekday,
		public readonly int $startMinutes,
		public readonly int $endMinutes,
	) {
	}
}