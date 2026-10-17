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
 *  - scheduling policy, review state, the last reconcile's results, and the
 *    block time already spent today
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Model\Defaults;
use OCA\AutoSchedule\Model\WorkingHours;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

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
	public const KEY_PLAN_ACTIVE = 'plan_active';
	public const KEY_UNSCHEDULED_TASKS = 'unscheduled_tasks';
	public const KEY_PLANNED_TASKS = 'planned_tasks';
	public const KEY_PLANNING_DAYS = 'planning_days';
	public const KEY_MIN_PART_MINUTES = 'min_part_minutes';
	public const KEY_RESTARTED_TASKS = 'restarted_tasks';
	public const KEY_SPENT_TODAY = 'spent_today';
	public const KEY_REWRITTEN_TASKS = 'rewritten_tasks';
	public const KEY_REPLAN_HOLD = 'replan_hold_until';
	/** Allowed minimum lengths of a split task's parts. */
	public const MIN_PART_CHOICES = [15, 30, 45, 60];
	/** Recovery time between tasks and buffer around events: 0–120 minutes in 15-minute steps. */
	public const GAP_CHOICES = [0, 15, 30, 45, 60, 75, 90, 105, 120];
	/** How often expired blocks are reviewed (0 = off: tag for review and stop scheduling). */
	public const AUTO_RESCHEDULE_CHOICES = [0, 5, 15, 30, 60];
	public const PACE_PRESETS = ['compact', 'balanced', 'relaxed', 'custom'];
	public const DAILY_CAP_MIN = 30;
	public const DAILY_CAP_MAX = 720;
	public const DAILY_CAP_DEFAULT = 300;
	public const DAILY_TASK_COUNT_MAX = 100;
	public const DEFAULT_DURATION_MIN = 15;
	public const DEFAULT_DURATION_MAX = 480;
	/** Longest a client may hold back the per-write re-plans (see holdReplans). */
	public const REPLAN_HOLD_SECONDS = 120;
	/** How long the Tasks page is told about a task CalPlan rewrote, so it can reload it. */
	private const REWRITTEN_TTL_SECONDS = 3600;

	public function __construct(
		private IConfig $config,
		private ?LoggerInterface $logger = null,
		private ?IUserConfig $userConfig = null,
	) {
	}

	/**
	 * Forget the user's values this process has read so far, so that the next
	 * reads come from the database. Nextcloud keeps them for the whole request:
	 * a reconcile that waited for another one to finish would otherwise work
	 * on the values from before that run, and skip writes that look unchanged.
	 */
	public function reload(string $userId): void {
		$this->userConfig?->clearCache($userId);
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

	/**
	 * The working hours CalPlan plans in: those saved in CalPlan's settings,
	 * else the user's Nextcloud availability (Personal settings ->
	 * Availability), else 09:00-17:00 every day.
	 */
	public function getWorkingHours(string $userId): WorkingHours {
		$map = $this->storedWorkingHours($userId) ?? $this->availabilityWorkingHours($userId);
		return $map !== null ? WorkingHoursService::fromMap($map) : WorkingHoursService::default();
	}

	/**
	 * Tasks the last reconcile could not place, for the Tasks UI warnings.
	 *
	 * @return array<string, array{reason: string, uri: string}> keyed by task UID (uri = object URI)
	 */
	public function getUnscheduledTasks(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_UNSCHEDULED_TASKS, '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string, array{reason: string, uri: string}> $tasks
	 * @return bool whether the stored map changed
	 */
	public function setUnscheduledTasks(string $userId, array $tasks): bool {
		ksort($tasks, SORT_STRING);
		$json = (string)json_encode((object)$tasks, JSON_UNESCAPED_SLASHES);
		if ($json === $this->config->getUserValue($userId, Application::APP_ID, self::KEY_UNSCHEDULED_TASKS, '{}')) {
			return false;
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_UNSCHEDULED_TASKS, $json);
		return true;
	}

	/**
	 * The blocks the last reconcile planned, for the Tasks UI: by task UID, the
	 * task's object URI and each block's start and end (Unix timestamps).
	 *
	 * @return array<string, array{uri: string, blocks: list<array{0: int, 1: int}>}>
	 */
	public function getPlannedTasks(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_PLANNED_TASKS, '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string, array{uri: string, blocks: list<array{0: int, 1: int}>}> $tasks
	 * @return bool whether the stored map changed
	 */
	public function setPlannedTasks(string $userId, array $tasks): bool {
		ksort($tasks, SORT_STRING);
		$json = (string)json_encode((object)$tasks, JSON_UNESCAPED_SLASHES);
		if ($json === $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PLANNED_TASKS, '{}')) {
			return false;
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PLANNED_TASKS, $json);
		return true;
	}

	/** Whether the last reconcile had tasks to schedule or blocks to maintain. */
	public function isPlanActive(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PLAN_ACTIVE, '0') === '1';
	}

	/** Whether a reconcile has ever run for the user. */
	public function hasPlanState(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PLAN_ACTIVE, '') !== '';
	}

	public function setPlanActive(string $userId, bool $active): void {
		if ($this->isPlanActive($userId) !== $active) {
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PLAN_ACTIVE, $active ? '1' : '0');
		}
	}

	/** Where getWorkingHours() reads from: 'calplan' (saved in CalPlan settings), 'availability' (Nextcloud's), or 'default'. */
	public function getWorkingHoursSource(string $userId): string {
		if ($this->storedWorkingHours($userId) !== null) {
			return 'calplan';
		}
		return $this->availabilityWorkingHours($userId) !== null ? 'availability' : 'default';
	}

	/** @return array<string, array{start: string, end: string}>|null a valid, non-empty `working_hours` map saved in CalPlan */
	private function storedWorkingHours(string $userId): ?array {
		$json = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_WORKING_HOURS, '');
		if ($json === '') {
			return null;
		}
		$map = json_decode($json, true);
		return is_array($map) && count($map) > 0 && WorkingHoursService::validateMap($map) === null ? $map : null;
	}

	/**
	 * The user's Nextcloud availability (a VAVAILABILITY stored on their
	 * CalDAV inbox) as a working-hours map, or null when none is set.
	 *
	 * @return array<string, array{start: string, end: string}>|null
	 */
	private function availabilityWorkingHours(string $userId): ?array {
		if (!class_exists(\OCA\DAV\Db\PropertyMapper::class)) {
			return null;
		}
		try {
			/** @var \OCA\DAV\Db\PropertyMapper $mapper */
			$mapper = \OCP\Server::get(\OCA\DAV\Db\PropertyMapper::class);
			$properties = $mapper->findPropertyByPathAndName($userId, 'calendars/' . $userId . '/inbox', '{urn:ietf:params:xml:ns:caldav}calendar-availability');
			$value = $properties !== [] ? (string)$properties[0]->getPropertyvalue() : '';
			return $value !== '' ? WorkingHoursService::fromAvailability($value) : null;
		} catch (\Throwable $e) {
			$this->logger?->warning('CalPlan could not read the Nextcloud availability', ['app' => 'calplan', 'exception' => $e]);
			return null;
		}
	}

	/** @param array<string, array{start: string, end: string}> $map */
	public function setWorkingHours(string $userId, array $map): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_WORKING_HOURS, json_encode($map));
	}

	/** Shortest part when a task is split (15, 30, 45 or 60 minutes). */
	public function getMinPartMinutes(string $userId): int {
		$value = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_MIN_PART_MINUTES, (string)Defaults::MIN_PART_MINUTES);
		return in_array($value, self::MIN_PART_CHOICES, true) ? $value : Defaults::MIN_PART_MINUTES;
	}

	/** How many days ahead CalPlan plans (the scheduling horizon). */
	public function getPlanningDays(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_PLANNING_DAYS, Defaults::LOOKAHEAD_DAYS, Defaults::MIN_PLANNING_DAYS, Defaults::MAX_PLANNING_DAYS);
	}

	public function getDailyCapMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DAILY_CAP_MINUTES, self::DAILY_CAP_DEFAULT, self::DAILY_CAP_MIN, self::DAILY_CAP_MAX);
	}

	/** Zero means unlimited. */
	public function getDailyTaskCount(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DAILY_TASK_COUNT, 0, 0, self::DAILY_TASK_COUNT_MAX);
	}

	public function getDefaultDurationMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_DEFAULT_DURATION_MINUTES, Defaults::DEFAULT_ESTIMATE_MINUTES, self::DEFAULT_DURATION_MIN, self::DEFAULT_DURATION_MAX);
	}

	public function getTaskGapMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_TASK_GAP_MINUTES, 0, 0, 120);
	}

	public function getEventBufferMinutes(string $userId): int {
		return $this->boundedUserInt($userId, self::KEY_EVENT_BUFFER_MINUTES, 0, 0, 120);
	}

	public function getPacePreset(string $userId): string {
		$value = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PACE_PRESET, 'compact');
		return in_array($value, self::PACE_PRESETS, true) ? $value : 'compact';
	}

	/** @return array{dailyCapMinutes:int,dailyTaskCount:int,defaultDurationMinutes:int,taskGapMinutes:int,eventBufferMinutes:int,pacePreset:string,autoRescheduleMinutes:int,planningDays:int,minPartMinutes:int} */
	public function getSchedulingPolicy(string $userId): array {
		return [
			'dailyCapMinutes' => $this->getDailyCapMinutes($userId),
			'dailyTaskCount' => $this->getDailyTaskCount($userId),
			'defaultDurationMinutes' => $this->getDefaultDurationMinutes($userId),
			'taskGapMinutes' => $this->getTaskGapMinutes($userId),
			'eventBufferMinutes' => $this->getEventBufferMinutes($userId),
			'pacePreset' => $this->getPacePreset($userId),
			'autoRescheduleMinutes' => $this->getAutoRescheduleMinutes($userId),
			'planningDays' => $this->getPlanningDays($userId),
			'minPartMinutes' => $this->getMinPartMinutes($userId),
		];
	}

	public function setSchedulingPolicy(string $userId, int $dailyCap, int $defaultDuration, int $taskGap, int $eventBuffer, string $preset, int $autoRescheduleMinutes = 15, int $dailyTaskCount = 0, int $planningDays = Defaults::LOOKAHEAD_DAYS, int $minPartMinutes = Defaults::MIN_PART_MINUTES): void {
		if (!in_array($minPartMinutes, self::MIN_PART_CHOICES, true)) {
			throw new \InvalidArgumentException('Minimum part must be 15, 30, 45, or 60 minutes');
		}
		if ($planningDays < Defaults::MIN_PLANNING_DAYS || $planningDays > Defaults::MAX_PLANNING_DAYS) {
			throw new \InvalidArgumentException('Planning window must be between ' . Defaults::MIN_PLANNING_DAYS . ' and ' . Defaults::MAX_PLANNING_DAYS . ' days');
		}
		if ($dailyCap < self::DAILY_CAP_MIN || $dailyCap > self::DAILY_CAP_MAX) {
			throw new \InvalidArgumentException('Daily cap must be between ' . self::DAILY_CAP_MIN . ' and ' . self::DAILY_CAP_MAX . ' minutes');
		}
		if ($defaultDuration < self::DEFAULT_DURATION_MIN || $defaultDuration > self::DEFAULT_DURATION_MAX) {
			throw new \InvalidArgumentException('Default duration must be between ' . self::DEFAULT_DURATION_MIN . ' and ' . self::DEFAULT_DURATION_MAX . ' minutes');
		}
		if ($dailyTaskCount < 0 || $dailyTaskCount > self::DAILY_TASK_COUNT_MAX) {
			throw new \InvalidArgumentException('Maximum task blocks per day must be unlimited or between 1 and ' . self::DAILY_TASK_COUNT_MAX);
		}
		if (!in_array($taskGap, self::GAP_CHOICES, true)) {
			throw new \InvalidArgumentException('Task gap must be 0–120 minutes in 15-minute steps');
		}
		if (!in_array($eventBuffer, self::GAP_CHOICES, true)) {
			throw new \InvalidArgumentException('Event buffer must be 0–120 minutes in 15-minute steps');
		}
		if (!in_array($preset, self::PACE_PRESETS, true)) {
			throw new \InvalidArgumentException('Unknown pace preset');
		}
		if (!in_array($autoRescheduleMinutes, self::AUTO_RESCHEDULE_CHOICES, true)) {
			throw new \InvalidArgumentException('Auto-reschedule interval must be off, 5, 15, 30, or 60 minutes');
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DAILY_CAP_MINUTES, (string)$dailyCap);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DAILY_TASK_COUNT, (string)$dailyTaskCount);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DEFAULT_DURATION_MINUTES, (string)$defaultDuration);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_TASK_GAP_MINUTES, (string)$taskGap);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_EVENT_BUFFER_MINUTES, (string)$eventBuffer);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PACE_PRESET, $preset);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_AUTO_RESCHEDULE_MINUTES, (string)$autoRescheduleMinutes);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_PLANNING_DAYS, (string)$planningDays);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_MIN_PART_MINUTES, (string)$minPartMinutes);
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
		return in_array($value, self::AUTO_RESCHEDULE_CHOICES, true) ? $value : 15;
	}

	/**
	 * Paused tasks the user restarted from another client (by adding
	 * `calplan` again) during a re-plan that ran for that client's own write:
	 * their `calplan-review` category is removed by the next review run
	 * instead, so the client's copy of the task stays current.
	 *
	 * @return string[]
	 */
	public function getRestartedTasks(string $userId): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_RESTARTED_TASKS, '[]'), true);
		return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
	}

	public function setTaskRestarted(string $userId, string $taskUid, bool $restarted): void {
		$tasks = array_fill_keys($this->getRestartedTasks($userId), true);
		if ($restarted) {
			$tasks[$taskUid] = true;
		} else {
			unset($tasks[$taskUid]);
		}
		$json = (string)json_encode(array_map('strval', array_keys($tasks)));
		if ($json !== $this->config->getUserValue($userId, Application::APP_ID, self::KEY_RESTARTED_TASKS, '[]')) {
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_RESTARTED_TASKS, $json);
		}
	}

	/**
	 * Block time already spent today by blocks CalPlan removed or moved
	 * (completed tasks, reviewed ones): it still counts against today's limits.
	 *
	 * @return list<array{task: string, day: string, minutes: int}>
	 */
	public function getSpentToday(string $userId, string $today): array {
		$out = [];
		foreach ($this->spentBlocks($userId, $today) as $block) {
			if (is_array($block) && isset($block['task'], $block['minutes'])) {
				$out[] = ['task' => (string)$block['task'], 'day' => $today, 'minutes' => (int)$block['minutes']];
			}
		}
		return $out;
	}

	/**
	 * Remember the time spent today by removed or moved blocks, each once
	 * (by its block key and start).
	 *
	 * @param list<array{task: string, key: string, start: int, day: string, minutes: int}> $blocks
	 */
	public function addSpentToday(string $userId, string $today, array $blocks): void {
		$known = $this->spentBlocks($userId, $today);
		$changed = false;
		foreach ($blocks as $block) {
			if ($block['day'] === $today && $block['minutes'] > 0) {
				$known[$block['key'] . '@' . $block['start']] = ['task' => $block['task'], 'minutes' => $block['minutes']];
				$changed = true;
			}
		}
		if ($changed) {
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_SPENT_TODAY, (string)json_encode(['day' => $today, 'blocks' => (object)$known], JSON_UNESCAPED_SLASHES));
		}
	}

	/** @return array<string, mixed> today's spent blocks by "<block key>@<start>" (older days are dropped) */
	private function spentBlocks(string $userId, string $today): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_SPENT_TODAY, '{}'), true);
		return is_array($decoded) && ($decoded['day'] ?? null) === $today && is_array($decoded['blocks'] ?? null) ? $decoded['blocks'] : [];
	}

	/**
	 * Tasks CalPlan rewrote (review categories) in the last hour, by UID, with
	 * their object URI and when: an open Tasks page reloads them, so that its
	 * next save of such a task is not refused as a conflict.
	 *
	 * @return array<string, array{uri: string, at: int}>
	 */
	public function getRewrittenTasks(string $userId, int $now): array {
		$decoded = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY_REWRITTEN_TASKS, '{}'), true);
		$out = [];
		foreach (is_array($decoded) ? $decoded : [] as $uid => $entry) {
			if (is_array($entry) && (int)($entry['at'] ?? 0) > $now - self::REWRITTEN_TTL_SECONDS) {
				$out[(string)$uid] = ['uri' => (string)($entry['uri'] ?? ''), 'at' => (int)$entry['at']];
			}
		}
		return $out;
	}

	/** @param array<string, string> $tasks object URI by task UID */
	public function addRewrittenTasks(string $userId, array $tasks, int $now): void {
		if ($tasks === []) {
			return;
		}
		$known = $this->getRewrittenTasks($userId, $now);
		foreach ($tasks as $uid => $uri) {
			$known[(string)$uid] = ['uri' => $uri, 'at' => $now];
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_REWRITTEN_TASKS, (string)json_encode((object)$known, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Hold back the re-plan each task write triggers, for at most
	 * REPLAN_HOLD_SECONDS: a client writing many tasks at once (a parent's
	 * Auto-schedule switch) then asks for a single re-plan at the end.
	 */
	public function holdReplans(string $userId, int $now): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_REPLAN_HOLD, (string)($now + self::REPLAN_HOLD_SECONDS));
	}

	public function releaseReplans(string $userId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_REPLAN_HOLD);
	}

	public function areReplansHeld(string $userId, int $now): bool {
		return (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_REPLAN_HOLD, '0') > $now;
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

	/**
	 * The user's timezone: their personal setting, else (as Nextcloud's own
	 * CalDAV code does) the timezone of their availability or their calendars,
	 * else the instance's default_timezone (config.php), else UTC.
	 */
	public function getUserTimeZone(string $userId): \DateTimeZone {
		$candidates = [$this->config->getUserValue($userId, 'core', 'timezone', '')];
		if ($candidates[0] === '' && class_exists(\OCA\DAV\CalDAV\TimezoneService::class)) {
			try {
				$candidates[] = (string)\OCP\Server::get(\OCA\DAV\CalDAV\TimezoneService::class)->getUserTimezone($userId);
			} catch (\Throwable $e) {
				$this->logger?->debug('CalPlan could not read the user timezone from DAV', ['app' => 'calplan', 'exception' => $e]);
			}
		}
		$candidates[] = $this->config->getSystemValueString('default_timezone', '');
		foreach ($candidates as $name) {
			if ($name !== '') {
				try {
					return new \DateTimeZone($name);
				} catch (\Throwable $_) {
				}
			}
		}
		return new \DateTimeZone('UTC');
	}
}