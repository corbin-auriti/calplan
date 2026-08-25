<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

class PlacementResult {
	public function __construct(
		public readonly ?Slot $slot,
		public readonly ?string $reason = null,
	) {
	}
}
