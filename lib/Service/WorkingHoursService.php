<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Builds WorkingHours from a sensible default, a user-supplied map
 * (weekday key → {start,end} as "HH:MM") or Nextcloud's availability. Pure —
 * no I/O.
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\WorkingDay;
use OCA\AutoSchedule\Model\WorkingHours;
use Sabre\VObject\Reader;

class WorkingHoursService {
	private const WEEKDAYS = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
	private const BYDAY = ['MO' => 'mon', 'TU' => 'tue', 'WE' => 'wed', 'TH' => 'thu', 'FR' => 'fri', 'SA' => 'sat', 'SU' => 'sun'];

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
		$days = [];
		foreach (self::WEEKDAYS as $key => $weekday) {
			$entry = $map[$key] ?? null;
			if ($entry === null) {
				continue;
			}
			$days[] = new WorkingDay($weekday, self::parseHhmm($entry['start'] ?? '0:00'), self::parseHhmm($entry['end'] ?? '0:00'));
		}
		return new WorkingHours($days);
	}

	/**
	 * Working hours from Nextcloud's availability (a VAVAILABILITY with one
	 * weekly AVAILABLE per time slot). CalPlan keeps one window per weekday:
	 * from the first slot's start to the last slot's end. Null when the
	 * availability has no usable slot.
	 *
	 * @return array<string, array{start: string, end: string}>|null
	 */
	public static function fromAvailability(string $ics): ?array {
		$vcal = Reader::read($ics);
		$minutes = [];
		foreach ($vcal->getComponents() as $availability) {
			if ($availability->name !== 'VAVAILABILITY') {
				continue;
			}
			foreach ($availability->getComponents() as $available) {
				if ($available->name !== 'AVAILABLE' || !isset($available->DTSTART, $available->DTEND)) {
					continue;
				}
				$start = $available->DTSTART->getDateTime();
				$end = $available->DTEND->getDateTime();
				$from = (int)$start->format('G') * 60 + (int)$start->format('i');
				$to = $start->format('Y-m-d') === $end->format('Y-m-d') ? (int)$end->format('G') * 60 + (int)$end->format('i') : 24 * 60;
				if ($to <= $from) {
					continue;
				}
				$days = [];
				if (isset($available->RRULE) && preg_match('/(?:^|;)BYDAY=([^;]+)/i', (string)$available->RRULE->getValue(), $m) === 1) {
					foreach (explode(',', strtoupper($m[1])) as $byday) {
						$code = substr(trim($byday), -2);
						if (isset(self::BYDAY[$code])) {
							$days[] = self::BYDAY[$code];
						}
					}
				} else {
					$days[] = array_search((int)$start->format('N') - 1, self::WEEKDAYS, true);
				}
				foreach ($days as $day) {
					$minutes[$day] = [min($minutes[$day][0] ?? $from, $from), max($minutes[$day][1] ?? $to, $to)];
				}
			}
		}
		$map = [];
		foreach (self::WEEKDAYS as $key => $_) {
			if (isset($minutes[$key])) {
				$map[$key] = ['start' => self::minutesToHhmm($minutes[$key][0]), 'end' => self::minutesToHhmm($minutes[$key][1])];
			}
		}
		return $map !== [] ? $map : null;
	}

	public static function parseHhmm(string $hhmm): int {
		$parts = array_pad(explode(':', $hhmm), 2, '0');
		return ((int)$parts[0]) * 60 + (int)$parts[1];
	}

	public static function minutesToHhmm(int $minutes): string {
		$minutes = max(0, $minutes);
		return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	/** A time of day: 0:00–23:59, or 24:00 for the end of the day. */
	public static function isValidHhmm(string $hhmm): bool {
		return preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$|^24:00$/', $hhmm) === 1;
	}

	/** @return array<string, array{start: string, end: string}> */
	public static function toMap(WorkingHours $wh): array {
		$names = array_keys(self::WEEKDAYS);
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
	 * Validate a {weekday: {start, end}} map for storage: known weekdays,
	 * times of day (an end may be 24:00, midnight), each start before its end.
	 *
	 * @param array<string, mixed> $map
	 * @return string|null null when valid, else an error message
	 */
	public static function validateMap(array $map): ?string {
		foreach ($map as $key => $entry) {
			if (!isset(self::WEEKDAYS[$key])) {
				return "unknown weekday '$key'";
			}
			if (!is_array($entry) || !array_key_exists('start', $entry) || !array_key_exists('end', $entry)) {
				return "entry '$key' must have start and end";
			}
			$start = (string)$entry['start'];
			$end = (string)$entry['end'];
			if (!self::isValidHhmm($start) || !self::isValidHhmm($end) || $start === '24:00') {
				return "entry '$key' has invalid time (expected HH:MM, 00:00–24:00)";
			}
			if (self::parseHhmm($start) >= self::parseHhmm($end)) {
				return "entry '$key' start must be before end";
			}
		}
		return null;
	}
}
