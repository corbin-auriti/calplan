<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Service\IcalCodec;
use PHPUnit\Framework\TestCase;

/**
 * Sabre-dependent codec round-trips. Skips itself when Sabre\VObject is not
 * available (the pure-core test runner has no VObject library); run via the
 * live NC container / distrobox-with-NC-vendor for the integration path.
 */
class IcalCodecTest extends TestCase {
	private function requireSabre(): void {
		if (!class_exists('\\Sabre\\VObject\\Reader')) {
			$this->markTestSkipped('Sabre\\VObject not available in this environment');
		}
	}

	private function vtodo(string $extra = ''): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
			. "BEGIN:VTODO\r\nUID:task-1\r\nSUMMARY:Do a thing\r\nPRIORITY:5\r\n"
			. "STATUS:NEEDS-ACTION\r\nCATEGORIES:calplan\r\n"
			. $extra
			. "END:VTODO\r\nEND:VCALENDAR\r\n";
	}

	public function testParseTasksReadsDurationAsEstimate(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DURATION:PT45M\r\n"));
		$this->assertCount(1, $tasks);
		$this->assertSame(45, $tasks[0]->estimatedMinutes);
		$this->true($tasks[0]->autoSchedule);
	}

	public function testParseTasksDefaultsEstimateWhenNoDuration(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo());
		$this->assertCount(1, $tasks);
		$this->assertSame(Defaults::DEFAULT_ESTIMATE_MINUTES, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksUsesCallerDefaultEstimate(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo(), 45);
		$this->assertSame(45, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksReadsDurationFromNotesWhenPropertyIsAbsent(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DESCRIPTION:Notes\\nDuration: 15min\\nMore notes\r\n"));
		$this->assertSame(15, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksDurationPropertyWinsOverNotes(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DURATION:PT45M\r\nDESCRIPTION:Duration: 15min\r\n"));
		$this->assertSame(45, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksDurationHoursAndMinutes(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DURATION:PT1H30M\r\n"));
		$this->assertSame(90, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksGarbageDurationFallsBack(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DURATION:banana\r\n"));
		$this->assertSame(Defaults::DEFAULT_ESTIMATE_MINUTES, $tasks[0]->estimatedMinutes);
	}
	public function testParseTasksReadsMirroredFields(): void {
		$this->requireSabre();
		$ics = $this->vtodo('DESCRIPTION:do the thing\r\nLOCATION:Desk\r\nURL:https://ex.test/t\r\nPERCENT-COMPLETE:60\r\n');
		$tasks = IcalCodec::parseTasks($ics);
		$this->assertCount(1, $tasks);
		$this->assertSame('do the thing', $tasks[0]->description);
		$this->assertSame('Desk', $tasks[0]->location);
		$this->assertSame('https://ex.test/t', $tasks[0]->url);
		$this->assertSame(60, $tasks[0]->percentComplete);
	}

	public function testReplaceTaskCategoriesPreservesTaskContent(): void {
		$this->requireSabre();
		$ics = $this->vtodo("DESCRIPTION:keep me\r\nLOCATION:Desk\r\n");
		$updated = IcalCodec::replaceTaskCategories($ics, ['work', 'calplan-review']);
		$tasks = IcalCodec::parseTasks($updated);

		$this->assertSame('Do a thing', $tasks[0]->title);
		$this->assertSame('keep me', $tasks[0]->description);
		$this->assertSame('Desk', $tasks[0]->location);
		$this->assertSame(['work', 'calplan-review'], $tasks[0]->categories);
		$this->assertFalse($tasks[0]->autoSchedule);
		$this->assertTrue($tasks[0]->needsReview());
	}

	public function testBuildSlotEventMirrorsContent(): void {
		$this->requireSabre();
		$task = new \OCA\AutoSchedule\Model\Task(
			'task-1', 'Do a thing', 3, null, null, 45, true, 'NEEDS-ACTION',
			'notes', 'Desk', 'https://ex.test/t', 60
		);
		$start = new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('UTC'));
		$end = new \DateTimeImmutable('2026-08-20 09:45:00', new \DateTimeZone('UTC'));
		$ics = IcalCodec::buildSlotEvent($task, $start, $end);
		$this->assertStringContainsString('SUMMARY:Do a thing', $ics);
		$this->assertStringContainsString('PRIORITY:3', $ics);
		$this->assertStringContainsString('LOCATION:Desk', $ics);
		$this->assertStringContainsString('URL:https://ex.test/t', $ics);
		$this->assertStringContainsString('DESCRIPTION:60% - notes', $ics);
	}

	public function testParseEventsReadsBackMirroredSlotContent(): void {
		$this->requireSabre();
		$task = new \OCA\AutoSchedule\Model\Task(
			'task-1', 'Do a thing', 3, null, null, 45, true, 'NEEDS-ACTION',
			'notes', 'Desk', 'https://ex.test/t', 60
		);
		$start = new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('UTC'));
		$end = new \DateTimeImmutable('2026-08-20 09:45:00', new \DateTimeZone('UTC'));
		$ics = IcalCodec::buildSlotEvent($task, $start, $end);
		$events = IcalCodec::parseEvents($ics);
		$this->assertCount(1, $events);
		$e = $events[0];
		$this->assertSame('task-1', $e->autoScheduleTaskUid);
		$this->assertSame('Do a thing', $e->summary);
		$this->assertSame('60% - notes', $e->description);
		$this->assertSame('Desk', $e->location);
		$this->assertSame('https://ex.test/t', $e->url);
		$this->assertSame(3, $e->priority);
	}

	public function testParseEventsReadsTransp(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:ev-1\r\nDTSTART:20260820T090000Z\r\nDTEND:20260820T100000Z\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$events = IcalCodec::parseEvents($ics);
		$this->assertCount(1, $events);
		$this->assertSame('TRANSPARENT', $events[0]->transp);
	}

	public function testParseEventsReadsOrdinaryEventSummary(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:ev-title\r\nSUMMARY:Busy hold\r\nDTSTART:20260820T090000Z\r\nDTEND:20260820T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$events = IcalCodec::parseEvents($ics);
		$this->assertCount(1, $events);
		$this->assertSame('Busy hold', $events[0]->summary);
	}

	public function testCalplanPrefixedOrdinaryEventIsNotMistakenForOwnedSlot(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:calplan-ordinary-meeting\r\nSUMMARY:Ordinary meeting\r\nDTSTART:20260820T090000Z\r\nDTEND:20260820T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$events = IcalCodec::parseEvents($ics);
		$this->assertCount(1, $events);
		$this->assertNull($events[0]->autoScheduleTaskUid);
	}

	public function testParseEventsTranspDefaultsToNull(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:ev-2\r\nDTSTART:20260820T090000Z\r\nDTEND:20260820T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$events = IcalCodec::parseEvents($ics);
		$this->assertCount(1, $events);
		$this->assertNull($events[0]->transp);
	}
}
