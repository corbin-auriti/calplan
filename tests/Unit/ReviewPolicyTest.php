<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Service\ReconcileService;

class ReviewPolicyTest extends AutoScheduleTestCase {
	public function testAutoRescheduleAddsReviewAndPreservesScheduleAndUserTags(): void {
		$task = $this->task('T', categories: ['work', Defaults::FLAG_CATEGORY]);

		$this->assertSame(['work', 'calplan', 'calplan-review'], $task->reviewCategories(true));
	}

	public function testDisabledAutoRescheduleAddsReviewAndRemovesOnlyScheduleTag(): void {
		$task = $this->task('T', categories: ['work', Defaults::FLAG_CATEGORY, 'urgent']);

		$this->assertSame(['work', 'urgent', 'calplan-review'], $task->reviewCategories(false));
	}

	public function testReviewCategoriesAreIdempotent(): void {
		$task = $this->task('T', categories: ['calplan', 'calplan-review']);

		$this->assertSame(['calplan', 'calplan-review'], $task->reviewCategories(true));
		$this->assertTrue($task->needsReview());
	}

	public function testGraceBoundaryIsInclusive(): void {
		$now = $this->utc('2026-08-23 10:15:00');

		$this->assertTrue(ReconcileService::isPastReviewGrace($this->utc('2026-08-23 10:00:00'), $now, 15));
		$this->assertFalse(ReconcileService::isPastReviewGrace($this->utc('2026-08-23 10:01:00'), $now, 15));
	}
}