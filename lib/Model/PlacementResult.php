<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

class PlacementResult {
	/**
	 * @param Slot[] $parts when the task was split: every part, in time order
	 *                      ($slot is then the first one); empty for a whole block
	 */
	public function __construct(
		public readonly ?Slot $slot,
		public readonly ?string $reason = null,
		public readonly array $parts = [],
	) {
	}

	/** @return Slot[] the blocks to write: the parts, or the single slot */
	public function blocks(): array {
		if ($this->parts !== []) {
			return $this->parts;
		}
		return $this->slot !== null ? [$this->slot] : [];
	}
}
