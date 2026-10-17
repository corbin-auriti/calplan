<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CalPlan — places flagged tasks (CATEGORIES:calplan) into the
 * earliest free working-hours slot on the user's calendar, respecting each
 * task's start and due dates. Runs as a Nextcloud background job on cron.
 */

namespace OCA\AutoSchedule\AppInfo;

use OCA\AutoSchedule\Listener\CalendarObjectChangeListener;
use OCA\AutoSchedule\Listener\UserDeletedListener;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'calplan';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	/** @psalm-suppress UndefinedClass */
	public function register(IRegistrationContext $context): void {
		// The cron-driven reconciliation pass is declared in appinfo/info.xml
		// (<background-jobs>) rather than registered here: NC's
		// IRegistrationContext has no registerBackgroundJob() method.

		// Re-plan on local VTODO changes, once per user at the end of the
		// request (the listener filters these broad public OCP events by object
		// component and skips users with nothing to plan). Task edits thus move
		// blocks before the client gets its answer. VEVENT changes are picked up
		// by cron or an on-demand reconcile.
		$context->registerEventListener(CalendarObjectCreatedEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, CalendarObjectChangeListener::class);
		// NC trashes rather than hard-deletes by default (retention != 0), dispatching
		// CalendarObjectMovedToTrashEvent instead of ...DeletedEvent - handle both,
		// plus restores from the trash bin and moves between calendars.
		$context->registerEventListener(CalendarObjectMovedToTrashEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectRestoredEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectMovedEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	/** @psalm-suppress UndefinedClass */
	public function boot(IBootContext $context): void {
		// Inject the frontend only into Tasks and Calendar, for a logged-in user
		// the app is enabled for (public calendar pages, /p/ and /embed/, have
		// no CalPlan to talk to). Their navigation action uses Nextcloud's shared
		// #app-navigation-vue structure, which is also present in Files and
		// Settings; global asset loading would therefore leak the action into
		// unrelated apps. The script repeats this route guard client-side as a
		// defense against webroot/routing differences. On cron/occ (\OC::$CLI)
		// there is no template to inject into, so skip it.
		if (class_exists('\\OC', false) && !empty(\OC::$CLI)) {
			return;
		}
		$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$webroot = class_exists('\\OC', false) ? (string)\OC::$WEBROOT : '';
		if (preg_match('#^' . preg_quote($webroot, '#') . '(?:/index\.php)?/apps/(?:tasks|calendar)(?:/(?!p/|embed/|appointment/)|$)#', $path) !== 1) {
			return;
		}
		$context->injectFn(function (IUserSession $session, IAppManager $appManager): void {
			$user = $session->getUser();
			if ($user === null || !$appManager->isEnabledForUser(self::APP_ID, $user)) {
				return;
			}
			\OCP\Util::addScript(self::APP_ID, 'calplan-tasks');
			\OCP\Util::addStyle(self::APP_ID, 'calplan-tasks');
		});
	}
}