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
		/** Whether another task of the calendar is a subtask of this one (CalendarRepository::getTasks). */
		public readonly bool $hasChildren = false,
		/** The parent's title (CalendarRepository::getTasks). */
		public readonly ?string $parentTitle = null,
		/** @var string[] */
		public readonly array $childTitles = [],
		public readonly ?string $objectUri = null,
		/** @var string[] */
		public readonly array $categories = [],
		/** DTSTART is an all-day DATE: "not before that day", never a time window of its own. */
		public readonly bool $startAllDay = false,
		/** The VTODO's CLASS (PUBLIC, PRIVATE, CONFIDENTIAL), mirrored onto its blocks. */
		public readonly ?string $classification = null,
	) {
	}

	/**
	 * A copy with every date in *tz* (the user's): the same instants, so that
	 * the scheduler's day arithmetic always works in the user's days.
	 */
	public function withTimesIn(\DateTimeZone $tz): self {
		return $this->with([
			'due' => $this->due?->setTimezone($tz),
			'start' => $this->start?->setTimezone($tz),
		]);
	}

	/** A copy flagged for scheduling (a task of a calendar flagged as a whole). */
	public function withAutoSchedule(): self {
		return $this->with(['autoSchedule' => true]);
	}

	/** A copy with the given constructor arguments replaced (by name). */
	private function with(array $changes): self {
		return new self(...array_merge(get_object_vars($this), $changes));
	}

	/**
	 * A copy with its relationships within the calendar (CalendarRepository::getTasks):
	 * the parent's title and the subtasks' titles.
	 *
	 * @param string[] $childTitles
	 */
	public function withRelationships(?string $parentTitle, array $childTitles): self {
		return $this->with([
			'hasChildren' => $childTitles !== [],
			'parentTitle' => $parentTitle,
			'childTitles' => array_values($childTitles),
		]);
	}

	/** @param string[] $categories */
	public function withSource(string $calendarUri, string $objectUri, array $categories): self {
		return $this->with([
			'autoSchedule' => self::hasCategory($categories, Defaults::FLAG_CATEGORY),
			'calendarUri' => $calendarUri,
			'objectUri' => $objectUri,
			'categories' => array_values($categories),
		]);
	}

	/** @param string[] $categories */
	public function withCategories(array $categories): self {
		return $this->withSource($this->calendarUri ?? '', $this->objectUri ?? '', $categories);
	}

	public function needsReview(): bool {
		return self::hasCategory($this->categories, Defaults::REVIEW_CATEGORY);
	}

	/**
	 * Whether *categories* hold *category*. Like the Tasks UI, the match ignores
	 * case and surrounding spaces ("CalPlan", or " calplan" from "work, calplan").
	 *
	 * @param string[] $categories
	 */
	public static function hasCategory(array $categories, string $category): bool {
		foreach ($categories as $value) {
			if (self::sameCategory((string)$value, $category)) {
				return true;
			}
		}
		return false;
	}

	public static function sameCategory(string $value, string $category): bool {
		return strtolower(trim($value)) === strtolower($category);
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
		if (!self::hasCategory($categories, Defaults::REVIEW_CATEGORY)) {
			$categories[] = Defaults::REVIEW_CATEGORY;
		}
		if (!$autoReschedule) {
			$categories = array_values(array_filter($categories, fn (string $category) => !self::sameCategory($category, Defaults::FLAG_CATEGORY)));
		}
		return $categories;
	}

	/**
	 * Categories without the review marker, for a paused task the user
	 * restarted by adding `calplan` again.
	 *
	 * @return string[]
	 */
	public function categoriesWithoutReview(): array {
		return array_values(array_filter($this->categories, fn (string $category) => !self::sameCategory($category, Defaults::REVIEW_CATEGORY)));
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
