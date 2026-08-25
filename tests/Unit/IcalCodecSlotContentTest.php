<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Model\Task;
use OCA\AutoSchedule\Service\IcalCodec;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the pure slotContent() helper (plan 1.5.11) that defines the
 * mirrored content of a working-slot VEVENT and drives the reconciler's
 * content-change detection. Sabre-independent.
 */
class IcalCodecSlotContentTest extends TestCase {
	private function task(array $over = []): Task {
		return new Task(
			$over['id'] ?? 'T',
			$over['title'] ?? 'My task',
			$over['priority'] ?? 5,
			null,
			null,
			30,
			true,
			'NEEDS-ACTION',
			$over['description'] ?? null,
			$over['location'] ?? null,
			$over['url'] ?? null,
			$over['percentComplete'] ?? null,
			null,
			null,
			$over['parentUid'] ?? null,
			$over['hasChildren'] ?? false,
			$over['parentTitle'] ?? null,
			$over['childTitles'] ?? [],
		);
	}

	public function testBareTaskContent(): void {
		$c = IcalCodec::slotContent($this->task());
		$this->assertSame('My task', $c['summary']);
		$this->assertNull($c['description']);
		$this->assertNull($c['location']);
		$this->assertNull($c['url']);
		$this->assertSame(5, $c['priority']);
	}

	public function testDescriptionIsMirrored(): void {
		$c = IcalCodec::slotContent($this->task(['description' => 'Pick up milk']));
		$this->assertSame('Pick up milk', $c['description']);
	}

	public function testPercentPrefixFoldedIntoDescription(): void {
		$c = IcalCodec::slotContent($this->task(['description' => 'notes', 'percentComplete' => 60]));
		$this->assertSame('60% - notes', $c['description']);
	}

	public function testPercentPrefixWithoutNotes(): void {
		$c = IcalCodec::slotContent($this->task(['percentComplete' => 60]));
		$this->assertSame('60% - ', $c['description']);
	}

	public function testZeroPercentOmitsPrefix(): void {
		$c = IcalCodec::slotContent($this->task(['description' => 'notes', 'percentComplete' => 0]));
		$this->assertSame('notes', $c['description']);
	}

	public function testLocationAndUrlMirroredWhenNonEmpty(): void {
		$c = IcalCodec::slotContent($this->task(['location' => 'Office', 'url' => 'https://ex.test/t']));
		$this->assertSame('Office', $c['location']);
		$this->assertSame('https://ex.test/t', $c['url']);
	}

	public function testEmptyLocationAndUrlAreNull(): void {
		$c = IcalCodec::slotContent($this->task(['location' => '', 'url' => '']));
		$this->assertNull($c['location']);
		$this->assertNull($c['url']);
	}

	public function testZeroPriorityIsOmitted(): void {
		$c = IcalCodec::slotContent($this->task(['priority' => 0]));
		$this->assertNull($c['priority']);
	}

	public function testLockPrefixWhenNowProvided(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$c = IcalCodec::slotContent($this->task(), $now);
		// 0.9.2: priority marks live in the DESCRIPTION, not the SUMMARY; not
		// overdue -> no '!'. Only the lock stays in front of the title.
		$this->assertSame('🔒 My task', $c['summary']);
		$this->assertSame("---Task---\nTitle: My task\nPriority: 5", $c['description']);
	}

	public function testUrgentBangPrefixWhenStartPassed(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$past = new \DateTimeImmutable('2026-08-18 09:00:00', new \DateTimeZone('UTC'));
		$t = new Task('T', 'Late', 5, null, $past, 30, true, 'NEEDS-ACTION', null, null, null, null, null, null, null, false);
		$c = IcalCodec::slotContent($t, $now);
		// 0.9.2: overdue -> single '!' in SUMMARY; the ⏰ detail + Priority
		// moved into the DESCRIPTION.
		$this->assertSame('🔒 ! Late', $c['summary']);
		$this->assertSame("---Task---\nTitle: Late\nPriority: 5\nStatus: ⏰ Overdue", $c['description']);
	}

	public function testStructuredDescriptionSubtaskLines(): void {
		// hasChildren -> Subtasks: ↴ (priority 5 -> Priority: 5). No notes.
		$t = $this->task(['hasChildren' => true]);
		$this->assertSame("---Task---\nTitle: My task\nPriority: 5\nSubtasks: ↴", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionChildLine(): void {
		// Unresolved parent still carries a relation cue.
		$t = $this->task(['parentUid' => 'parent-1', 'priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task\nParent task: ↳", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionNamesParentTask(): void {
		$t = $this->task(['parentUid' => 'parent-1', 'parentTitle' => 'Prepare release', 'priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task\nParent task: ↳ Prepare release", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionNamesImmediateSubtasks(): void {
		$t = $this->task(['childTitles' => ['Draft notes', 'Run tests'], 'priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task\nSubtasks: ↴ Draft notes; ↴ Run tests", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionBoundsLongSubtaskList(): void {
		$t = $this->task(['childTitles' => ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'], 'priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task\nSubtasks: ↴ One; ↴ Two; ↴ Three; ↴ Four; ↴ Five; +2 more", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionOnlyNotes(): void {
		// No metadata besides the Title (priority 0, no %/url/overdue/subtask) but
		// notes present -> Title + ---Notes--- (0.9.6: Title always leads ---Task---).
		$t = $this->task(['priority' => 0, 'description' => 'just notes']);
		$this->assertSame("---Task---\nTitle: My task\n\n---Notes---\njust notes", IcalCodec::structuredDescription($t));
	}

	public function testLiveSummaryTopPriority(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$c = IcalCodec::slotContent($this->task(['priority' => 1, 'title' => 'Critical']), $now);
		// 0.9.2: no more '!!!' in the SUMMARY; the priority level lives in the
		// DESCRIPTION as 'Priority: 1'.
		$this->assertSame('🔒 Critical', $c['summary']);
		$this->assertSame("---Task---\nTitle: Critical\nPriority: 1", $c['description']);
	}

	public function testLiveSummaryLowPriorityHasNoMark(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$c = IcalCodec::slotContent($this->task(['priority' => 9, 'title' => 'Whenever']), $now);
		$this->assertSame('🔒 Whenever', $c['summary']);
	}

	public function testLiveSummaryOverdueNoPriority(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$past = new \DateTimeImmutable('2026-08-18 09:00:00', new \DateTimeZone('UTC'));
		$t = new Task('T', 'Late', 0, null, $past, 30, true, 'NEEDS-ACTION', null, null, null, null, null, null, null, false);
		$c = IcalCodec::slotContent($t, $now);
		// 0.9.2: overdue -> '!' in SUMMARY; the ⏰ moved into the DESCRIPTION.
		$this->assertSame('🔒 ! Late', $c['summary']);
		$this->assertSame("---Task---\nTitle: Late\nStatus: ⏰ Overdue", $c['description']);
	}

	public function testRelationMarkParent(): void {
		$this->assertSame('↴ ', IcalCodec::relationMark($this->task(['hasChildren' => true])));
	}

	public function testRelationMarkChild(): void {
		$this->assertSame('↳ ', IcalCodec::relationMark($this->task(['parentUid' => 'parent-1'])));
	}

	public function testRelationMarkNone(): void {
		$this->assertSame('', IcalCodec::relationMark($this->task()));
	}

	public function testRelationMarkParentWinsOverChild(): void {
		// A mid-tree node (has children AND is itself a child) shows ↴.
		$this->assertSame('↴ ', IcalCodec::relationMark($this->task(['hasChildren' => true, 'parentUid' => 'p'])));
	}

	public function testLiveSummaryParentRelation(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$c = IcalCodec::slotContent($this->task(['hasChildren' => true, 'title' => 'Project']), $now);
		// 0.9.2: the ↴ subtask marker moved out of the SUMMARY into the
		// DESCRIPTION ('Subtasks: ↴'); the SUMMARY keeps only the lock + title.
		$this->assertSame('🔒 Project', $c['summary']);
		$this->assertSame("---Task---\nTitle: Project\nPriority: 5\nSubtasks: ↴", $c['description']);
	}

	public function testStructuredDescriptionFull(): void {
		$t = $this->task(['description' => 'do the thing', 'url' => 'https://ex.test/t', 'percentComplete' => 60]);
		// priority 5 (medium) -> Priority: 5. Notes get their own ---Notes--- header.
		$this->assertSame("---Task---\nTitle: My task\nCompletion: 60%\nPriority: 5\nLink: https://ex.test/t\n\n---Notes---\ndo the thing", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionOnlyPriority(): void {
		$t = $this->task(); // priority 5, no notes/%/url.
		$this->assertSame("---Task---\nTitle: My task\nPriority: 5", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionTitleLineWhenNothingElseToShow(): void {
		// priority 0 (none), no notes, no %, no url -- just the Title line (0.9.6:
		// Title always leads the ---Task--- section, so it is never empty/null).
		$t = $this->task(['priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionZeroPercentOmitsCompletionLine(): void {
		$t = $this->task(['description' => 'notes', 'percentComplete' => 0]);
		$this->assertSame("---Task---\nTitle: My task\nPriority: 5\n\n---Notes---\nnotes", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionNoPriority(): void {
		$t = $this->task(['priority' => 0, 'description' => 'notes']);
		// No metadata besides the Title (priority 0, no %/url/overdue/subtask)
		// -> Title + ---Notes--- (0.9.6).
		$this->assertSame("---Task---\nTitle: My task\n\n---Notes---\nnotes", IcalCodec::structuredDescription($t));
	}

	public function testLegacyPathStillRawTitleAndPercentPrefix(): void {
		// No $now -> legacy/pure path: raw title, simple % prefix (unchanged).
		$c = IcalCodec::slotContent($this->task(['description' => 'notes', 'percentComplete' => 60]));
		$this->assertSame('My task', $c['summary']);
		$this->assertSame('60% - notes', $c['description']);
	}

	public function testStructuredDescriptionOverdueLine(): void {
		// Overdue (start passed), priority 0, no notes/%/url/subtask -> only
		// the Status: ⏰ Overdue line under ---Task---.
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$past = new \DateTimeImmutable('2026-08-18 09:00:00', new \DateTimeZone('UTC'));
		$t = new Task('T', 'Late', 0, null, $past, 30, true, 'NEEDS-ACTION', null, null, null, null, null, null, null, false);
		$this->assertSame("---Task---\nTitle: Late\nStatus: ⏰ Overdue", IcalCodec::structuredDescription($t, $now));
	}

	public function testLiveSummaryOverdueWithPriority(): void {
		// Overdue + top priority: '!' in SUMMARY; Priority + Status in DESCRIPTION.
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$past = new \DateTimeImmutable('2026-08-18 09:00:00', new \DateTimeZone('UTC'));
		$t = new Task('T', 'Critical', 1, null, $past, 30, true, 'NEEDS-ACTION', null, null, null, null, null, null, null, false);
		$c = IcalCodec::slotContent($t, $now);
		$this->assertSame('🔒 ! Critical', $c['summary']);
		$this->assertSame("---Task---\nTitle: Critical\nPriority: 1\nStatus: ⏰ Overdue", $c['description']);
	}
	public function testFirstStepReadsPreferredSyntax(): void {
		$this->assertSame('write the heading', IcalCodec::firstStep("Some notes\nFirst step: write the heading\nmore notes"));
	}

	public function testFirstStepAcceptsLegacyNextLine(): void {
		$this->assertSame('write the heading', IcalCodec::nextAction("Some notes\nNext: write the heading\nmore notes"));
	}

	public function testNextActionIsCaseInsensitiveAndToleratesWhitespace(): void {
		$this->assertSame('do X', IcalCodec::nextAction("   next:\tdo X  "));
	}

	public function testNextActionNullWhenAbsent(): void {
		$this->assertNull(IcalCodec::nextAction('just notes, no next step'));
		$this->assertNull(IcalCodec::nextAction(null));
		$this->assertNull(IcalCodec::nextAction(''));
	}

	public function testNotesWithoutNextActionRemovesNextLine(): void {
		// The Next: line and its newline are removed, so no blank gap is left.
		$this->assertSame("line one\nline two", IcalCodec::notesWithoutNextAction("line one\nNext: skip me\nline two"));
	}

	public function testNotesWithoutFirstStepRemovesPreferredAndLegacyLines(): void {
		$this->assertSame('notes', IcalCodec::notesWithoutFirstStep("First step: begin\nNext: legacy\nnotes"));
	}

	public function testNotesWithoutNextActionUnchangedWhenNoNextLine(): void {
		// No Next: line -> the description is returned verbatim (preserves the
		// common case exactly so existing DESCRIPTION assertions hold).
		$this->assertSame('do the thing', IcalCodec::notesWithoutNextAction('do the thing'));
		$this->assertSame('', IcalCodec::notesWithoutNextAction(null));
	}

	public function testStructuredDescriptionSurfacesNextAction(): void {
		$t = $this->task(['description' => "First step: write the heading\nthen review", 'priority' => 0]);
		// First step promoted to the ---Task--- block; the helper line is
		// dropped from ---Notes--- so it isn't shown twice.
		$this->assertSame("---Task---\nTitle: My task\n👣 First step: write the heading\n\n---Notes---\nthen review", IcalCodec::structuredDescription($t));
	}

	public function testStructuredDescriptionOnlyNextAction(): void {
		// A task whose only note is the Next: line -> ---Notes--- is omitted.
		$t = $this->task(['description' => 'First step: just do it', 'priority' => 0]);
		$this->assertSame("---Task---\nTitle: My task\n👣 First step: just do it", IcalCodec::structuredDescription($t));
	}

	public function testLiveSlotContentCarriesNextAction(): void {
		$now = new \DateTimeImmutable('2026-08-19 08:00:00', new \DateTimeZone('UTC'));
		$t = $this->task(['description' => "First step: draft outline\nnotes here"]);
		$c = IcalCodec::slotContent($t, $now);
		$this->assertSame('🔒 My task', $c['summary']);
		$this->assertSame("---Task---\nTitle: My task\nPriority: 5\n👣 First step: draft outline\n\n---Notes---\nnotes here", $c['description']);
	}
}
