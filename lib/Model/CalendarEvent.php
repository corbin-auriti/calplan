<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * A VEVENT already on the calendar.
 *
 * ``autoScheduleTaskUid`` is set on the working-slot events *we* created
 * (UID `calplan-<taskUID>`) so the reconciler can tell its own blocks apart
 * from real busy events and re-derive them each run.
 */
class CalendarEvent {
	public function __construct(
		public readonly string $uid,
		public readonly \DateTimeImmutable $start,
		public readonly \DateTimeImmutable $end,
		public readonly ?string $autoScheduleTaskUid = null,
		public readonly ?string $summary = null,
		public readonly ?string $description = null,
		public readonly ?string $location = null,
		public readonly ?string $url = null,
		public readonly ?int $priority = null,
		public readonly ?int $percentComplete = null,
		public readonly ?string $transp = null,
	) {
	}

	public function toBusy(): BusyBlock {
		return new BusyBlock($this->start, $this->end, $this->uid);
	}
}