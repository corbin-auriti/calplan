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
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;

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

		// Reconcile synchronously on local VTODO changes (the listener filters
		// these broad public OCP events by object component). This keeps completion,
		// date edits and deletes immediate without making an ordinary VEVENT save
		// wait for a full-user schedule. VEVENT changes are picked up by cron or an
		// on-demand reconcile; a deduplicated deferred job is the scale follow-up.
		$context->registerEventListener(CalendarObjectCreatedEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, CalendarObjectChangeListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, CalendarObjectChangeListener::class);
		// NC trashes rather than hard-deletes by default (retention != 0), dispatching
		// CalendarObjectMovedToTrashEvent instead of ...DeletedEvent - handle both.
		$context->registerEventListener(CalendarObjectMovedToTrashEvent::class, CalendarObjectChangeListener::class);
	}

	/** @psalm-suppress UndefinedClass */
	public function boot(IBootContext $context): void {
		// Inject the frontend only into Tasks and Calendar. Their navigation action
		// uses Nextcloud's shared #app-navigation-vue structure, which is also present
		// in Files and Settings; global asset loading would therefore leak the action
		// into unrelated apps. The script repeats this route guard client-side as a
		// defense against webroot/routing differences. On cron/occ (\OC::$CLI) there
		// is no template to inject into, so skip it.
		if (class_exists('\\OC', false) && !empty(\OC::$CLI)) {
			return;
		}
		$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
		if (preg_match('#/(?:index\.php/)?apps/(?:tasks|calendar)(?:/|$|\?)#', $requestUri) !== 1) {
			return;
		}
		\OCP\Util::addScript('calplan', 'calplan-tasks');
		\OCP\Util::addStyle('calplan', 'calplan-tasks');
	}
}