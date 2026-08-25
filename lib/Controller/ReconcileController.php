<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OCS endpoint to trigger a reconciliation pass for the calling user on demand
 * ("Schedule now"). This exists because the cron-driven first-run sweep can
 * hold the SQLite write lock long enough to block concurrent writers; driving
 * ReconcileService::reconcileUser() directly in the request avoids that and is
 * also the path the Playwright E2E uses to exercise the scheduling pipeline
 * end-to-end without waiting for cron.
 */

namespace OCA\AutoSchedule\Controller;

use OCA\AutoSchedule\Model\Reconciliation;
use OCA\AutoSchedule\Service\ReconcileService;
use OCA\AutoSchedule\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ReconcileController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ReconcileService $reconcileService,
		private IUserSession $userSession,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	/**
	 * Run a reconciliation pass for the calling user now and report how many
	 * working-slot events were (re)created or removed.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{reconciled: int, placed: int, removed: int}, array{}>
	 *
	 * 200: Reconciliation complete
	 */
	#[NoAdminRequired]
	public function reconcileNow(): DataResponse {
		$uid = $this->userId();
		/** @var Reconciliation[] $results */
		$results = $this->reconcileService->reconcileUser($uid, true, 'manual');
		$placed = 0;
		$removed = 0;
		$unscheduled = [];
		foreach ($results as $recon) {
			$placed += count($recon->toPut);
			$removed += count($recon->toDelete);
			foreach ($recon->unscheduled as $reason) {
				$unscheduled[$reason] = ($unscheduled[$reason] ?? 0) + 1;
			}
		}
		$lastRun = $this->configService->getReconcileDiagnostics($uid)[0] ?? null;
		return new DataResponse([
			'reconciled' => count($results),
			'placed' => is_array($lastRun) ? (int)($lastRun['placed'] ?? $placed) : $placed,
			'removed' => is_array($lastRun) ? (int)($lastRun['removed'] ?? $removed) : $removed,
			'unscheduled' => array_sum($unscheduled),
			'unscheduledReasons' => $unscheduled,
			'lastRun' => $lastRun,
		]);
	}

	#[NoAdminRequired]
	public function getStatus(): DataResponse {
		$reports = $this->configService->getReconcileDiagnostics($this->userId());
		return new DataResponse([
			'lastRun' => $reports[0] ?? null,
			'recentRuns' => $reports,
		]);
	}
}