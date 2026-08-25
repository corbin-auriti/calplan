<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure iCalendar <-> model codec using the Sabre\VObject library bundled with
 * Nextcloud. No custom X- properties: tasks are flagged via the standard
 * CATEGORIES value `calplan`, and the working-slot VEVENTs we create
 * carry only standard properties (UID `calplan-<taskUID>`, RELATED-TO back to
 * the VTODO, CATEGORIES `calplan-slot`).
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\CalendarEvent;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\Task;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class IcalCodec {
	/**
	 * Parse VTODO components from an iCalendar blob into Task objects.
	 *
	 * @return Task[]
	 */
	public static function parseTasks(string $ics, int $defaultEstimateMinutes = Defaults::DEFAULT_ESTIMATE_MINUTES): array {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			return [];
		}
		$tasks = [];
		foreach ($vcal->select('VTODO') as $vtodo) {
			$uid = self::stringProp($vtodo, 'UID');
			if ($uid === '') {
				continue;
			}
			$title = self::stringProp($vtodo, 'SUMMARY') ?: $uid;
			$priorityRaw = self::stringProp($vtodo, 'PRIORITY');
			// RFC 5545: an absent PRIORITY means "unspecified" (no priority), NOT
			// medium (5). The Tasks app writes no PRIORITY unless the user sets
			// stars, so absent -> 0 keeps "no priority" tasks out of the
			// Priority:/!-mark output (plan 0.9.1). Scheduling weight is preserved
			// (priorityWeight(0) == priorityWeight(5) == 1.0).
			$priority = $priorityRaw !== '' ? (int)$priorityRaw : 0;
			$due = self::dateTimeProp($vtodo, 'DUE');
			$start = self::dateTimeProp($vtodo, 'DTSTART');
			$status = strtoupper(self::stringProp($vtodo, 'STATUS') ?: 'NEEDS-ACTION');
			$categories = self::categories($vtodo);
			$autoSchedule = in_array(Defaults::FLAG_CATEGORY, $categories, true);
			$description = self::stringProp($vtodo, 'DESCRIPTION') ?: null;
			$durationRaw = self::stringProp($vtodo, 'DURATION');
			// Prefer the standard RFC 5545 property. Nextcloud Tasks has no
			// duration field, so a simple `Duration: 15min` notes line is the
			// user-friendly fallback before the 30-minute default.
			$estimatedMinutes = $durationRaw !== ''
				? self::durationToMinutes($durationRaw)
				: self::notesDurationToMinutes($description ?? '');
			if ($estimatedMinutes <= 0) {
				$estimatedMinutes = $defaultEstimateMinutes;
			}
			$location = self::stringProp($vtodo, 'LOCATION') ?: null;
			$url = self::stringProp($vtodo, 'URL') ?: null;
			$percentRaw = self::stringProp($vtodo, 'PERCENT-COMPLETE');
			$percentComplete = $percentRaw !== '' ? (int)$percentRaw : null;
			// Parent/child: the Tasks app marks a child VTODO with
			// RELATED-TO;RELTYPE=PARENT:<parent-uid> (a RELATED-TO with no RELTYPE
			// is also a parent link). "Has children" is resolved across the whole
			// calendar in CalendarRepository::getTasks (plan 0.9.1).
			$parentUid = self::relatedToParent($vtodo);
			$tasks[] = new Task(
				$uid,
				$title,
				$priority,
				$due,
				$start,
				$estimatedMinutes,
				$autoSchedule,
				$status,
				$description,
				$location,
				$url,
				$percentComplete,
				null,
				null,
				$parentUid,
				false,
				null,
				[],
				null,
				$categories,
			);
		}
		return $tasks;
	}

	/**
	 * Parse VEVENT components from an iCalendar blob into CalendarEvent objects.
	 *
	 * @return CalendarEvent[]
	 */
	public static function parseEvents(string $ics): array {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			return [];
		}
		$events = [];
		foreach ($vcal->select('VEVENT') as $vevent) {
			$uid = self::stringProp($vevent, 'UID');
			if ($uid === '') {
				continue;
			}
			$start = self::dateTimeProp($vevent, 'DTSTART');
			if ($start === null) {
				continue;
			}
			$end = self::dateTimeProp($vevent, 'DTEND') ?? $start;
			$categories = self::categories($vevent);
			$relatedTaskUid = self::relatedToTodo($vevent);
			$isOwnedSlot = str_starts_with($uid, Defaults::SLOT_UID_PREFIX)
				&& (in_array(Defaults::SLOT_CATEGORY, $categories, true) || $relatedTaskUid !== null);
			$autoTaskUid = $isOwnedSlot
				? ($relatedTaskUid ?? substr($uid, strlen(Defaults::SLOT_UID_PREFIX)))
				: null;
			// Read the mirrored content back from our own slots so the
			// reconciler can detect content-only edits (plan 1.5.11). The
			// raw DESCRIPTION (incl. the % prefix) is compared to slotContent().
			$transpRaw = self::stringProp($vevent, 'TRANSP');
			$transp = $transpRaw !== '' ? strtoupper($transpRaw) : null;
			// Ordinary event summaries are needed for the user's busy-title
			// exclusions. Other mirrored content is read only for owned slots.
			$summary = self::stringProp($vevent, 'SUMMARY') ?: null;
			$description = null;
			$location = null;
			$url = null;
			$priority = null;
			if ($autoTaskUid !== null) {
				$description = self::stringProp($vevent, 'DESCRIPTION') ?: null;
				$location = self::stringProp($vevent, 'LOCATION') ?: null;
				$url = self::stringProp($vevent, 'URL') ?: null;
				$priRaw = self::stringProp($vevent, 'PRIORITY');
				$priority = $priRaw !== '' ? (int)$priRaw : null;
			}
			$events[] = new CalendarEvent($uid, $start, $end, $autoTaskUid, $summary, $description, $location, $url, $priority, transp: $transp);
		}
		return $events;
	}

	/**
	 * The mirrored content a working-slot VEVENT carries for $task, exactly as
	 * the reconciler expects to find it on an existing slot. Pure and
	 * Sabre-independent, so it drives both buildSlotEvent (write) and the
	 * reconciler content-change detection (compare). A null value means the
	 * property is omitted from the VEVENT.
	 *
	 * Two render modes (plan 0.9.1):
	 *  - $now === null  -> legacy/pure-test callers: raw title, simple % prefix.
	 *  - $now provided  -> live slot: the SUMMARY carries a lock marker and a
	 *    single '!' when the task is overdue (the relation marker, priority
	 *    marks and ⏰ glyph moved into the DESCRIPTION in 0.9.2 to keep the
	 *    title visible in narrow day-view blocks); the DESCRIPTION is a
	 *    structured block (---Task--- / ---Notes---). See the helper docblocks.
	 *
	 * @return array{summary:string,description:?string,location:?string,url:?string,priority:?int}
	 */
	public static function slotContent(Task $task, ?\DateTimeImmutable $now = null): array {
		if ($now === null) {
			// Legacy/pure-test path: raw title, simple % prefix (unchanged).
			$desc = $task->description ?? '';
			if ($task->percentComplete !== null && $task->percentComplete > 0) {
				$desc = $task->percentComplete . '% - ' . $desc;
			}
			return [
				'summary' => $task->title,
				'description' => $desc !== '' ? $desc : null,
				'location' => ($task->location !== null && $task->location !== '') ? $task->location : null,
				'url' => ($task->url !== null && $task->url !== '') ? $task->url : null,
				'priority' => $task->priority > 0 ? $task->priority : null,
			];
		}

		// Live slot SUMMARY: <lock> [! if overdue] title. The relation marker,
		// priority marks (!/!!/!!!) and the overdue ⏰ glyph used to live inline
		// here (plan 0.9.1), but in Calendar's narrow day-view blocks they ate
		// 55-65% of the width and clipped the title to nothing (plan 0.9.2). They
		// moved into the structured DESCRIPTION below; only a single '!' glance
		// cue for an overdue task stays in the SUMMARY.
		$summary = '🔒 '
			. (self::isUrgent($task, $now) ? '! ' : '')
			. $task->title;
		return [
			'summary' => $summary,
			'description' => self::structuredDescription($task, $now),
			'location' => ($task->location !== null && $task->location !== '') ? $task->location : null,
			'url' => ($task->url !== null && $task->url !== '') ? $task->url : null,
			'priority' => $task->priority > 0 ? $task->priority : null,
		];
	}

	/**
	 * Relation marker for the slot SUMMARY (plan 0.9.1): a task with subtasks
	 * gets a leading marker, and a task that is itself a subtask gets a different
	 * one. A mid-tree node (both) shows the "has children" marker -- the more
	 * structural info wins. Resolved in CalendarRepository::getTasks from the
	 * calendar's RELATED-TO;RELTYPE=PARENT links (child -> parent uid).
	 */
	public static function relationMark(Task $task): string {
		if ($task->hasChildren) {
			return '↴ ';
		}
		if ($task->parentUid !== null) {
			return '↳ ';
		}
		return '';
	}

	/**
	 * The structured DESCRIPTION block a live working-slot carries (plan 0.9.2):
	 *   ---Task---
	 *   Title: <the task title>   (always present -- survives narrow day-view clipping)
	 *   Completion: 60%
	 *   Priority: 3
	 *   Status: ⏰ Overdue
	 *   Subtasks: ↴
	 *   Link: https://ex.test/t
	 *   👣 First step: <promoted from `First step:` or legacy `Next:` notes>
	 *
	 *   ---Notes---
	 *   <the task's notes>
	 * The Calendar popover renders DESCRIPTION as plain text with URLs
	 * auto-linked (v-linkify, white-space: pre-wrap), so the headers survive
	 * literally and the Link: line becomes clickable. Each metadata line is
	 * emitted only when that field is present; the overdue ⏰ and the subtask
	 * ↴/↳ marker live here (moved out of the SUMMARY in 0.9.2 so the narrow
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
		// Subtask relation moved here from the SUMMARY (0.9.2): has-children
		// wins (mid-tree node), mirroring relationMark().
		if ($task->childTitles !== []) {
			$visible = array_slice($task->childTitles, 0, 5);
			$line = 'Subtasks: ' . implode('; ', array_map(fn (string $title) => '↴ ' . $title, $visible));
			$remaining = count($task->childTitles) - count($visible);
			if ($remaining > 0) {
				$line .= '; +' . $remaining . ' more';
			}
			$meta[] = $line;
		} elseif ($task->hasChildren) {
			$meta[] = 'Subtasks: ↴';
		}
		if ($task->parentTitle !== null) {
			$meta[] = 'Parent task: ↳ ' . $task->parentTitle;
		} elseif ($task->parentUid !== null) {
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
		$taskBlock = $meta !== [] ? "---Task---\n" . implode("\n", $meta) : '';
		$notes = self::notesWithoutFirstStep($task->description);
		$notesBlock = $notes !== '' ? "---Notes---\n" . $notes : '';
		if ($taskBlock === '' && $notesBlock === '') {
			return null;
		}
		if ($taskBlock !== '' && $notesBlock !== '') {
			return $taskBlock . "\n\n" . $notesBlock;
		}
		return $taskBlock . $notesBlock;
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
		return ($task->start !== null && $task->start <= $now)
			|| ($task->due !== null && $task->due <= $now);
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
			$val = (string)$p->getValue();
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

	/** @param string[] $categories */
	public static function replaceTaskCategories(string $ics, array $categories): string {
		$vcal = Reader::read($ics);
		if (!($vcal instanceof VCalendar)) {
			throw new \InvalidArgumentException('Expected a VCALENDAR');
		}
		$vtodos = $vcal->select('VTODO');
		if ($vtodos === []) {
			throw new \InvalidArgumentException('Expected a VTODO');
		}
		$vtodo = $vtodos[0];
		$vtodo->remove('CATEGORIES');
		$categories = array_values(array_unique(array_filter(array_map('strval', $categories), fn (string $value) => $value !== '')));
		if ($categories !== []) {
			$vtodo->add('CATEGORIES', $categories);
		}
		return $vcal->serialize();
	}

	public static function buildSlotEvent(Task $task, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $now = null): string {
		$vcal = new VCalendar();
		$vcal->PRODID = '-//Nextcloud//CalPlan//EN';
		$vevent = $vcal->createComponent('VEVENT');
		$vevent->UID = Defaults::SLOT_UID_PREFIX . $task->id;
		$vevent->DTSTART = $start;
		$vevent->DTEND = $end;
		$vevent->STATUS = 'CONFIRMED';
		// Mirror the task's rich content via the shared slotContent() helper so
		// the reconciler can detect content-only edits (plan 1.5.11). The slot
		// stays a derived, one-way artifact: edits in Calendar are overwritten on
		// the next reconcile. PERCENT-COMPLETE is VTODO-only in strict RFC 5545,
		// so it is folded into the DESCRIPTION prefix, not emitted on the VEVENT.
		// The SUMMARY carries the lock/urgent prefix from slotContent (1.6.3).
		$content = self::slotContent($task, $now);
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
		// Standard link back to the originating VTODO.
		$rel = $vcal->createProperty('RELATED-TO', $task->id);
		$rel['RELTYPE'] = 'TODO';
		$vevent->add($rel);
		$vevent->add('CATEGORIES', Defaults::SLOT_CATEGORY);
		$vcal->add($vevent);
		return $vcal->serialize();
	}

	private static function stringProp(Component $comp, string $name): string {
		foreach ($comp->select($name) as $p) {
			return (string)$p->getValue();
		}
		return '';
	}

	private static function dateTimeProp(Component $comp, string $name): ?\DateTimeImmutable {
		foreach ($comp->select($name) as $p) {
			$dt = $p->getDateTime();
			if ($dt instanceof \DateTimeImmutable) {
				return $dt;
			}
			if ($dt instanceof \DateTime) {
				return \DateTimeImmutable::createFromMutable($dt);
			}
		}
		return null;
	}

	/** @return string[] */
	private static function categories(Component $comp): array {
		$cats = [];
		foreach ($comp->select('CATEGORIES') as $p) {
			foreach ($p->getParts() as $part) {
				$cats[] = $part;
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
		if (!preg_match('/^\s*Duration\s*:\s*(.+?)\s*$/mi', $notes, $line)) {
			return 0;
		}
		$value = trim($line[1]);
		if (!preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours))?(?:\s*(\d+(?:\.\d+)?)\s*(?:m|min|mins|minute|minutes))?$/i', $value, $parts)) {
			return 0;
		}
		$hours = isset($parts[1]) && $parts[1] !== '' ? (float)$parts[1] : 0.0;
		$minutes = isset($parts[2]) && $parts[2] !== '' ? (float)$parts[2] : 0.0;
		$total = (int)round($hours * 60 + $minutes);
		return $total > 0 ? $total : 0;
	}

	/**
	 * Parse an RFC 5545 DURATION value (e.g. "PT45M", "PT1H30M", "PT1H", "P1D")
	 * into a whole number of minutes. Returns 0 for an empty/unparseable
	 * value, so the caller can fall back to the default estimate. Pure and
	 * Sabre-independent so it can be unit-tested without the VObject library.
	 */
	public static function durationToMinutes(string $raw): int {
		$s = ltrim($raw, "+-");
		if ($s === "" || !preg_match('/^P(?:([0-9.]+)W)?(?:([0-9.]+)D)?(?:T(?:([0-9.]+)H)?(?:([0-9.]+)M)?(?:([0-9.]+)S)?)?$/', $s, $m)) {
			return 0;
		}
		$weeks = isset($m[1]) && $m[1] !== "" ? (float)$m[1] : 0.0;
		$days = isset($m[2]) && $m[2] !== "" ? (float)$m[2] : 0.0;
		$hours = isset($m[3]) && $m[3] !== "" ? (float)$m[3] : 0.0;
		$minutes = isset($m[4]) && $m[4] !== "" ? (float)$m[4] : 0.0;
		$seconds = isset($m[5]) && $m[5] !== "" ? (float)$m[5] : 0.0;
		return (int)round($weeks * 7 * 24 * 60 + $days * 24 * 60 + $hours * 60 + $minutes + $seconds / 60);
	}
}