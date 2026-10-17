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

	/** @var list<string> task UIDs whose working-slot VEVENT must be deleted */
	public array $toDelete = [];

	/** @var list<string> task UIDs whose slot is already correct */
	public array $unchanged = [];

	/** @var array<string,string> task id => machine-readable reason */
	public array $unscheduled = [];

	/**
	 * Object URIs of the existing blocks, by task UID: an existing block is
	 * updated or deleted where it is stored, whatever its file name.
	 *
	 * @var array<string,string>
	 */
	public array $objectUris = [];

	/**
	 * Blocks that started today and that this plan deletes or moves, with the
	 * minutes of them already past: that time still counts against today's
	 * limits, so the caller remembers it once the write succeeded.
	 *
	 * @var list<array{task: string, start: int, day: string, minutes: int}>
	 */
	public array $spent = [];

	public function hasChanges(): bool {
		return $this->toPut !== [] || $this->toDelete !== [];
	}
}