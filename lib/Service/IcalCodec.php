<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure iCalendar <-> model codec using the Sabre\VObject library bundled with
 * Nextcloud. Tasks are flagged via the standard CATEGORIES value `calplan`;
 * a task's own settings use ESTIMATED-DURATION (draft-ietf-calext-ical-tasks)
 * and a few X-CALPLAN- properties (daily limits, "split if needed"). The
 * working-slot VEVENTs we create carry UID `calplan-<block key>`, RELATED-TO
 * back to the VTODO, CATEGORIES `calplan-slot` and, for a part of a split
 * task, X-CALPLAN-PART.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Task;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;
use Sabre\VObject\Settings;

class IcalCodec {
	/**
	 * Most occurrences of one recurring event Sabre may walk through. Sabre
	 * counts from the first occurrence, so its default (3500) silently drops
	 * a daily event that started about ten years ago.
	 */
	private const MAX_RECURRENCES = 50000;

	/**
	 * Parse VTODO components from an iCalendar blob into Task objects.
	 *
	 * All-day (DATE) and floating values are read in *tz*, the user's
	 * timezone: a DUE of 2026-10-21 is that day's midnight for the user. A
	 * recurring task is not expanded: only its master component counts, not
	 * the overridden occurrences (RECURRENCE-ID) a client may store with it.
	 *
	 * @return Task[]
	 */
	public static function parseTasks(string $ics, int $defaultEstimateMinutes = Defaults::DEFAULT_ESTIMATE_MINUTES, ?\DateTimeZone $tz = null): array {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			return [];
		}
		$tasks = [];
		foreach ($vcal->select('VTODO') as $vtodo) {
			$uid = self::stringProp($vtodo, 'UID');
			if ($uid === '' || isset($vtodo->{'RECURRENCE-ID'})) {
				continue;
			}
			$title = self::nonEmpty(self::stringProp($vtodo, 'SUMMARY')) ?? $uid;
			$priorityRaw = self::stringProp($vtodo, 'PRIORITY');
			// RFC 5545: an absent PRIORITY means "unspecified" (no priority), NOT
			// medium (5). The Tasks app writes no PRIORITY unless the user sets
			// stars, so absent -> 0 keeps "no priority" tasks out of the
			// Priority:/!-mark output (plan 0.9.1). Scheduling weight is preserved
			// (priorityWeight(0) == priorityWeight(5) == 1.0).
			$priority = $priorityRaw !== '' ? (int)$priorityRaw : 0;
			$due = self::dateTimeProp($vtodo, 'DUE', $tz);
			$start = self::dateTimeProp($vtodo, 'DTSTART', $tz);
			// An all-day start (VALUE=DATE) means "from that day", not a time.
			$startAllDay = $start !== null && self::isDateOnly($vtodo, 'DTSTART');
			$status = strtoupper(self::nonEmpty(self::stringProp($vtodo, 'STATUS')) ?? 'NEEDS-ACTION');
			$categories = self::categories($vtodo);
			$description = self::nonEmpty(self::stringProp($vtodo, 'DESCRIPTION'));
			// ESTIMATED-DURATION (draft-ietf-calext-ical-tasks) is the task's
			// own effort estimate, written by the Tasks sidebar Duration field.
			// Then the standard RFC 5545 DURATION, then a simple
			// `Duration: 15min` notes line for clients without the field,
			// before the 30-minute default.
			$estimatedMinutes = self::durationToMinutes(self::stringProp($vtodo, 'ESTIMATED-DURATION'));
			if ($estimatedMinutes <= 0) {
				$durationRaw = self::stringProp($vtodo, 'DURATION');
				$estimatedMinutes = $durationRaw !== ''
					? self::durationToMinutes($durationRaw)
					: self::notesDurationToMinutes($description ?? '');
			}
			if ($estimatedMinutes <= 0) {
				$estimatedMinutes = $defaultEstimateMinutes;
			}
			$percentRaw = self::stringProp($vtodo, 'PERCENT-COMPLETE');
			// Per-day limits for this task and its subtasks, set in the Tasks
			// sidebar: a DURATION value and a block count (absent/invalid = none).
			$limitBlocksRaw = trim(self::stringProp($vtodo, Defaults::DAILY_MAX_BLOCKS_PROP));
			// Manual order among siblings, as Nextcloud Tasks keeps it: the Apple
			// X-APPLE-SORT-ORDER, defaulting to seconds since 2001-01-01 of CREATED.
			$sortRaw = self::stringProp($vtodo, 'X-APPLE-SORT-ORDER');
			$created = self::dateTimeProp($vtodo, 'CREATED', $tz);
			$tasks[] = new Task(
				id: $uid,
				title: $title,
				priority: $priority,
				due: $due,
				start: $start,
				estimatedMinutes: $estimatedMinutes,
				autoSchedule: Task::hasCategory($categories, Defaults::FLAG_CATEGORY),
				status: $status,
				description: $description,
				location: self::nonEmpty(self::stringProp($vtodo, 'LOCATION')),
				url: self::nonEmpty(self::stringProp($vtodo, 'URL')),
				percentComplete: $percentRaw !== '' ? (int)$percentRaw : null,
				// Parent/child: the Tasks app marks a child VTODO with
				// RELATED-TO;RELTYPE=PARENT:<parent-uid> (a RELATED-TO with no
				// RELTYPE is also a parent link). The relationships are resolved
				// across the whole calendar in TaskLimits::resolve (plan 0.9.1).
				parentUid: self::relatedToParent($vtodo),
				categories: $categories,
				dailyLimitMinutes: self::durationToMinutes(self::stringProp($vtodo, Defaults::DAILY_MAX_TIME_PROP)),
				dailyLimitBlocks: ctype_digit($limitBlocksRaw) ? (int)$limitBlocksRaw : 0,
				splittable: in_array(strtoupper(trim(self::stringProp($vtodo, Defaults::SPLITTABLE_PROP))), ['TRUE', 'YES', '1'], true),
				sortOrder: preg_match('/^-?\d+$/', trim($sortRaw)) === 1
					? (int)trim($sortRaw)
					: ($created !== null ? $created->getTimestamp() - 978307200 : 0),
				startAllDay: $startAllDay,
				classification: self::nonEmpty(strtoupper(trim(self::stringProp($vtodo, 'CLASS')))),
			);
		}
		return $tasks;
	}

	/**
	 * Parse VEVENT components from an iCalendar blob into CalendarEvent objects.
	 *
	 * With a [$from, $until] window, recurring events (RRULE/RDATE) are
	 * expanded into their occurrences inside it (EXDATEs and overridden
	 * instances honoured): the stored VEVENT only carries the first
	 * occurrence, so without expansion a daily lunch would count as busy on
	 * its first date only. All-day and floating times are read in *tz*, the
	 * user's timezone. An event without DTEND lasts its DURATION, or a whole
	 * day when it is all-day (RFC 5545 3.6.1).
	 *
	 * @return CalendarEvent[]
	 */
	public static function parseEvents(string $ics, ?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null, ?\DateTimeZone $tz = null, ?string $objectUri = null): array {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			return [];
		}
		if ($from !== null && $until !== null && self::hasRecurringEvent($vcal)) {
			$maxRecurrences = Settings::$maxRecurrences;
			Settings::$maxRecurrences = max($maxRecurrences, self::MAX_RECURRENCES);
			try {
				$vcal = $vcal->expand($from, $until, $tz);
			} catch (\Throwable $_) {
				// No instances in range, or too many: keep the unexpanded master.
			} finally {
				Settings::$maxRecurrences = $maxRecurrences;
			}
		}
		$events = [];
		foreach ($vcal->select('VEVENT') as $vevent) {
			$uid = self::stringProp($vevent, 'UID');
			if ($uid === '') {
				continue;
			}
			$start = self::dateTimeProp($vevent, 'DTSTART', $tz);
			if ($start === null) {
				continue;
			}
			$end = self::eventEnd($vevent, $start, $tz);
			if ($end < $start) {
				continue; // a reversed event holds no time
			}
			$categories = self::categories($vevent);
			$relatedTaskUid = self::relatedToTodo($vevent);
			$isOwnedSlot = str_starts_with($uid, Defaults::SLOT_UID_PREFIX)
				&& (Task::hasCategory($categories, Defaults::SLOT_CATEGORY) || $relatedTaskUid !== null);
			$autoTaskUid = $isOwnedSlot
				? ($relatedTaskUid ?? self::taskUidOfSlotKey(substr($uid, strlen(Defaults::SLOT_UID_PREFIX))))
				: null;
			// Read the mirrored content back from our own slots so the
			// reconciler can detect content-only edits (plan 1.5.11). The
			// raw DESCRIPTION (incl. the % prefix) is compared to slotContent().
			$transpRaw = self::stringProp($vevent, 'TRANSP');
			$transp = $transpRaw !== '' ? strtoupper(trim($transpRaw)) : null;
			// Ordinary event summaries are needed for the user's busy-title
			// exclusions. Other mirrored content is read only for owned slots.
			$summary = self::nonEmpty(self::stringProp($vevent, 'SUMMARY'));
			$description = null;
			$location = null;
			$url = null;
			$priority = null;
			$classification = null;
			if ($autoTaskUid !== null) {
				$description = self::nonEmpty(self::stringProp($vevent, 'DESCRIPTION'));
				$location = self::nonEmpty(self::stringProp($vevent, 'LOCATION'));
				$url = self::nonEmpty(self::stringProp($vevent, 'URL'));
				$priRaw = self::stringProp($vevent, 'PRIORITY');
				$priority = $priRaw !== '' ? (int)$priRaw : null;
				$classification = self::nonEmpty(strtoupper(trim(self::stringProp($vevent, 'CLASS'))));
			}
			$events[] = new CalendarEvent($uid, $start, $end, $autoTaskUid, $summary, $description, $location, $url, $priority, transp: $transp, objectUri: $objectUri, classification: $classification);
		}
		return $events;
	}

	/** When *vevent* ends: DTEND, else DTSTART + DURATION, else the next day for an all-day event, else its start. */
	private static function eventEnd(Component $vevent, \DateTimeImmutable $start, ?\DateTimeZone $tz): \DateTimeImmutable {
		$end = self::dateTimeProp($vevent, 'DTEND', $tz);
		if ($end !== null) {
			return $end;
		}
		$duration = self::stringProp($vevent, 'DURATION');
		if ($duration !== '') {
			try {
				return $start->add(DateTimeParser::parseDuration($duration));
			} catch (\Throwable $_) {
				// An invalid DURATION: treat the event as instantaneous.
			}
		}
		return self::isDateOnly($vevent, 'DTSTART') ? $start->modify('+1 day') : $start;
	}

	/** The block key of a task's block: the task UID, or "<uid>~p<n>" for part n of a split task. */
	public static function slotKey(string $taskUid, ?int $part = null): string {
		return $part === null ? $taskUid : $taskUid . Defaults::SLOT_PART_SEPARATOR . $part;
	}

	/** The task UID a block key belongs to. */
	public static function taskUidOfSlotKey(string $key): string {
		return preg_replace('#' . preg_quote(Defaults::SLOT_PART_SEPARATOR, '#') . '\d+$#', '', $key) ?? $key;
	}

	/**
	 * The mirrored content a working-slot VEVENT carries for $task, exactly as
	 * the reconciler expects to find it on an existing slot. Pure and
	 * Sabre-independent, so it drives both buildSlotEvent (write) and the
	 * reconciler content-change detection (compare). A null value means the
	 * property is omitted from the VEVENT. Values are normalised the way they
	 * survive a write and a read: line breaks as LF, commas in the URL
	 * percent-encoded (Sabre escapes a URL's commas on write but does not
	 * unescape them on read), so an unchanged block is never rewritten.
	 *
	 * Two render modes (plan 0.9.1):
	 *  - $now === null  -> legacy/pure-test callers: raw title, simple % prefix.
	 *  - $now provided  -> live slot: the SUMMARY carries a lock marker and a
	 *    single '!' when the task is overdue (the relation marker, priority
	 *    marks and ⏰ glyph moved into the DESCRIPTION in 0.9.2 to keep the
	 *    title visible in narrow day-view blocks); the DESCRIPTION is a
	 *    structured block (---Task--- / ---Notes---). See the helper docblocks.
	 *
	 * @return array{summary:string,description:?string,location:?string,url:?string,priority:?int,classification:?string}
	 */
	public static function slotContent(Task $task, ?\DateTimeImmutable $now = null, ?int $part = null, ?int $partCount = null): array {
		// A part of a split task ends its SUMMARY with "(n/count)": short enough
		// for Calendar's narrow blocks, and the title stays first.
		$partLabel = $part !== null && $partCount !== null ? ' (' . $part . '/' . $partCount . ')' : '';
		$common = [
			'location' => self::nonEmpty(self::lineBreaks($task->location)),
			'url' => self::nonEmpty(str_replace(',', '%2C', (string)$task->url)),
			'priority' => $task->priority > 0 ? $task->priority : null,
			// A private or confidential task's blocks are hidden from sharees
			// like the task itself (Nextcloud filters by CLASS).
			'classification' => in_array($task->classification, ['PRIVATE', 'CONFIDENTIAL'], true) ? $task->classification : null,
		];
		if ($now === null) {
			// Legacy/pure-test path: raw title, simple % prefix (unchanged).
			$desc = $task->description ?? '';
			if ($task->percentComplete !== null && $task->percentComplete > 0) {
				$desc = $task->percentComplete . '% - ' . $desc;
			}
			return ['summary' => self::lineBreaks($task->title) . $partLabel, 'description' => self::nonEmpty(self::lineBreaks($desc))] + $common;
		}

		// Live slot SUMMARY: <lock> [! if overdue] title. The relation marker,
		// priority marks (!/!!/!!!) and the overdue ⏰ glyph used to live inline
		// here (plan 0.9.1), but in Calendar's narrow day-view blocks they ate
		// 55-65% of the width and clipped the title to nothing (plan 0.9.2). They
		// moved into the structured DESCRIPTION below; only a single '!' glance
		// cue for an overdue task stays in the SUMMARY.
		$summary = '🔒 '
			. (self::isUrgent($task, $now) ? '! ' : '')
			. self::lineBreaks($task->title)
			. $partLabel;
		$description = self::structuredDescription($task, $now);
		return ['summary' => $summary, 'description' => $description !== null ? self::lineBreaks($description) : null] + $common;
	}

	/**
	 * The structured DESCRIPTION block a live working-slot carries (plan 0.9.2):
	 *   ---Task---
	 *   Title: <the task title>   (always present -- survives narrow day-view clipping)
	 *   Completion: 60%
	 *   Priority: 3
	 *   Status: ⏰ Overdue
	 *   Subtasks: ↴ <titles>
	 *   Parent task: ↳ <top task> › … › <parent>
	 *   Link: https://ex.test/t
	 *   👣 First step: <promoted from `First step:` or legacy `Next:` notes>
	 *
	 *   ---Notes---
	 *   <the task's notes>
	 * The Calendar popover renders DESCRIPTION as plain text with URLs
	 * auto-linked (v-linkify, white-space: pre-wrap), so the headers survive
	 * literally and the Link: line becomes clickable. Each metadata line is
	 * emitted only when that field is present; the overdue ⏰ and the subtask
	 * ↴/↳ markers live here (moved out of the SUMMARY in 0.9.2 so the narrow
	 * day-view block keeps room for the title). Each section header is emitted
	 * only when its section has content. The Title: line always leads the
	 * ---Task--- section (0.9.6), so the block is never empty for a real task.
	 */
	public static function structuredDescription(Task $task, ?\DateTimeImmutable $now = null): ?string {
		// Title always leads (0.9.6): the full title stays readable even when the
		// narrow day-view block clips the SUMMARY.
		$meta = ['Title: ' . $task->title];
		if ($task->percentComplete !== null && $task->percentComplete > 0) {
			$meta[] = 'Completion: ' . $task->percentComplete . '%';
		}
		if ($task->priority > 0) {
			$meta[] = 'Priority: ' . $task->priority;
		}
		// Overdue ⏰ moved here from the SUMMARY (0.9.2): the single '!' glance
		// cue stays in the SUMMARY; the ⏰ detail lives here. Needs $now.
		if ($now !== null && self::isUrgent($task, $now)) {
			$meta[] = 'Status: ⏰ Overdue';
		}
		// Subtask relations moved here from the SUMMARY (0.9.2).
		if ($task->childTitles !== []) {
			$visible = array_slice($task->childTitles, 0, 5);
			$line = 'Subtasks: ' . implode('; ', array_map(fn (string $title) => '↴ ' . $title, $visible));
			$remaining = count($task->childTitles) - count($visible);
			if ($remaining > 0) {
				$line .= '; +' . $remaining . ' more';
			}
			$meta[] = $line;
		}
		if ($task->ancestorTitles !== []) {
			// The whole chain, top task first: "Course › Module".
			$meta[] = 'Parent task: ↳ ' . implode(' › ', array_reverse($task->ancestorTitles));
		} elseif ($task->parentUid !== null) {
			// The parent is not in this calendar.
			$meta[] = 'Parent task: ↳';
		}
		if ($task->url !== null && $task->url !== '') {
			$meta[] = 'Link: ' . $task->url;
		}
		// Preferred `First step:` note helper, with legacy `Next:` compatibility.
		// It is display-only: no effect on duration, ranking or placement.
		$firstStep = self::firstStep($task->description);
		if ($firstStep !== null) {
			$meta[] = '👣 First step: ' . $firstStep;
		}
		$taskBlock = "---Task---\n" . implode("\n", $meta);
		$notes = self::notesWithoutFirstStep($task->description);
		return $notes !== '' ? $taskBlock . "\n\n---Notes---\n" . $notes : $taskBlock;
	}

	/**
	 * The first preferred `First step:` line, or legacy `Next:` line, with its
	 * prefix stripped. Preferred syntax wins when both are present.
	 * Pure and Sabre-independent so it can be unit-tested directly.
	 */
	public static function firstStep(?string $description): ?string {
		if ($description === null || $description === '') {
			return null;
		}
		if (preg_match('/^[ \t]*First[ \t]+step:[ \t]*(.+?)[ \t]*$/im', $description, $m)) {
			return $m[1];
		}
		if (preg_match('/^[ \t]*Next:[ \t]*(.+?)[ \t]*$/im', $description, $m)) {
			return $m[1];
		}
		return null;
	}

	/** @deprecated Use firstStep(); retained for source compatibility. */
	public static function nextAction(?string $description): ?string {
		return self::firstStep($description);
	}

	/**
	 * Task notes with `First step:` and legacy `Next:` helper lines removed so
	 * the promoted step is not duplicated in ---Notes---.
	 */
	public static function notesWithoutFirstStep(?string $description): string {
		if ($description === null || $description === '') {
			return '';
		}
		if (self::firstStep($description) === null) {
			return $description;
		}
		$without = preg_replace('/^[ \t]*(?:First[ \t]+step|Next):[ \t]*.*\r?\n?/im', '', $description);
		$without = preg_replace('/\n{3,}/', "\n\n", $without);
		return trim($without, "\n");
	}

	/** @deprecated Use notesWithoutFirstStep(); retained for source compatibility. */
	public static function notesWithoutNextAction(?string $description): string {
		return self::notesWithoutFirstStep($description);
	}

	/** A task is "already late" when its DTSTART or DUE has passed. */
	private static function isUrgent(Task $task, \DateTimeImmutable $now): bool {
		// A subtask is late only when its tree's deadline (the top task's) is.
		$due = $task->schedulingDue();
		return ($task->start !== null && $task->start <= $now)
			|| ($due !== null && $due <= $now);
	}

	/**
	 * The first RELATED-TO that links this VTODO to a PARENT, or null. The Tasks
	 * app treats a RELATED-TO with no RELTYPE as a parent link too
	 * (src/models/task.js getParent()). RELTYPE=CHILD/SIBLING are skipped, so a
	 * parent listing its children via RELATED-TO;RELTYPE=CHILD is not misread as
	 * being a child itself. Sabre Property ArrayAccess reads the RELTYPE
	 * parameter (offsetExists / offsetGet -> null when absent).
	 */
	private static function relatedToParent(Component $comp): ?string {
		foreach ($comp->select('RELATED-TO') as $p) {
			$reltype = null;
			if (isset($p['RELTYPE'])) {
				$reltype = strtoupper((string)$p['RELTYPE']);
			}
			if ($reltype !== null && $reltype !== 'PARENT') {
				continue;
			}
			$val = trim((string)$p->getValue());
			return $val !== '' ? $val : null;
		}
		return null;
	}

	/** The first RELATED-TO;RELTYPE=TODO link on a derived VEVENT. */
	private static function relatedToTodo(Component $comp): ?string {
		foreach ($comp->select('RELATED-TO') as $property) {
			$reltype = isset($property['RELTYPE']) ? strtoupper((string)$property['RELTYPE']) : '';
			if ($reltype !== 'TODO') {
				continue;
			}
			$value = (string)$property->getValue();
			return $value !== '' ? $value : null;
		}
		return null;
	}

	/**
	 * Replace the categories of the task in *ics* (its master component, not
	 * an overridden occurrence).
	 *
	 * @param string[] $categories
	 */
	public static function replaceTaskCategories(string $ics, array $categories): string {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			throw new \InvalidArgumentException('Expected a VCALENDAR');
		}
		$vtodo = null;
		foreach ($vcal->select('VTODO') as $candidate) {
			if (!isset($candidate->{'RECURRENCE-ID'})) {
				$vtodo = $candidate;
				break;
			}
		}
		if ($vtodo === null) {
			throw new \InvalidArgumentException('Expected a VTODO');
		}
		$vtodo->remove('CATEGORIES');
		$categories = array_values(array_unique(array_filter(array_map(fn ($value) => trim((string)$value), $categories), fn (string $value) => $value !== '')));
		if ($categories !== []) {
			$vtodo->add('CATEGORIES', $categories);
		}
		return $vcal->serialize();
	}

	/**
	 * The block for *task*, or for part *part* of *partCount* when the task is
	 * split. Its times are written in UTC: a TZID would need a VTIMEZONE
	 * definition (RFC 5545 3.2.19), which clients without that zone's rules
	 * cannot do without.
	 */
	public static function buildSlotEvent(Task $task, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $now = null, ?int $part = null, ?int $partCount = null): string {
		$utc = new \DateTimeZone('UTC');
		$vcal = new VCalendar();
		$vcal->PRODID = '-//Nextcloud//CalPlan//EN';
		$vevent = $vcal->createComponent('VEVENT');
		$vevent->UID = Defaults::SLOT_UID_PREFIX . self::slotKey($task->id, $part);
		if ($part !== null && $partCount !== null) {
			$vevent->add(Defaults::SLOT_PART_PROP, $part . '/' . $partCount);
		}
		$vevent->DTSTART = $start->setTimezone($utc);
		$vevent->DTEND = $end->setTimezone($utc);
		$vevent->STATUS = 'CONFIRMED';
		// Mirror the task's rich content via the shared slotContent() helper so
		// the reconciler can detect content-only edits (plan 1.5.11). The slot
		// stays a derived, one-way artifact: edits in Calendar are overwritten on
		// the next reconcile. PERCENT-COMPLETE is VTODO-only in strict RFC 5545,
		// so it is folded into the DESCRIPTION prefix, not emitted on the VEVENT.
		// The SUMMARY carries the lock/urgent prefix from slotContent (1.6.3).
		$content = self::slotContent($task, $now, $part, $partCount);
		$vevent->SUMMARY = $content['summary'];
		if ($content['priority'] !== null) {
			$vevent->PRIORITY = $content['priority'];
		}
		if ($content['description'] !== null) {
			$vevent->DESCRIPTION = $content['description'];
		}
		if ($content['location'] !== null) {
			$vevent->LOCATION = $content['location'];
		}
		if ($content['url'] !== null) {
			$vevent->URL = $content['url'];
		}
		if ($content['classification'] !== null) {
			$vevent->CLASS = $content['classification'];
		}
		// Standard link back to the originating VTODO.
		$rel = $vcal->createProperty('RELATED-TO', $task->id);
		$rel['RELTYPE'] = 'TODO';
		$vevent->add($rel);
		$vevent->add('CATEGORIES', Defaults::SLOT_CATEGORY);
		$vcal->add($vevent);
		return $vcal->serialize();
	}

	private static function hasRecurringEvent(VCalendar $vcal): bool {
		foreach ($vcal->select('VEVENT') as $vevent) {
			if (isset($vevent->RRULE) || isset($vevent->RDATE)) {
				return true;
			}
		}
		return false;
	}

	private static function stringProp(Component $comp, string $name): string {
		foreach ($comp->select($name) as $p) {
			return (string)$p->getValue();
		}
		return '';
	}

	/** *value*, or null when it is empty (a value of "0" is kept). */
	private static function nonEmpty(?string $value): ?string {
		return $value !== null && $value !== '' ? $value : null;
	}

	/** *text* with CRLF and lone CR line breaks as LF, as they read back after a write. */
	private static function lineBreaks(?string $text): ?string {
		return $text === null ? null : str_replace(["\r\n", "\r"], "\n", $text);
	}

	/** Whether the first *name* property is a DATE (all-day) value. */
	private static function isDateOnly(Component $comp, string $name): bool {
		foreach ($comp->select($name) as $p) {
			return method_exists($p, 'hasTime') && !$p->hasTime();
		}
		return false;
	}

	/** The first *name* property as a time; DATE and floating values are taken in *tz*. */
	private static function dateTimeProp(Component $comp, string $name, ?\DateTimeZone $tz = null): ?\DateTimeImmutable {
		foreach ($comp->select($name) as $p) {
			if (!method_exists($p, 'getDateTime')) {
				return null;
			}
			$dt = $p->getDateTime($tz);
			if ($dt instanceof \DateTimeImmutable) {
				return $dt;
			}
			if ($dt instanceof \DateTime) {
				return \DateTimeImmutable::createFromMutable($dt);
			}
		}
		return null;
	}

	/** @return string[] the CATEGORIES values, trimmed ("work, calplan" gives "work" and "calplan") */
	private static function categories(Component $comp): array {
		$cats = [];
		foreach ($comp->select('CATEGORIES') as $p) {
			foreach ($p->getParts() as $part) {
				$part = trim((string)$part);
				if ($part !== '') {
					$cats[] = $part;
				}
			}
		}
		return $cats;
	}

	/**
	 * Read the first standalone `Duration:` line from task notes.
	 *
	 * Accepted examples: 15min, 10 min, 90m, 1hr, 1 hour 30 minutes.
	 * Returns 0 when absent or invalid so callers can use the default estimate.
	 */
	public static function notesDurationToMinutes(string $notes): int {
		if (!preg_match('/^[ \t]*Duration[ \t]*:[ \t]*(.*?)[ \t]*$/mi', $notes, $line)) {
			return 0;
		}
		$value = trim($line[1]);
		if ($value === '' || !preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours))?(?:\s*(\d+(?:\.\d+)?)\s*(?:m|min|mins|minute|minutes))?$/i', $value, $parts)) {
			return 0;
		}
		$hours = isset($parts[1]) && $parts[1] !== '' ? (float)$parts[1] : 0.0;
		$minutes = isset($parts[2]) && $parts[2] !== '' ? (float)$parts[2] : 0.0;
		$total = (int)round($hours * 60 + $minutes);
		return $total > 0 ? $total : 0;
	}

	/**
	 * Parse an RFC 5545 DURATION value (e.g. "PT45M", "PT1H30M", "PT1H", "P1D")
	 * into a whole number of minutes. Returns 0 for an empty, unparseable or
	 * negative value, so the caller can fall back to the default estimate.
	 * Case-insensitive, as RFC 5545 values are. Pure and Sabre-independent so
	 * it can be unit-tested without the VObject library.
	 */
	public static function durationToMinutes(string $raw): int {
		$s = strtoupper(trim($raw));
		if (str_starts_with($s, '-')) {
			return 0;
		}
		$s = ltrim($s, '+');
		if ($s === '' || !preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $s, $m)) {
			return 0;
		}
		$n = fn (int $i): int => isset($m[$i]) && $m[$i] !== '' ? (int)$m[$i] : 0;
		return $n(1) * 7 * 24 * 60 + $n(2) * 24 * 60 + $n(3) * 60 + $n(4) + intdiv($n(5) + 30, 60);
	}
}
