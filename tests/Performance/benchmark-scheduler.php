<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require dirname(__DIR__) . '/bootstrap.php';

use OCA\AutoSchedule\Model\BusyBlock;
use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Model\WorkingDay;
use OCA\AutoSchedule\Model\WorkingHours;
use OCA\AutoSchedule\Service\SchedulerService;

$taskCount = max(1, (int)($argv[1] ?? 100));
$eventCount = max(0, (int)($argv[2] ?? 500));
$iterations = max(1, (int)($argv[3] ?? 20));
$now = new DateTimeImmutable('2026-08-19 08:00:00', new DateTimeZone('UTC'));
$days = [];
for ($weekday = 0; $weekday < 7; $weekday++) {
	$days[] = new WorkingDay($weekday, 9 * 60, 17 * 60);
}
$working = new WorkingHours($days);
$tasks = [];
for ($i = 0; $i < $taskCount; $i++) {
	$tasks[] = new Task(
		'scale-' . $i,
		'Task ' . $i,
		($i % 9) + 1,
		$now->modify('+' . (($i % 14) + 1) . ' days')->setTime(17, 0),
		null,
		[15, 30, 45, 60][$i % 4],
		true,
	);
}
$busy = [];
for ($i = 0; $i < $eventCount; $i++) {
	$start = $now->modify('+' . ($i % 14) . ' days')->setTime(9 + ($i % 8), ($i * 15) % 60);
	$busy[] = new BusyBlock($start, $start->modify('+15 minutes'));
}
$scheduler = new SchedulerService();
$times = [];
for ($i = 0; $i < $iterations; $i++) {
	$started = hrtime(true);
	$scheduler->schedule($tasks, $working, $busy, $now);
	$times[] = (hrtime(true) - $started) / 1_000_000;
}
sort($times);
$percentile = static function (array $values, float $p): float {
	$index = (int)floor((count($values) - 1) * $p);
	return $values[$index];
};
printf(
	"CalPlan scheduler benchmark: tasks=%d busy=%d iterations=%d min=%.2fms median=%.2fms p95=%.2fms max=%.2fms\n",
	$taskCount,
	$eventCount,
	$iterations,
	$times[0],
	$percentile($times, 0.5),
	$percentile($times, 0.95),
	$times[count($times) - 1],
);
