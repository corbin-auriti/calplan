<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Service\SchedulerService;

class SchedulerScaleTest extends AutoScheduleTestCase {
	private SchedulerService $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduler = new SchedulerService();
	}

	public function testDozensOfTasksRecomputeDeterministicallyWithinRegressionBudget(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = $this->tasks(60, $now);
		$busy = $this->busyBlocks(120, $now);

		$started = hrtime(true);
		$first = $this->scheduler->schedule($tasks, $this->allWeek(), $busy, $now);
		$firstMs = (hrtime(true) - $started) / 1_000_000;
		$started = hrtime(true);
		$second = $this->scheduler->schedule($tasks, $this->allWeek(), $busy, $now);
		$secondMs = (hrtime(true) - $started) / 1_000_000;

		$this->assertCount(60, $first);
		$this->assertEquals($first, $second, 'Recomputing identical inputs must produce the same plan');
		$this->assertLessThan(5_000.0, max($firstMs, $secondMs), 'Pure scheduling exceeded the broad regression budget');
		fwrite(STDERR, sprintf("\nCalPlan scale: 60 tasks + 120 busy: first %.2f ms, recompute %.2f ms\n", $firstMs, $secondMs));
	}

	public function testHundredsOfTasksRemainBoundedAndReturnEveryCandidate(): void {
		$now = $this->utc('2026-08-19 08:00:00');
		$tasks = $this->tasks(250, $now);
		$busy = $this->busyBlocks(500, $now);

		$started = hrtime(true);
		$placed = $this->scheduler->schedule($tasks, $this->allWeek(), $busy, $now);
		$elapsedMs = (hrtime(true) - $started) / 1_000_000;

		$this->assertCount(250, $placed);
		$this->assertLessThan(5_000.0, $elapsedMs, 'Pure scheduling exceeded the broad regression budget');
		fwrite(STDERR, sprintf("CalPlan scale: 250 tasks + 500 busy: %.2f ms\n", $elapsedMs));
	}

	/** @return array<\OCA\AutoSchedule\Model\Task> */
	private function tasks(int $count, \DateTimeImmutable $now): array {
		$tasks = [];
		for ($i = 0; $i < $count; $i++) {
			$tasks[] = $this->task(
				'scale-' . $i,
				priority: ($i % 9) + 1,
				due: $now->modify('+' . (($i % 14) + 1) . ' days')->setTime(17, 0),
				estimatedMinutes: [15, 30, 45, 60][$i % 4],
			);
		}
		return $tasks;
	}

	/** @return BusyBlock[] */
	private function busyBlocks(int $count, \DateTimeImmutable $now): array {
		$busy = [];
		for ($i = 0; $i < $count; $i++) {
			$day = $now->modify('+' . ($i % 14) . ' days')->setTime(9 + ($i % 8), 0);
			$busy[] = new BusyBlock($day, $day->modify('+15 minutes'));
		}
		return $busy;
	}
}
