<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReconcileJobCompatibilityTest extends TestCase {
	public function testUsesNamespacedNextcloudAppManagerInterface(): void {
		$source = file_get_contents(__DIR__ . '/../../lib/BackgroundJob/ReconcileJob.php');

		self::assertIsString($source);
		self::assertStringContainsString('use OCP\\App\\IAppManager;', $source);
		self::assertStringNotContainsString('use OCP\\IAppManager;', $source);
	}
}
