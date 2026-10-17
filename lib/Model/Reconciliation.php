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
	/**
	 * Blocks to create or update: [task, slot, part number, part count]; part
	 * number and count are null for a whole (unsplit) block.
	 *
	 * @var list<array{0: Task, 1: Slot, 2: ?int, 3: ?int}>
	 */
	public array $toPut = [];

	/** @var list<string> block keys ("<task uid>" or "<task uid>~p<n>") whose block must be deleted */
	public array $toDelete = [];

	/** @var list<string> block keys whose block is already correct */
	public array $unchanged = [];

	/** @var array<string,string> task id => machine-readable reason */
	public array $unscheduled = [];

	/**
	 * Object URIs of the existing blocks, by block key: an existing block is
	 * updated or deleted where it is stored, whatever its file name.
	 *
	 * @var array<string,string>
	 */
	public array $objectUris = [];

	/**
	 * Every desired block of each placed task, by task id, in time order
	 * (changed or not): what a task's plan is after this run.
	 *
	 * @var array<string, list<Slot>>
	 */
	public array $blocks = [];

	/**
	 * Blocks that started today and that this plan deletes or moves, with the
	 * minutes of them already past: that time still counts against today's
	 * limits, so the caller remembers it once the write succeeded.
	 *
	 * @var list<array{task: string, key: string, start: int, day: string, minutes: int}>
	 */
	public array $spent = [];

	public function hasChanges(): bool {
		return $this->toPut !== [] || $this->toDelete !== [];
	}
}