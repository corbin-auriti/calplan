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
		$this->assertTrue($tasks[0]->autoSchedule);
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

	public function testParseTasksEstimatedDurationWinsOverDurationAndNotes(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("ESTIMATED-DURATION:PT1H15M\r\nDURATION:PT45M\r\nDESCRIPTION:Duration: 15min\r\n"));
		$this->assertSame(75, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksGarbageEstimatedDurationFallsBackToNotes(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("ESTIMATED-DURATION:banana\r\nDESCRIPTION:Duration: 15min\r\n"));
		$this->assertSame(15, $tasks[0]->estimatedMinutes);
	}

	public function testParseTasksReadsDailyLimits(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("X-CALPLAN-DAILY-MAX-TIME:PT2H30M\r\nX-CALPLAN-DAILY-MAX-BLOCKS:3\r\n"));
		$this->assertSame(150, $tasks[0]->dailyLimitMinutes);
		$this->assertSame(3, $tasks[0]->dailyLimitBlocks);
		$none = IcalCodec::parseTasks($this->vtodo("X-CALPLAN-DAILY-MAX-BLOCKS:lots\r\n"));
		$this->assertSame(0, $none[0]->dailyLimitMinutes);
		$this->assertSame(0, $none[0]->dailyLimitBlocks);
	}

	public function testParseTasksMarksAnAllDayStart(): void {
		$this->requireSabre();
		$this->assertTrue(IcalCodec::parseTasks($this->vtodo("DTSTART;VALUE=DATE:20260820\r\n"))[0]->startAllDay);
		$this->assertFalse(IcalCodec::parseTasks($this->vtodo("DTSTART:20260820T090000Z\r\n"))[0]->startAllDay);
	}

	public function testParseTasksReadsSplittable(): void {
		$this->requireSabre();
		$this->assertTrue(IcalCodec::parseTasks($this->vtodo("X-CALPLAN-SPLITTABLE:TRUE\r\n"))[0]->splittable);
		$this->assertFalse(IcalCodec::parseTasks($this->vtodo())[0]->splittable);
	}

	public function testParseTasksReadsTheManualSortOrder(): void {
		$this->requireSabre();
		$this->assertSame(42, IcalCodec::parseTasks($this->vtodo("X-APPLE-SORT-ORDER:42\r\nCREATED:20261016T200000Z\r\n"))[0]->sortOrder);
		// Without it, like Nextcloud Tasks: seconds from 2001-01-01 to CREATED.
		$this->assertSame(60, IcalCodec::parseTasks($this->vtodo("CREATED:20010101T000100Z\r\n"))[0]->sortOrder);
	}

	public function testPartBlockRoundTrip(): void {
		$this->requireSabre();
		$task = new \OCA\AutoSchedule\Model\Task('task-1', 'Do a thing', estimatedMinutes: 120, autoSchedule: true);
		$start = new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('UTC'));
		$end = new \DateTimeImmutable('2026-08-20 10:00:00', new \DateTimeZone('UTC'));
		$ics = IcalCodec::buildSlotEvent($task, $start, $end, $start, 2, 3);
		$this->assertStringContainsString("UID:calplan-task-1~p2\r\n", $ics);
		$this->assertStringContainsString("X-CALPLAN-PART:2/3\r\n", $ics);
		$events = IcalCodec::parseEvents($ics);
		$this->assertSame('task-1', $events[0]->autoScheduleTaskUid);
		$this->assertSame('task-1~p2', $events[0]->slotKey());
		// Block times are written in UTC, so no VTIMEZONE is needed.
		$this->assertStringContainsString("DTSTART:20260820T090000Z\r\n", $ics);
		$this->assertStringEndsWith('Do a thing (2/3)', $events[0]->summary);
		$this->assertSame('task-1', IcalCodec::taskUidOfSlotKey('task-1~p2'));
		$this->assertSame('task-1', IcalCodec::taskUidOfSlotKey('task-1'));
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
		$ics = $this->vtodo("DESCRIPTION:do the thing\r\nLOCATION:Desk\r\nURL:https://ex.test/t\r\nPERCENT-COMPLETE:60\r\n");
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
		// Sabre serializes URL with the (redundant, valid) VALUE=URI parameter.
		$this->assertMatchesRegularExpression('/^URL(;VALUE=URI)?:https:\/\/ex\.test\/t\r?$/m', $ics);
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

	/** @param CalendarEvent[] $events */
	private function utcStarts(array $events): array {
		return array_map(fn ($e) => $e->start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $events);
	}

	public function testParseEventsExpandsRecurringEventsInsideTheWindow(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:lunch\r\nSUMMARY:Lunch\r\n"
			. "DTSTART:20261013T103000Z\r\nDTEND:20261013T113000Z\r\nRRULE:FREQ=DAILY\r\nEXDATE:20261017T103000Z\r\n"
			. "END:VEVENT\r\nEND:VCALENDAR\r\n";
		$from = new \DateTimeImmutable('2026-10-16 00:00:00', new \DateTimeZone('UTC'));
		$until = new \DateTimeImmutable('2026-10-19 00:00:00', new \DateTimeZone('UTC'));
		$events = IcalCodec::parseEvents($ics, $from, $until);
		$this->assertSame(['2026-10-16 10:30', '2026-10-18 10:30'], $this->utcStarts($events));
		$this->assertSame('2026-10-18 11:30', $events[1]->end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
		$this->assertSame('Lunch', $events[1]->summary);
	}

	public function testParseEventsExpandsLocalTimeRecurrenceAcrossDstChange(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:lunch\r\n"
			. "DTSTART;TZID=Europe/Rome:20261013T123000\r\nDTEND;TZID=Europe/Rome:20261013T133000\r\nRRULE:FREQ=DAILY\r\n"
			. "END:VEVENT\r\nEND:VCALENDAR\r\n";
		$from = new \DateTimeImmutable('2026-10-24 00:00:00', new \DateTimeZone('UTC'));
		$until = new \DateTimeImmutable('2026-10-27 00:00:00', new \DateTimeZone('UTC'));
		$events = IcalCodec::parseEvents($ics, $from, $until);
		// 12:30 in Rome is 10:30 UTC in summer time and 11:30 UTC after 2026-10-25.
		$this->assertSame(['2026-10-24 10:30', '2026-10-25 11:30', '2026-10-26 11:30'], $this->utcStarts($events));
	}

	public function testParseEventsWithoutWindowKeepsTheRecurringMaster(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:lunch\r\n"
			. "DTSTART:20261013T103000Z\r\nDTEND:20261013T113000Z\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame(['2026-10-13 10:30'], $this->utcStarts(IcalCodec::parseEvents($ics)));
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

	private function vevent(string $body): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\n" . $body . "END:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	public function testAllDayAndFloatingTimesAreTheUsersTimes(): void {
		$this->requireSabre();
		$ny = new \DateTimeZone('America/New_York');
		$task = IcalCodec::parseTasks($this->vtodo("DUE;VALUE=DATE:20261021\r\nDTSTART:20261020T100000\r\n"), 30, $ny)[0];
		$this->assertSame('2026-10-21T00:00:00-04:00', $task->due->format(DATE_ATOM));
		$this->assertSame('2026-10-20T10:00:00-04:00', $task->start->format(DATE_ATOM));
		$event = IcalCodec::parseEvents($this->vevent("UID:vacation\r\nDTSTART;VALUE=DATE:20261020\r\n"), null, null, new \DateTimeZone('Australia/Sydney'))[0];
		// An all-day event without DTEND lasts that whole day, the user's day.
		$this->assertSame('2026-10-20T00:00:00+11:00', $event->start->format(DATE_ATOM));
		$this->assertSame('2026-10-21T00:00:00+11:00', $event->end->format(DATE_ATOM));
	}

	public function testAnEventWithADurationLastsThatLong(): void {
		$this->requireSabre();
		$event = IcalCodec::parseEvents($this->vevent("UID:m\r\nDTSTART;TZID=Europe/Rome:20261020T090000\r\nDURATION:PT8H\r\n"))[0];
		$this->assertSame(8 * 3600, $event->end->getTimestamp() - $event->start->getTimestamp());
		// Recurring occurrences keep their DURATION too.
		$events = IcalCodec::parseEvents($this->vevent("UID:r\r\nDTSTART:20261019T090000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"),
			new \DateTimeImmutable('2026-10-18T00:00:00Z'), new \DateTimeImmutable('2026-10-25T00:00:00Z'));
		$this->assertCount(3, $events);
		$this->assertSame(3600, $events[2]->end->getTimestamp() - $events[2]->start->getTimestamp());
	}

	public function testAReversedEventIsSkipped(): void {
		$this->requireSabre();
		$this->assertSame([], IcalCodec::parseEvents($this->vevent("UID:x\r\nDTSTART:20261020T100000Z\r\nDTEND:20261020T093000Z\r\n")));
	}

	public function testAnOldDailyEventStillCountsToday(): void {
		$this->requireSabre();
		$events = IcalCodec::parseEvents($this->vevent("UID:lunch\r\nDTSTART:20160104T120000Z\r\nDTEND:20160104T130000Z\r\nRRULE:FREQ=DAILY\r\n"),
			new \DateTimeImmutable('2026-10-19T00:00:00Z'), new \DateTimeImmutable('2026-10-21T00:00:00Z'));
		$this->assertCount(2, $events);
		$this->assertSame('2026-10-19 12:00', $events[0]->start->format('Y-m-d H:i'));
	}

	public function testOverriddenOccurrencesOfARecurringTaskAreNotTasks(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
			. "BEGIN:VTODO\r\nUID:r\r\nSUMMARY:Weekly report\r\nDTSTART:20261012T090000Z\r\nRRULE:FREQ=WEEKLY\r\nCATEGORIES:calplan\r\nEND:VTODO\r\n"
			. "BEGIN:VTODO\r\nUID:r\r\nRECURRENCE-ID:20261019T090000Z\r\nSUMMARY:Weekly report (moved)\r\nCATEGORIES:calplan\r\nEND:VTODO\r\n"
			. "END:VCALENDAR\r\n";
		$tasks = IcalCodec::parseTasks($ics);
		$this->assertCount(1, $tasks);
		$this->assertSame('Weekly report', $tasks[0]->title);
		// The categories are changed on the master component.
		$this->assertStringContainsString("SUMMARY:Weekly report\r\nDTSTART:20261012T090000Z\r\nRRULE:FREQ=WEEKLY\r\nCATEGORIES:calplan,calplan-review", IcalCodec::replaceTaskCategories($ics, ['calplan', 'calplan-review']));
	}

	public function testTheFlagIsMatchedLikeTheTasksUi(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:c\r\nSUMMARY:C\r\nCATEGORIES:work, CalPlan\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$task = IcalCodec::parseTasks($ics)[0];
		$this->assertSame(['work', 'CalPlan'], $task->categories);
		$this->assertTrue($task->autoSchedule);
		$this->assertSame(['work'], array_values(array_filter($task->reviewCategories(false), fn ($c) => $c !== Defaults::REVIEW_CATEGORY)));
	}

	public function testABlockRoundTripsUnchanged(): void {
		$this->requireSabre();
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$task = new \OCA\AutoSchedule\Model\Task(id: 'T', title: 'Visit', priority: 3, url: 'https://en.wikipedia.org/wiki/Washington,_D.C.',
			location: '0', description: "line one\r\nline two", classification: 'PRIVATE');
		$ics = IcalCodec::buildSlotEvent($task, $now->modify('+1 hour'), $now->modify('+2 hours'), $now);
		$this->assertStringContainsString("CLASS:PRIVATE\r\n", $ics);
		$event = IcalCodec::parseEvents($ics)[0];
		$expected = IcalCodec::slotContent($task, $now);
		$this->assertSame($expected['url'], $event->url);
		$this->assertSame($expected['location'], $event->location);
		$this->assertSame($expected['description'], $event->description);
		$this->assertSame($expected['classification'], $event->classification);
	}

	public function testATaskTitledZeroKeepsItsTitle(): void {
		$this->requireSabre();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:z\r\nSUMMARY:0\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->assertSame('0', IcalCodec::parseTasks($ics)[0]->title);
	}

	public function testANotesDurationIsReadOnItsOwnLineOnly(): void {
		$this->requireSabre();
		$tasks = IcalCodec::parseTasks($this->vtodo("DESCRIPTION:Duration:\\n45min\r\n"));
		$this->assertSame(Defaults::DEFAULT_ESTIMATE_MINUTES, $tasks[0]->estimatedMinutes);
	}
}
