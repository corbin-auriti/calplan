<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal PSR-4 autoloader so the unit tests run without Composer / Nextcloud /
 * Sabre. The pure PHP classes live under lib/; the shared test base lives under
 * tests/. The IcalCodec tests skip themselves if Sabre\VObject is absent.
 */

$testPrefix = 'OCA\\AutoSchedule\\Tests\\';
$libPrefix = 'OCA\\AutoSchedule\\';

spl_autoload_register(function (string $class) use ($testPrefix, $libPrefix): void {
	if (strncmp($class, $testPrefix, strlen($testPrefix)) === 0) {
		$relative = substr($class, strlen($testPrefix));
		$file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
		return;
	}
	if (strncmp($class, $libPrefix, strlen($libPrefix)) === 0) {
		$relative = substr($class, strlen($libPrefix));
		$file = __DIR__ . '/../lib/' . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});