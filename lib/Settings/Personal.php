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
		$principalUri = 'principals/users/' . $uid;
		foreach ($this->calendars->getCalendarsForPrincipal($principalUri) as $uri => $calendar) {
			$availableUris[(string)$uri] = true;
			$calendarOptions[] = [
				'uri' => (string)$uri,
				'name' => (string)($calendar['{DAV:}displayname'] ?? $calendar['displayname'] ?? $uri),
				'available' => true,
				// Shared with the user: only its events count (as busy time).
				'kind' => CalendarRepository::isOwnedBy($calendar, $principalUri) ? 'own' : 'shared',
			];
		}
		// Subscriptions and federated calendars: busy time only.
		foreach ($this->calendars->getBusyOnlyCalendars($principalUri) as $key => $calendar) {
			$availableUris[(string)$key] = true;
			$calendarOptions[] = ['uri' => (string)$key, 'name' => $calendar['name'], 'available' => true, 'kind' => 'subscription'];
		}
		foreach ($ignoredCalendars as $uri) {
			if (!isset($availableUris[$uri])) {
				$calendarOptions[] = ['uri' => $uri, 'name' => $uri, 'available' => false, 'kind' => 'own'];
			}
		}
		$parameters = array_merge(
			$policy,
			$this->config->getBehavioralHistorySettings($uid),
			$this->config->getExclusionSettings($uid),
			['reconcileDiagnostics' => $this->config->getReconcileDiagnostics($uid)],
			['calendarOptions' => $calendarOptions],
			// The choices and bounds the server accepts, so the form offers exactly those.
			[
				'gapChoices' => ConfigService::GAP_CHOICES,
				'dailyCapMin' => ConfigService::DAILY_CAP_MIN,
				'dailyCapMax' => ConfigService::DAILY_CAP_MAX,
				'dailyTaskCountMax' => ConfigService::DAILY_TASK_COUNT_MAX,
				'defaultDurationMin' => ConfigService::DEFAULT_DURATION_MIN,
				'defaultDurationMax' => ConfigService::DEFAULT_DURATION_MAX,
			],
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
