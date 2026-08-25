<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * The set of write operations needed to make the calendar match the freshly
 * computed schedule. Producing this is pure; applying it touches the calendar.
 */
class Reconciliation {
	/** @var list<array{Task, Slot}> task + the slot to (re)create its working-slot VEVENT for */
	public array $toPut = [];

	/** @var list<string> task ids whose working-slot VEVENT must be deleted */
	public array $toDelete = [];

	/** @var list<string> task ids whose slot is already correct */
	public array $unchanged = [];

	/** @var array<string,string> task id => machine-readable reason */
	public array $unscheduled = [];

	public function hasChanges(): bool {
		return $this->toPut !== [] || $this->toDelete !== [];
	}
}