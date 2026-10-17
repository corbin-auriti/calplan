<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OCS endpoints to run a reconciliation pass for the calling user on demand
 * (the Reconcile button, settings saves, the Tasks page after a batch of
 * writes) and to read the latest run's status. Driving
 * ReconcileService::reconcileUser() directly in the request is also the path
 * the Playwright E2E uses to exercise the scheduling pipeline end-to-end
 * without waiting for cron.
 */

namespace OCA\AutoSchedule\Controller;

use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\ReconcileService;
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
	 * Run a reconciliation pass for the calling user now (ending any hold of
	 * the per-write re-plans) and report how many working-slot events were
	 * (re)created or removed by this very run.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{reconciled: int, placed: int, removed: int, unscheduled: int}, array{}>
	 *
	 * 200: Reconciliation complete
	 */
	#[NoAdminRequired]
	public function reconcileNow(): DataResponse {
		$uid = $this->userId();
		$this->configService->releaseReplans($uid);
		$results = $this->reconcileService->reconcileUser($uid, true, 'manual');
		$report = $this->reconcileService->lastReport();
		return new DataResponse([
			'reconciled' => count($results),
			// Null when the run was skipped because another one kept running.
			'ran' => $report !== null,
			'placed' => (int)($report['placed'] ?? 0),
			'removed' => (int)($report['removed'] ?? 0),
			'unscheduled' => (int)($report['unscheduled'] ?? 0),
			'unscheduledReasons' => (object)($report['unscheduledReasons'] ?? []),
			'lastRun' => $report ?? ($this->configService->getReconcileDiagnostics($uid)[0] ?? null),
			'unscheduledTasks' => (object)$this->configService->getUnscheduledTasks($uid),
			'plannedTasks' => (object)$this->configService->getPlannedTasks($uid),
			'rewrittenTasks' => (object)$this->configService->getRewrittenTasks($uid, time()),
		]);
	}

	/**
	 * Hold back the re-plan that each task write triggers, for at most
	 * ConfigService::REPLAN_HOLD_SECONDS, while the client writes many tasks
	 * at once; it then calls reconcileNow() once.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{heldSeconds: int}, array{}>
	 */
	#[NoAdminRequired]
	public function hold(): DataResponse {
		$this->configService->holdReplans($this->userId(), time());
		return new DataResponse(['heldSeconds' => ConfigService::REPLAN_HOLD_SECONDS]);
	}

	#[NoAdminRequired]
	public function getStatus(): DataResponse {
		$uid = $this->userId();
		$reports = $this->configService->getReconcileDiagnostics($uid);
		return new DataResponse([
			'lastRun' => $reports[0] ?? null,
			'recentRuns' => $reports,
			// Task UID => {reason, uri}: what the Tasks UI flags as not scheduled.
			'unscheduledTasks' => (object)$this->configService->getUnscheduledTasks($uid),
			// Task UID => {uri, blocks}: when the Tasks UI says a task is planned.
			'plannedTasks' => (object)$this->configService->getPlannedTasks($uid),
			// Task UID => {uri, at}: tasks CalPlan rewrote, which an open Tasks page reloads.
			'rewrittenTasks' => (object)$this->configService->getRewrittenTasks($uid, time()),
		]);
	}
}
