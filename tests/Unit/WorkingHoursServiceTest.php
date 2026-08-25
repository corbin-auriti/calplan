<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Service\WorkingHoursService;

class WorkingHoursServiceTest extends AutoScheduleTestCase {

	public function testDefaultIsSevenDaysNineToFive(): void {
		$wh = WorkingHoursService::default();
		$this->assertCount(7, $wh->days);
		$this->assertSame(0, $wh->days[0]->weekday);
		$this->assertSame(540, $wh->days[0]->startMinutes);
		$this->assertSame(1020, $wh->days[0]->endMinutes);
		$this->assertSame(6, $wh->days[6]->weekday);
	}

	public function testParseAndFormatRoundTrip(): void {
		$this->assertSame(540, WorkingHoursService::parseHhmm('9:00'));
		$this->assertSame(1020, WorkingHoursService::parseHhmm('17:00'));
		$this->assertSame(0, WorkingHoursService::parseHhmm('0:00'));
		$this->assertSame('9:00', WorkingHoursService::minutesToHhmm(540));
		$this->assertSame('17:00', WorkingHoursService::minutesToHhmm(1020));
		$this->assertSame('0:00', WorkingHoursService::minutesToHhmm(0));
		$this->assertSame('23:59', WorkingHoursService::minutesToHhmm(1439));
	}

	public function testIsValidHhmm(): void {
		$this->assertTrue(WorkingHoursService::isValidHhmm('9:00'));
		$this->assertTrue(WorkingHoursService::isValidHhmm('17:00'));
		$this->assertFalse(WorkingHoursService::isValidHhmm('garbage'));
		$this->assertFalse(WorkingHoursService::isValidHhmm('9'));
		$this->assertFalse(WorkingHoursService::isValidHhmm('9:0'));
	}

	public function testToMapOfDefault(): void {
		$map = WorkingHoursService::toMap(WorkingHoursService::default());
		$this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], array_keys($map));
		$this->assertSame('9:00', $map['mon']['start']);
		$this->assertSame('17:00', $map['mon']['end']);
		$this->assertSame('9:00', $map['sun']['start']);
		$this->assertSame('17:00', $map['sun']['end']);
	}

	public function testFromMapToMapRoundTrip(): void {
		$in = ['mon' => ['start' => '9:00', 'end' => '17:00'], 'wed' => ['start' => '10:00', 'end' => '12:30']];
		$out = WorkingHoursService::toMap(WorkingHoursService::fromMap($in));
		$this->assertSame($in, $out);
	}

	public function testFromMapOmitsMissingDays(): void {
		$wh = WorkingHoursService::fromMap(['mon' => ['start' => '9:00', 'end' => '17:00']]);
		$this->assertCount(1, $wh->days);
		$this->assertSame(0, $wh->days[0]->weekday);
	}

	public function testValidateMapAcceptsValid(): void {
		$this->assertNull(WorkingHoursService::validateMap([]));
		$this->assertNull(WorkingHoursService::validateMap(['tue' => ['start' => '9:00', 'end' => '17:00']]));
	}

	public function testValidateMapRejectsProblems(): void {
		$this->assertNotNull(WorkingHoursService::validateMap(['funday' => ['start' => '9:00', 'end' => '17:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => '9:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => '17:00', 'end' => '9:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => 'nine', 'end' => '17:00']]));
	}
}