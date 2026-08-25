<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\CalendarEvent;

/** Case-insensitive user rules for events that should not count as busy time. */
class EventExclusionPolicy {
	/** @param string[] $titles */
	public function __construct(
		private array $titles,
		private string $matchMode = 'exact',
	) {
	}

	public function ignores(CalendarEvent $event): bool {
		// Owned slots must remain visible for idempotent updates and cleanup.
		if ($event->autoScheduleTaskUid !== null || $event->summary === null) {
			return false;
		}
		$summary = self::lower(trim($event->summary));
		foreach ($this->titles as $title) {
			$needle = self::lower(trim((string)$title));
			if ($needle === '') {
				continue;
			}
			if ($this->matchMode === 'contains' ? str_contains($summary, $needle) : $summary === $needle) {
				return true;
			}
		}
		return false;
	}

	private static function lower(string $value): string {
		return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
	}
}