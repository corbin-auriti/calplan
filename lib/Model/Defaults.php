<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * Tunable defaults (ADHD-friendly) + the priority→weight map + the well-known
 * category/UID strings shared by the whole app.
 */
class Defaults {
	/** Snap all slot starts/ends to a 15-min grid. */
	public const SLOT_GRANULARITY_MINUTES = 15;

	/** A task with no estimate still schedules; 30 min is always slotable. */
	public const DEFAULT_ESTIMATE_MINUTES = 30;

	/** Never place more than 5h of task blocks per day. */
	public const DEFAULT_DAILY_CAP_MINUTES = 5 * 60;

	/** Plan at most two weeks ahead. */
	public const LOOKAHEAD_DAYS = 14;

	/**
	 * Tasks with no due date sort after every dated task but still get placed:
	 * ranked as if due this many days ahead, far beyond any planning window.
	 */
	public const NO_DUE_HORIZON_DAYS = 3650;

	/** The standard iCalendar CATEGORIES value that flags a task for scheduling. */
	public const FLAG_CATEGORY = 'calplan';

	/** Standard task category marking an expired block for user review. */
	public const REVIEW_CATEGORY = 'calplan-review';

	/** The standard CATEGORIES value we put on the working-slot VEVENTs we create. */
	public const SLOT_CATEGORY = 'calplan-slot';

	/** UID prefix of the working-slot VEVENTs we create. */
	public const SLOT_UID_PREFIX = 'calplan-';

	/**
	 * Map a VTODO priority (1 highest … 9 lowest, 0 = no priority) to a
	 * scheduling weight that falls with the priority: Nextcloud Tasks writes
	 * high = 1, medium = 5 and low = 9. No priority (0, plan 0.9.1: an absent
	 * PRIORITY is parsed as 0, not 5) ranks like medium, so an un-prioritised
	 * task neither jumps the queue of high-priority work nor sinks below tasks
	 * explicitly marked low.
	 */
	public static function priorityWeight(int $priority): float {
		return match ($priority) {
			1 => 5.0,
			2 => 4.0,
			3 => 3.0,
			4 => 2.0,
			6 => 0.8,
			7 => 0.6,
			8 => 0.4,
			9 => 0.2,
			default => 1.0,
		};
	}
}