<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(private IURLGenerator $urlGenerator) {
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('calplan', 'calplan-sidebar-icon.svg');
	}

	public function getID(): string {
		return 'calplan';
	}

	public function getName(): string {
		return 'CalPlan';
	}

	public function getPriority(): int {
		return 45;
	}
}
