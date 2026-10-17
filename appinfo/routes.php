<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OCS routes for calplan. Mounted under
 * /ocs/v2.php/apps/calplan/<url>. The controller class name is used in
 * the route "name" without the "Controller" suffix (Config#method), matching
 * the Nextcloud convention (see e.g. provisioning_api's routes.php).
 */
return [
	'ocs' => [
		['name' => 'Config#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'Config#setFlaggedCalendars', 'url' => '/api/v1/settings/flagged-calendars', 'verb' => 'PUT'],
		['name' => 'Config#setWorkingHours', 'url' => '/api/v1/settings/working-hours', 'verb' => 'PUT'],
		['name' => 'Config#setSchedulingPolicy', 'url' => '/api/v1/settings/scheduling', 'verb' => 'PUT'],
		['name' => 'Config#setExclusions', 'url' => '/api/v1/settings/exclusions', 'verb' => 'PUT'],
		['name' => 'History#setSettings', 'url' => '/api/v1/history/settings', 'verb' => 'PUT'],
		['name' => 'History#export', 'url' => '/api/v1/history/export', 'verb' => 'POST'],
		['name' => 'History#delete', 'url' => '/api/v1/history', 'verb' => 'DELETE'],
		// Trigger a reconciliation pass for the calling user on demand: the
		// Reconcile button, settings saves, and the path the Playwright E2E uses
		// to drive ReconcileService directly.
		['name' => 'Reconcile#reconcileNow', 'url' => '/api/v1/reconcile', 'verb' => 'POST'],
		['name' => 'Reconcile#getStatus', 'url' => '/api/v1/reconcile/status', 'verb' => 'GET'],
		// Hold back the per-write re-plans while the client writes many tasks
		// (a parent's Auto-schedule switch); it then calls /reconcile once.
		['name' => 'Reconcile#hold', 'url' => '/api/v1/reconcile/hold', 'verb' => 'POST'],
	],
];