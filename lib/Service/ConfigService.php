<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * User app preferences for calplan, stored via OCP\IConfig (no schema):
 *  - flagged_calendars : comma-list of calendar URIs to auto-schedule wholesale
 *  - working_hours     : JSON map {weekday: {start,end}} (omitted → unavailable)
 *  - ignored calendars/event titles: per-user reconcile exclusions
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Model\WorkingHours;
use OCP\IConfig;

class ConfigService {
	public const KEY_FLAGGED_CALENDARS = 'flagged_calendars';
	public const KEY_WORKING_HOURS = 'working_hours';
	public const KEY_DAILY_CAP_MINUTES = 'daily_cap_minutes';
	public const KEY_DEFAULT_DURATION_MINUTES = 'default_duration_minutes';
	public const KEY_TASK_GAP_MINUTES = 'task_gap_minutes';
	public const KEY_EVENT_BUFFER_MINUTES = 'event_buffer_minutes';
	public const KEY_PACE_PRESET = 'pace_preset';
	public const KEY_PERFORMANCE_LOGGING = 'performance_logging';
	public const KEY_BEHAVIORAL_HISTORY_ENABLED = 'behavioral_history_enabled';
	public const KEY_BEHAVIORAL_HISTORY_RETENTION_DAYS = 'behavioral_history_retention_days';
	public const KEY_BEHAVIORAL_HISTORY_SALT = 'behavioral_history_salt';
	public const KEY_AUTO_RESCHEDULE_MINUTES = 'auto_reschedule_minutes';
	public const KEY_LAST_REVIEW_CHECK = 'last_review_check';
	public const KEY_PAUSED_TASKS = 'paused_tasks';
	public const KEY_DAILY_TASK_COUNT = 'daily_task_count';
	public const KEY_RECONCILE_DIAGNOSTICS = 'reconcile_diagnostics';
	public const KEY_IGNORED_CALENDARS = 'ignored_calendars';
	public const KEY_IGNORED_EVENT_TITLES = 'ignored_event_titles';
	public const KEY_EVENT_TITLE_MATCH_MODE = 'event_title_match_mode';

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return string[] calendar URIs flagged for whole-calendar auto-schedule */
	public function getFlaggedCalendars(string $userId): array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_FLAGGED_CALENDARS, '');
		$parts = $raw === '' ? [] : explode(',', $raw);
		return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
	}

	public function setFlaggedCalendars(string $userId, array $uris): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_FLAGGED_CALENDARS, implode(',', $uris));
	}

	/** @return string[] calendar URIs CalPlan must not read or modify */
	public function getIgnoredCalendars(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_IGNORED_CALENDARS, '[]'), true);
		return is_array($decoded) ? self::cleanStrings($decoded) : [];
	}

	/** @return string[] */
	public function getIgnoredEventTitles(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_IGNORED_EVENT_TITLES, '[]'), true);
		return is_array($decoded) ? self::cleanStrings($decoded) : [];
	}

	public function getEventTitleMatchMode(string $userId): string {
		$mode = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_EVENT_TITLE_MATCH_MODE, 'exact');
		return in_array($mode, ['exact', 'contains'], true) ? $mode : 'exact';
	}

	/** @return array{ignoredCalendars:string[],ignoredEventTitles:string[],eventTitleMatchMode:string} */
	public function getExclusionSettings(string $userId): array {
		return [
			'ignoredCalendars' => $this->getIgnoredCalendars($userId),
			'ignoredEventTitles' => $this->getIgnoredEventTitles($userId),
			'eventTitleMatchMode' => $this->getEventTitleMatchMode($userId),
		];
	}

	/** @param string[] $calendarUris @param string[] $titles */
	public function setExclusionSettings(string $userId, array $calendarUris, array $titles, string $matchMode): void {
		if (!in_array($matchMode, ['exact', 'contains'], true)) {
			throw new \InvalidArgumentException('Event title match mode must be exact or contains');
		}
		$calendarUris = self::cleanStrings($calendarUris);
		$titles = self::cleanStrings($titles);
		if (count($titles) > 100) {
			throw new \InvalidArgumentException('At most 100 ignored event titles are allowed');
		}
		foreach ($titles as $title) {
			if (strlen($title) > 255) {
				throw new \InvalidArgumentException('Ignored event titles must be 255 characters or fewer');
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_IGNORED_CALENDARS, json_encode($calendarUris));
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_IGNORED_EVENT_TITLES, json_encode($titles));
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_EVENT_TITLE_MATCH_MODE, $matchMode);
	}

	public function getWorkingHours(string $userId): WorkingHours {
		$json = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_WORKING_HOURS, '');
		if ($json !== '') {
			$map = json_decode($json, true);
			if (is_array($map) && count($map) > 0 && WorkingHoursService::validateMap($map) === null) {
				return WorkingHoursService::fromMap($map);
			}
		}

		$spreedJson = $this->config->getUserValue($userId, 'spreed', 'working_hours', '');
		if ($spreedJson !== '') {
			$map = json_decode($spreedJson, true);
			if (is_array($map) && count($map) > 0 && WorkingHoursService::validateMap($map) === null) {
				return WorkingHoursService::fromMap($map);
			}
		}

		return WorkingHoursService::default();
	}

	/** @param array<string, array{start: string, end: string}> $map */
	public function setWorkingHours(string $userId, array $map): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_WORKING_HOURS, json_encode($map));
	}

	public function getDailyCapMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DAILY_CAP_MINUTES, 300, 30, 720);
	}

	/** Zero means unlimited. */
	public function getDailyTaskCount(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DAILY_TASK_COUNT, 0, 0, 100);
	}

	public function getDefaultDurationMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DEFAULT_DURATION_MINUTES, 30, 15, 480);
	}

	public function getTaskGapMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_TASK_GAP_MINUTES, 0, 0, 120);
	}

	public function getEventBufferMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_EVENT_BUFFER_MINUTES, 0, 0, 120);
	}

	public function getPacePreset(string $userId): string {
		$value = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PACE_PRESET, 'compact');
		return in_array($value, ['compact', 'balanced', 'relaxed', 'custom'], true) ? $value : 'compact';
	}

	/** @return array{dailyCapMinutes:int,dailyTaskCount:int,defaultDurationMinutes:int,taskGapMinutes:int,eventBufferMinutes:int,pacePreset:string,autoRescheduleMinutes:int} */
	public function getSchedulingPolicy(string $userId): array {
		return [
			'dailyCapMinutes' => $this->getDailyCapMinutes($userId),
			'dailyTaskCount' => $this->getDailyTaskCount($userId),
			'defaultDurationMinutes' => $this->getDefaultDurationMinutes($userId),
			'taskGapMinutes' => $this->getTaskGapMinutes($userId),
			'eventBufferMinutes' => $this->getEventBufferMinutes($userId),
			'pacePreset' => $this->getPacePreset($userId),
			'autoRescheduleMinutes' => $this->getAutoRescheduleMinutes($userId),
		];
	}

	public function setSchedulingPolicy(string $userId, int $dailyCap, int $defaultDuration, int $taskGap, int $eventBuffer, string $preset, int $autoRescheduleMinutes = 15, int $dailyTaskCount = 0): void {
		if ($dailyCap < 30 || $dailyCap > 720) {
			throw new \InvalidArgumentException('Daily cap must be between 30 and 720 minutes');
		}
		if ($defaultDuration < 15 || $defaultDuration > 480) {
			throw new \InvalidArgumentException('Default duration must be between 15 and 480 minutes');
		}
		if ($dailyTaskCount < 0 || $dailyTaskCount > 100) {
			throw new \InvalidArgumentException('Maximum task blocks per day must be unlimited or between 1 and 100');
		}
		if ($taskGap < 0 || $taskGap > 120 || $taskGap % 15 !== 0) {
			throw new \InvalidArgumentException('Task gap must be 0–120 minutes in 15-minute steps');
		}
		if ($eventBuffer < 0 || $eventBuffer > 120 || $eventBuffer % 15 !== 0) {
			throw new \InvalidArgumentException('Event buffer must be 0–120 minutes in 15-minute steps');
		}
		if (!in_array($preset, ['compact', 'balanced', 'relaxed', 'custom'], true)) {
			throw new \InvalidArgumentException('Unknown pace preset');
		}
		if (!in_array($autoRescheduleMinutes, [0, 5, 15, 30, 60], true)) {
			throw new \InvalidArgumentException('Auto-reschedule interval must be off, 5, 15, 30, or 60 minutes');
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DAILY_CAP_MINUTES, (string)$dailyCap);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DAILY_TASK_COUNT, (string)$dailyTaskCount);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DEFAULT_DURATION_MINUTES, (string)$defaultDuration);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_TASK_GAP_MINUTES, (string)$taskGap);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_EVENT_BUFFER_MINUTES, (string)$eventBuffer);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PACE_PRESET, $preset);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_AUTO_RESCHEDULE_MINUTES, (string)$autoRescheduleMinutes);
	}

	/** @return array<int,array<string,mixed>> newest first */
	public function getReconcileDiagnostics(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_RECONCILE_DIAGNOSTICS, '[]'), true);
		return is_array($decoded) ? array_slice(array_values(array_filter($decoded, 'is_array')), 0, 10) : [];
	}

	/** @param array<string,mixed> $report */
	public function recordReconcileDiagnostic(string $userId, array $report): void {
		$reports = $this->getReconcileDiagnostics($userId);
		array_unshift($reports, $report);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_RECONCILE_DIAGNOSTICS, json_encode(array_slice($reports, 0, 10)));
	}

	public function getAutoRescheduleMinutes(string $userId): int {
		$value = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_AUTO_RESCHEDULE_MINUTES, '15');
		return in_array($value, [0, 5, 15, 30, 60], true) ? $value : 15;
	}

	public function reviewGraceMinutes(string $userId): int {
		return match ($this->getPacePreset($userId)) {
			'balanced' => 30,
			'relaxed' => 60,
			'custom' => max(15, $this->getTaskGapMinutes($userId)),
			default => 15,
		};
	}

	public function isReviewCheckDue(string $userId, \DateTimeImmutable $now): bool {
		$interval = $this->getAutoRescheduleMinutes($userId);
		if ($interval === 0) {
			return true; // still mark/stop expired work; just do not reschedule it
		}
		$last = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_LAST_REVIEW_CHECK, '0');
		return $last === 0 || $now->getTimestamp() - $last >= $interval * 60;
	}

	public function markReviewChecked(string $userId, \DateTimeImmutable $now): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_LAST_REVIEW_CHECK, (string)$now->getTimestamp());
	}

	/** @return string[] */
	public function getPausedTasks(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_PAUSED_TASKS, '[]'), true);
		return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
	}

	public function isTaskPaused(string $userId, string $taskUid): bool {
		return in_array($taskUid, $this->getPausedTasks($userId), true);
	}

	public function setTaskPaused(string $userId, string $taskUid, bool $paused): void {
		$tasks = array_fill_keys($this->getPausedTasks($userId), true);
		if ($paused) {
			$tasks[$taskUid] = true;
		} else {
			unset($tasks[$taskUid]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PAUSED_TASKS, json_encode(array_keys($tasks)));
	}

	private function boundedUserInt(string $userId, string $key, int $default, int $min, int $max): int {
		$value = (int)$this->config->getUserValue($userId, Application::APP_ID, $key, (string)$default);
		return max($min, min($max, $value));
	}

	/** @return string[] */
	private static function cleanStrings(array $values): array {
		return array_values(array_unique(array_filter(array_map(
			fn ($value) => trim((string)$value),
			$values,
		), fn (string $value) => $value !== '')));
	}

	public function isPerformanceLoggingEnabled(): bool {
		$value = $this->config->getAppValue(Application::APP_ID, self::KEY_PERFORMANCE_LOGGING, '0');
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	public function isBehavioralHistoryEnabled(string $userId): bool {
		$value = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_BEHAVIORAL_HISTORY_ENABLED, '0');
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	public function getBehavioralHistoryRetentionDays(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_BEHAVIORAL_HISTORY_RETENTION_DAYS, 180, 30, 730);
	}

	/** @return array{enabled:bool,retentionDays:int} */
	public function getBehavioralHistorySettings(string $userId): array {
		return [
			'enabled' => $this->isBehavioralHistoryEnabled($userId),
			'retentionDays' => $this->getBehavioralHistoryRetentionDays($userId),
		];
	}

	public function setBehavioralHistorySettings(string $userId, bool $enabled, int $retentionDays): void {
		if ($retentionDays < 30 || $retentionDays > 730) {
			throw new \InvalidArgumentException('History retention must be between 30 and 730 days');
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_BEHAVIORAL_HISTORY_ENABLED, $enabled ? '1' : '0');
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_BEHAVIORAL_HISTORY_RETENTION_DAYS, (string)$retentionDays);
	}

	public function getBehavioralHistorySalt(string $userId): string {
		$salt = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_BEHAVIORAL_HISTORY_SALT, '');
		if ($salt === '') {
			$salt = bin2hex(random_bytes(32));
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_BEHAVIORAL_HISTORY_SALT, $salt);
		}
		return $salt;
	}

	public function getUserTimeZone(string $userId): \DateTimeZone {
		$tz = $this->config->getUserValue($userId, 'core', 'timezone', '');
		if ($tz !== '') {
			try {
				return new \DateTimeZone($tz);
			} catch (\Throwable $_) {
			}
		}
		$def = $this->config->getAppValue('core', 'default_timezone', '');
		if ($def !== '') {
			try {
				return new \DateTimeZone($def);
			} catch (\Throwable $_) {
			}
		}
		$sys = date_default_timezone_get();
		try {
			return new \DateTimeZone($sys ?: 'UTC');
		} catch (\Throwable $_) {
			return new \DateTimeZone('UTC');
		}
	}
}