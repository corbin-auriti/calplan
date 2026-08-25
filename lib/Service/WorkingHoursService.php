<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Builds WorkingHours from a sensible default or a user-supplied map
 * (weekday key → {start,end} as "HH:MM"). Pure — no I/O.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\WorkingDay;
use OCA\AutoSchedule\Model\WorkingHours;

class WorkingHoursService {
	/** 09:00–17:00, 7 days a week — ADHD-friendly default (schedule anytime). */
	public static function default(): WorkingHours {
		$days = [];
		for ($w = 0; $w < 7; $w++) {
			$days[] = new WorkingDay($w, 9 * 60, 17 * 60);
		}
		return new WorkingHours($days);
	}

	/**
	 * @param array<string, array{start: string, end: string}> $map weekday key → hours
	 * @return WorkingHours
	 */
	public static function fromMap(array $map): WorkingHours {
		$keys = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
		$days = [];
		foreach ($keys as $key => $weekday) {
			$entry = $map[$key] ?? null;
			if ($entry === null) {
				continue;
			}
			$days[] = new WorkingDay($weekday, self::parseHhmm($entry['start'] ?? '0:00'), self::parseHhmm($entry['end'] ?? '0:00'));
		}
		return new WorkingHours($days);
	}

	public static function parseHhmm(string $hhmm): int {
		$parts = array_pad(explode(':', $hhmm), 2, '0');
		return ((int)$parts[0]) * 60 + (int)$parts[1];
	}

	public static function minutesToHhmm(int $minutes): string {
		$minutes = max(0, $minutes);
		return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	public static function isValidHhmm(string $hhmm): bool {
		return (bool)preg_match('/^\d{1,2}:\d{2}$/', $hhmm);
	}

	/** @return array<string, array{start: string, end: string}> */
	public static function toMap(WorkingHours $wh): array {
		$names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
		$out = [];
		foreach ($wh->days as $d) {
			if (!isset($names[$d->weekday])) {
				continue;
			}
			$out[$names[$d->weekday]] = [
				'start' => self::minutesToHhmm($d->startMinutes),
				'end' => self::minutesToHhmm($d->endMinutes),
			];
		}
		return $out;
	}

	/**
	 * Validate a {weekday: {start, end}} map for storage.
	 * @param array<string, mixed> $map
	 * @return string|null null when valid, else an error message
	 */
	public static function validateMap(array $map): ?string {
		$names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
		foreach ($map as $key => $entry) {
			if (!in_array($key, $names, true)) {
				return "unknown weekday '$key'";
			}
			if (!is_array($entry) || !array_key_exists('start', $entry) || !array_key_exists('end', $entry)) {
				return "entry '$key' must have start and end";
			}
			$start = (string)$entry['start'];
			$end = (string)$entry['end'];
			if (!self::isValidHhmm($start) || !self::isValidHhmm($end)) {
				return "entry '$key' has invalid time (expected HH:MM)";
			}
			if (self::parseHhmm($start) >= self::parseHhmm($end)) {
				return "entry '$key' start must be before end";
			}
		}
		return null;
	}
}