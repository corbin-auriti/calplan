<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * A contiguous, free chunk of time a task can be dropped into.
 */
class Slot {
	public function __construct(
		public readonly \DateTimeImmutable $start,
		public readonly \DateTimeImmutable $end,
	) {
	}

	public function durationMinutes(): float {
		return ((float)($this->end->getTimestamp() - $this->start->getTimestamp())) / 60.0;
	}

	public function overlaps(self $other): bool {
		return $this->start < $other->end && $other->start < $this->end;
	}
}