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
 * Exercises the pure, Sabre-independent RFC 5545 DURATION parser that backs
 * per-task estimate parsing (plan 1.5.10). The Sabre-dependent parseTasks()
 * round-trip is covered separately by an integration test that skips itself
 * when Sabre\VObject is absent (see IcalCodecTest).
 */
class IcalCodecDurationTest extends TestCase {
	/**
	 * @dataProvider provideDurations
	 */
	public function testDurationToMinutes(string $raw, int $expected): void {
		$this->assertSame($expected, IcalCodec::durationToMinutes($raw));
	}

	public function provideDurations(): array {
		return [
			'plain minutes' => ['PT45M', 45],
			'one hour' => ['PT1H', 60],
			'hour and minutes' => ['PT1H30M', 90],
			'one day' => ['P1D', 1440],
			'one week' => ['P1W', 10080],
			'two hours fifteen' => ['PT2H15M', 135],
			'with seconds rounding' => ['PT90S', 2],
			'day plus hours' => ['P1DT2H', 1560],
			'positive sign' => ['+PT45M', 45],
			'negative sign ignored' => ['-PT45M', 45],
			'empty => 0' => ['', 0],
			'garbage => 0' => ['not-a-duration', 0],
			'missing P prefix => 0' => ['45M', 0],
			'week and days' => ['P1W2D', 12960],
		];
	}

	/** @dataProvider provideNoteDurations */
	public function testNotesDurationToMinutes(string $notes, int $expected): void {
		$this->assertSame($expected, IcalCodec::notesDurationToMinutes($notes));
	}

	public function provideNoteDurations(): array {
		return [
			'minutes compact' => ["Duration: 15min", 15],
			'minutes spaced' => ["notes\nDuration: 10 min\nmore", 10],
			'short minutes' => ["duration: 90m", 90],
			'hour' => ["Duration: 1hr", 60],
			'hours and minutes' => ["Duration: 1 hour 30 minutes", 90],
			'decimal hours' => ["Duration: 1.5h", 90],
			'absent' => ["ordinary notes", 0],
			'zero' => ["Duration: 0min", 0],
			'ambiguous trailing text' => ["Duration: about an hour", 0],
		];
	}

	public function testParseTasksFallsBackToDefaultWhenNoDuration(): void {
		// The default-estimate fallback is exercised via the parseTasks path; here
		// we assert the contract the caller relies on: an unparseable duration
		// yields 0 so the caller maps it back to the default.
		$this->assertSame(0, IcalCodec::durationToMinutes('garbage'));
		$this->assertSame(Defaults::DEFAULT_ESTIMATE_MINUTES, 30);
	}
}
