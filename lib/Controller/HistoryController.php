<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Controller;

use OCA\AutoSchedule\Service\BehavioralHistoryService;
use OCA\AutoSchedule\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class HistoryController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private BehavioralHistoryService $history,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function setSettings(bool $enabled = false, int $retentionDays = 180): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$this->config->setBehavioralHistorySettings($uid, $enabled, $retentionDays);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->config->getBehavioralHistorySettings($uid));
	}

	#[NoAdminRequired]
	public function export(): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return new DataResponse($this->history->export($uid));
	}

	#[NoAdminRequired]
	public function delete(): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->history->delete($uid);
		return new DataResponse(['deleted' => true]);
	}
}
