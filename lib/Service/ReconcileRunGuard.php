<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

final class ReconcileRunGuard {
	private static int $depth = 0;

	public static function enter(): void {
		self::$depth++;
	}

	public static function leave(): void {
		self::$depth = max(0, self::$depth - 1);
	}

	public static function isActive(): bool {
		return self::$depth > 0;
	}
}
