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

	public function testValidationAcceptsMidnightAndRejectsImpossibleTimes(): void {
		$this->assertNull(WorkingHoursService::validateMap(['mon' => ['start' => '18:00', 'end' => '24:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => '09:00', 'end' => '99:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => '09:60', 'end' => '17:00']]));
		$this->assertNotNull(WorkingHoursService::validateMap(['mon' => ['start' => '24:00', 'end' => '24:00']]));
	}

	public function testNextcloudAvailabilityBecomesOneWindowPerDay(): void {
		if (!class_exists('\\Sabre\\VObject\\Reader')) {
			$this->markTestSkipped('Sabre\\VObject not available in this environment');
		}
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:Nextcloud DAV app\r\nBEGIN:VAVAILABILITY\r\n"
			. "BEGIN:AVAILABLE\r\nUID:a\r\nDTSTART;TZID=Europe/Rome:20241014T090000\r\nDTEND;TZID=Europe/Rome:20241014T120000\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,TU\r\nEND:AVAILABLE\r\n"
			. "BEGIN:AVAILABLE\r\nUID:b\r\nDTSTART;TZID=Europe/Rome:20241014T130000\r\nDTEND;TZID=Europe/Rome:20241014T173000\r\nRRULE:FREQ=WEEKLY;BYDAY=MO\r\nEND:AVAILABLE\r\n"
			. "END:VAVAILABILITY\r\nEND:VCALENDAR\r\n";
		$this->assertSame([
			'mon' => ['start' => '9:00', 'end' => '17:30'],
			'tue' => ['start' => '9:00', 'end' => '12:00'],
		], WorkingHoursService::fromAvailability($ics));
	}
}
