<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingDay;
use OCA\AutoSchedule\Model\WorkingHours;
use PHPUnit\Framework\TestCase;

abstract class AutoScheduleTestCase extends TestCase {
	/** Every day 09:00–17:00, so tests don't depend on the weekday of "now". */
	protected function allWeek(): WorkingHours {
		$days = [];
		for ($w = 0; $w < 7; $w++) {
			$days[] = new WorkingDay($w, 9 * 60, 17 * 60);
		}
		return new WorkingHours($days);
	}

	/** Mon–Fri 09:00–17:00 (weekends unavailable). */
	protected function monFri(): WorkingHours {
		$days = [];
		for ($w = 0; $w < 5; $w++) {
			$days[] = new WorkingDay($w, 9 * 60, 17 * 60);
		}
		return new WorkingHours($days);
	}

	protected function utc(string $iso): \DateTimeImmutable {
		return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
	}

	protected function task(
		string $id,
		string $title = 'T',
		int $priority = 5,
		?\DateTimeImmutable $due = null,
		?\DateTimeImmutable $start = null,
		int $estimatedMinutes = 30,
		bool $autoSchedule = true,
		string $status = 'NEEDS-ACTION',
		?string $description = null,
		?string $location = null,
		?string $url = null,
		?int $percentComplete = null,
		array $categories = [],
	): Task {
		return new Task(
			id: $id, title: $title, priority: $priority, due: $due, start: $start,
			estimatedMinutes: $estimatedMinutes, autoSchedule: $autoSchedule, status: $status,
			description: $description, location: $location, url: $url, percentComplete: $percentComplete,
			categories: $categories,
		);
	}
}