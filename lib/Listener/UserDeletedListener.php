<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Listener;

use OCA\AutoSchedule\Service\BehavioralHistoryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * A deleted account's scheduling history (in the app data) goes with it;
 * Nextcloud removes its preferences itself.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private BehavioralHistoryService $history,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		try {
			$this->history->delete($event->getUser()->getUID());
		} catch (\Throwable $e) {
			$this->logger->warning('CalPlan could not delete the history of a deleted user', ['app' => 'calplan', 'exception' => $e]);
		}
	}
}
