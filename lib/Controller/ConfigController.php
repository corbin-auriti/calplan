<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OCS settings endpoints for calplan. Exposes the per-calendar
 * auto-schedule toggle (a comma-list of calendar URIs) and the working-hours
 * definition, both stored per-user via OCP\IConfig (no schema). Routes are
 * declared in appinfo/routes.php under the 'ocs' key (mounted at
 * /ocs/v2.php/apps/calplan/api/v1/...); the controller just implements
 * the methods. Extends OCP\AppFramework\OCSController.
 */

namespace OCA\AutoSchedule\Controller;

use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\WorkingHoursService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ConfigController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	/**
	 * Get the caller's auto-schedule settings (flagged calendars + working hours).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{flaggedCalendars: list<string>, workingHours: array<string, array{start: string, end: string}>}, array{}>
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	public function getSettings(): DataResponse {
		$uid = $this->userId();
		return new DataResponse([
			'flaggedCalendars' => $this->configService->getFlaggedCalendars($uid),
			'workingHours' => WorkingHoursService::toMap($this->configService->getWorkingHours($uid)),
			'scheduling' => $this->configService->getSchedulingPolicy($uid),
			'exclusions' => $this->configService->getExclusionSettings($uid),
		]);
	}

	/**
	 * Set the list of calendar URIs to auto-schedule wholesale.
	 *
	 * @param string[] $calendars calendar URIs
	 * @return DataResponse<Http::STATUS_OK, array{flaggedCalendars: list<string>}, array{}>
	 *
	 * 200: Flagged calendars stored
	 */
	#[NoAdminRequired]
	public function setFlaggedCalendars(array $calendars = []): DataResponse {
		$uid = $this->userId();
		$uris = array_values(array_unique(array_filter(array_map('strval', $calendars), fn ($s) => $s !== '')));
		$this->configService->setFlaggedCalendars($uid, $uris);
		return new DataResponse(['flaggedCalendars' => $uris]);
	}

	/**
	 * Set the working-hours definition (weekday key -> {start, end} as HH:MM).
	 *
	 * @param array<string, array{start: string, end: string}> $workingHours
	 * @return DataResponse<Http::STATUS_OK, array{workingHours: array<string, array{start: string, end: string}>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 *
	 * 200: Working hours stored
	 * 400: Invalid working-hours map
	 */
	#[NoAdminRequired]
	public function setWorkingHours(array $workingHours = []): DataResponse {
		$uid = $this->userId();
		$error = WorkingHoursService::validateMap($workingHours);
		if ($error !== null) {
			return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}
		$this->configService->setWorkingHours($uid, $workingHours);
		return new DataResponse(['workingHours' => $workingHours]);
	}

	/**
	 * Set the scheduling policy. A setting left out of the request keeps its
	 * current value (an older settings page does not send the newer ones).
	 */
	#[NoAdminRequired]
	public function setSchedulingPolicy(
		?int $dailyCapMinutes = null,
		?int $defaultDurationMinutes = null,
		?int $taskGapMinutes = null,
		?int $eventBufferMinutes = null,
		?string $pacePreset = null,
		?int $autoRescheduleMinutes = null,
		?int $dailyTaskCount = null,
		?int $planningDays = null,
		?int $minPartMinutes = null,
	): DataResponse {
		$uid = $this->userId();
		$current = $this->configService->getSchedulingPolicy($uid);
		try {
			$this->configService->setSchedulingPolicy(
				$uid,
				$dailyCapMinutes ?? $current['dailyCapMinutes'],
				$defaultDurationMinutes ?? $current['defaultDurationMinutes'],
				$taskGapMinutes ?? $current['taskGapMinutes'],
				$eventBufferMinutes ?? $current['eventBufferMinutes'],
				$pacePreset ?? $current['pacePreset'],
				$autoRescheduleMinutes ?? $current['autoRescheduleMinutes'],
				$dailyTaskCount ?? $current['dailyTaskCount'],
				$planningDays ?? $current['planningDays'],
				$minPartMinutes ?? $current['minPartMinutes'],
			);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->configService->getSchedulingPolicy($uid));
	}

	/** @param string[] $ignoredCalendars @param string[] $ignoredEventTitles */
	#[NoAdminRequired]
	public function setExclusions(
		array $ignoredCalendars = [],
		array $ignoredEventTitles = [],
		string $eventTitleMatchMode = 'exact',
	): DataResponse {
		$uid = $this->userId();
		try {
			$this->configService->setExclusionSettings($uid, $ignoredCalendars, $ignoredEventTitles, $eventTitleMatchMode);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->configService->getExclusionSettings($uid));
	}
}