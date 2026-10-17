<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Cron-driven reconciliation pass, declared in appinfo/info.xml. On each tick
 * it reconciles every user for whom the app is enabled and who has something
 * to plan or review; per-user work is bounded (calendars with nothing to do are
 * skipped inside ReconcileService).
 */

namespace OCA\AutoSchedule\BackgroundJob;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Service\ConfigService;
use OCA\AutoSchedule\Service\ReconcileService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ReconcileJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IUserManager $userManager,
		private IAppManager $appManager,
		private ReconcileService $reconcileService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Every 5 minutes. The first run after the previous one completes.
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->userManager->callForSeenUsers(function (IUser $user): void {
			$uid = $user->getUID();
			if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
				return;
			}
			// A user whose last reconcile had no task to schedule and no block
			// has nothing to review either; flagging a task re-plans at once
			// (CalendarObjectChangeListener), so cron can leave them alone.
			if ($this->configService->hasPlanState($uid) && !$this->configService->isPlanActive($uid)
				&& $this->configService->getFlaggedCalendars($uid) === []) {
				return;
			}
			try {
				$now = new \DateTimeImmutable('now', $this->configService->getUserTimeZone($uid));
				$processReview = $this->configService->isReviewCheckDue($uid, $now);
				// Never wait for a run in progress: the next tick comes soon enough.
				$this->reconcileService->reconcileUser($uid, $processReview, 'background', 0);
				// lastReport() is null when the run was skipped (another one was running).
				if ($processReview && $this->reconcileService->lastReport() !== null) {
					$this->configService->markReviewChecked($uid, $now);
				}
			} catch (\Throwable $e) {
				$this->logger->error('CalPlan reconcile failed for user ' . $uid . ': ' . $e->getMessage(), [
					'exception' => $e,
				]);
			}
		});
	}
}