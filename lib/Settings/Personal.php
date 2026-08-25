<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Settings;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\CalendarRepository;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private ConfigService $config,
		private IUserSession $userSession,
		private CalendarRepository $calendars,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'calplan-settings');
		Util::addStyle(Application::APP_ID, 'calplan-settings');
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$policy = $this->config->getSchedulingPolicy($uid);
		$ignoredCalendars = $this->config->getIgnoredCalendars($uid);
		$calendarOptions = [];
		$availableUris = [];
		foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $uid) as $uri => $calendar) {
			$availableUris[(string)$uri] = true;
			$calendarOptions[] = [
				'uri' => (string)$uri,
				'name' => (string)($calendar['{DAV:}displayname'] ?? $calendar['displayname'] ?? $uri),
				'available' => true,
			];
		}
		foreach ($ignoredCalendars as $uri) {
			if (!isset($availableUris[$uri])) {
				$calendarOptions[] = ['uri' => $uri, 'name' => $uri, 'available' => false];
			}
		}
		$parameters = array_merge(
			$policy,
			$this->config->getBehavioralHistorySettings($uid),
			$this->config->getExclusionSettings($uid),
			['reconcileDiagnostics' => $this->config->getReconcileDiagnostics($uid)],
			['calendarOptions' => $calendarOptions],
		);
		return new TemplateResponse(Application::APP_ID, 'settings-personal', $parameters, TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return 'calplan';
	}

	public function getPriority(): int {
		return 10;
	}
}
