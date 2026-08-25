<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Service\EventExclusionPolicy;
use OCA\AutoSchedule\Service\ReconcileService;

class EventExclusionPolicyTest extends AutoScheduleTestCase {
	private function event(?string $summary, ?string $taskUid = null): CalendarEvent {
		return new CalendarEvent('event', $this->utc('2026-08-24 09:00:00'), $this->utc('2026-08-24 10:00:00'), $taskUid, $summary);
	}

	public function testExactMatchingIsCaseInsensitiveAndTrimsRules(): void {
		$policy = new EventExclusionPolicy([' Busy '], 'exact');
		$this->assertTrue($policy->ignores($this->event('BUSY')));
		$this->assertFalse($policy->ignores($this->event('Busy hold')));
	}

	public function testContainsMatchingIsCaseInsensitive(): void {
		$policy = new EventExclusionPolicy(['busy'], 'contains');
		$this->assertTrue($policy->ignores($this->event('External BUSY hold')));
		$this->assertFalse($policy->ignores($this->event('Meeting')));
	}

	public function testEmptyRulesAndMissingSummaryDoNotMatch(): void {
		$policy = new EventExclusionPolicy(['', '  '], 'contains');
		$this->assertFalse($policy->ignores($this->event('Busy')));
		$this->assertFalse($policy->ignores($this->event(null)));
	}

	public function testOwnedSlotIsNeverIgnoredEvenWhenItsTitleMatches(): void {
		$policy = new EventExclusionPolicy(['busy'], 'contains');
		$this->assertFalse($policy->ignores($this->event('🔒 Busy task', 'task-1')));
	}

	public function testIgnoredCalendarIsRemovedAtTheHardReconcileBoundary(): void {
		$calendars = [
			'personal' => ['id' => 1],
			'booking-holds' => ['id' => 2, 'hasExistingSlots' => true],
			'work' => ['id' => 3],
		];

		$this->assertSame(
			['personal' => ['id' => 1], 'work' => ['id' => 3]],
			ReconcileService::withoutIgnoredCalendars($calendars, ['booking-holds']),
		);
	}
}