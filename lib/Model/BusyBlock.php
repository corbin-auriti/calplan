<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * An existing VEVENT on the calendar that occupies time.
 */
class BusyBlock {
	public function __construct(
		public readonly \DateTimeImmutable $start,
		public readonly \DateTimeImmutable $end,
		public readonly ?string $uid = null,
	) {
	}
}