<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use OCA\AutoSchedule\Service\BehavioralHistoryService;
use PHPUnit\Framework\TestCase;

class BehavioralHistoryServiceTest extends TestCase {
	public function testTitleShapeUsesOnlyCoarseNonTextMetadata(): void {
		$shape = BehavioralHistoryService::titleShapeMetadata('Draft Q3 report: review numbers?');

		$this->assertSame('medium', $shape['characters']);
		$this->assertSame('several', $shape['words']);
		$this->assertTrue($shape['has_question']);
		$this->assertTrue($shape['has_digits']);
		$this->assertTrue($shape['has_separator']);
		$this->assertArrayNotHasKey('title', $shape);
		$this->assertStringNotContainsString('Draft', json_encode($shape));
	}

	public function testTitleShapeRecognizesChecklistAndShortTitle(): void {
		$shape = BehavioralHistoryService::titleShapeMetadata('[ ] Call Sam');

		$this->assertSame('very_short', $shape['characters']);
		$this->assertSame('few', $shape['words']);
		$this->assertTrue($shape['has_checklist_marker']);
	}

	public function testTitleShapeCountsUnicodeWordsByCharactersNotBytes(): void {
		$shape = BehavioralHistoryService::titleShapeMetadata('Réviser été');

		$this->assertSame('few', $shape['words']);
		$this->assertSame('medium', $shape['average_word_length']);
	}
}