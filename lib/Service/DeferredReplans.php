<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

use Psr\Log\LoggerInterface;

/**
 * Re-plans asked for while a request writes tasks, run once per user when
 * the request ends (CalendarObjectChangeListener). Running them then, rather
 * than inside the event, keeps them out of CalDavBackend's write transaction
 * (whose sync token another write may be updating), and an import or any
 * other request writing many tasks costs one re-plan instead of one per task.
 * The response is still sent after the re-plan, so a client reading the plan
 * right after its write sees the new one.
 */
final class DeferredReplans {
	/** @var array<string, string> trigger by user id */
	private static array $pending = [];
	private static bool $registered = false;

	public function __construct(
		private ReconcileService $reconcileService,
		private LoggerInterface $logger,
	) {
	}

	public function add(string $userId, string $trigger): void {
		self::$pending[$userId] = $trigger;
		if (!self::$registered) {
			self::$registered = true;
			register_shutdown_function([$this, 'flush']);
		}
	}

	/** Run the pending re-plans (at the end of the request). */
	public function flush(): void {
		while (self::$pending !== []) {
			$userId = (string)array_key_first(self::$pending);
			$trigger = self::$pending[$userId];
			unset(self::$pending[$userId]);
			try {
				$this->reconcileService->reconcileUser($userId, false, $trigger);
			} catch (\Throwable $e) {
				// Best-effort: the next cron pass or Reconcile recovers.
				$this->logger->error('CalPlan: re-plan after a calendar change for {user} failed: {msg}', [
					'app' => 'calplan',
					'user' => $userId,
					'msg' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}
}
