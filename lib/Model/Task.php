<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Model;

/**
 * A VTODO task the scheduler may place on the calendar.
 */
class Task {
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly int $priority = 0,
		public readonly ?\DateTimeImmutable $due = null,
		public readonly ?\DateTimeImmutable $start = null,
		public readonly int $estimatedMinutes = Defaults::DEFAULT_ESTIMATE_MINUTES,
		public readonly bool $autoSchedule = false,
		public readonly string $status = 'NEEDS-ACTION',
		public readonly ?string $description = null,
		public readonly ?string $location = null,
		public readonly ?string $url = null,
		public readonly ?int $percentComplete = null,
		public readonly ?string $calendarUri = null,
		public readonly ?string $principalUri = null,
		public readonly ?string $parentUid = null,
		public readonly bool $hasChildren = false,
		public readonly ?string $parentTitle = null,
		/** @var string[] */
		public readonly array $childTitles = [],
		public readonly ?string $objectUri = null,
		/** @var string[] */
		public readonly array $categories = [],
	) {
	}

	/**
	 * A copy with $hasChildren set. parent/child is resolved across the whole
	 * calendar's task set in CalendarRepository::getTasks (a task "has children"
	 * when another VTODO points at it via RELATED-TO;RELTYPE=PARENT), so this is
	 * populated after construction. Used by IcalCodec::slotContent for the ↴/↳
	 * relation marker in the slot SUMMARY (plan 0.9.1).
	 */
	public function withHasChildren(bool $hasChildren): self {
		return new self(
			$this->id, $this->title, $this->priority, $this->due, $this->start,
			$this->estimatedMinutes, $this->autoSchedule, $this->status,
			$this->description, $this->location, $this->url, $this->percentComplete,
			$this->calendarUri, $this->principalUri, $this->parentUid, $hasChildren,
			$this->parentTitle, $this->childTitles, $this->objectUri, $this->categories,
		);
	}

	/** @param string[] $childTitles */
	public function withRelationships(?string $parentTitle, array $childTitles): self {
		return new self(
			$this->id, $this->title, $this->priority, $this->due, $this->start,
			$this->estimatedMinutes, $this->autoSchedule, $this->status,
			$this->description, $this->location, $this->url, $this->percentComplete,
			$this->calendarUri, $this->principalUri, $this->parentUid,
			$childTitles !== [], $parentTitle, array_values($childTitles),
			$this->objectUri, $this->categories,
		);
	}

	/** @param string[] $categories */
	public function withSource(string $calendarUri, string $objectUri, array $categories): self {
		return new self(
			$this->id, $this->title, $this->priority, $this->due, $this->start,
			$this->estimatedMinutes, in_array(Defaults::FLAG_CATEGORY, $categories, true), $this->status,
			$this->description, $this->location, $this->url, $this->percentComplete,
			$calendarUri, $this->principalUri, $this->parentUid, $this->hasChildren,
			$this->parentTitle, $this->childTitles, $objectUri, array_values($categories),
		);
	}

	/** @param string[] $categories */
	public function withCategories(array $categories): self {
		return $this->withSource($this->calendarUri ?? '', $this->objectUri ?? '', $categories);
	}

	public function needsReview(): bool {
		return in_array(Defaults::REVIEW_CATEGORY, $this->categories, true);
	}

	/**
	 * Categories after an expired-slot review transition. Unrelated user tags are
	 * preserved. Disabled automatic rescheduling removes only CalPlan's schedule
	 * category; the review category remains filterable until the user removes it.
	 *
	 * @return string[]
	 */
	public function reviewCategories(bool $autoReschedule): array {
		$categories = array_values(array_unique($this->categories));
		if (!in_array(Defaults::REVIEW_CATEGORY, $categories, true)) {
			$categories[] = Defaults::REVIEW_CATEGORY;
		}
		if (!$autoReschedule) {
			$categories = array_values(array_filter($categories, fn (string $category) => $category !== Defaults::FLAG_CATEGORY));
		}
		return $categories;
	}

	public function isDone(): bool {
		$s = strtoupper($this->status);
		return $s === 'COMPLETED'
			|| $s === 'CANCELLED'
			|| ($this->percentComplete !== null && $this->percentComplete >= 100);
	}

	public function weight(): float {
		return Defaults::priorityWeight($this->priority);
	}
}