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
		/** Whether another task of the calendar is a subtask of this one (TaskLimits::resolve). */
		public readonly bool $hasChildren = false,
		/** The parent's title (TaskLimits::resolve). */
		public readonly ?string $parentTitle = null,
		/** @var string[] */
		public readonly array $childTitles = [],
		public readonly ?string $objectUri = null,
		/** @var string[] */
		public readonly array $categories = [],
		/** This task's own per-day limits for itself and its subtasks (0 = none). */
		public readonly int $dailyLimitMinutes = 0,
		public readonly int $dailyLimitBlocks = 0,
		/**
		 * The limits that bind this task: its own and every ancestor's, resolved
		 * in TaskLimits::resolve.
		 * @var list<array{id: string, minutes: int, blocks: int}>
		 */
		public readonly array $limitGroups = [],
		/** This task's own "split if needed" flag (X-CALPLAN-SPLITTABLE). */
		public readonly bool $splittable = false,
		/** Whether this task or any ancestor allows splitting; resolved with $limitGroups. */
		public readonly bool $canSplit = false,
		/**
		 * UIDs of the parent chain, nearest first; resolved with $limitGroups.
		 * Tasks sharing an ancestor are placed in sequence (SchedulerService).
		 * @var list<string>
		 */
		public readonly array $ancestors = [],
		/**
		 * Position among siblings, as in Tasks' manual order: X-APPLE-SORT-ORDER,
		 * or (like Tasks) seconds from 2001-01-01 to CREATED when unset.
		 */
		public readonly int $sortOrder = 0,
		/** For a subtask: the top ancestor's due date and priority, which rule the whole tree. */
		public readonly ?\DateTimeImmutable $rootDue = null,
		public readonly int $rootPriority = 0,
		/**
		 * Sibling positions from the top ancestor down to this task: the tree's
		 * depth-first order, in which its subtasks are placed one after another.
		 * @var list<int>
		 */
		public readonly array $sequence = [],
		/**
		 * Titles of the parent chain, nearest first (the block's "Parent task" path).
		 * @var list<string>
		 */
		public readonly array $ancestorTitles = [],
		/**
		 * Whether a subtask, at any depth, is itself still to be scheduled
		 * (Auto-schedule on or awaiting review, not done): such a task is a
		 * container whose subtasks get the blocks; otherwise (all done, or none
		 * flagged) it is planned itself.
		 */
		public readonly bool $hasScheduledSubtasks = false,
		/** DTSTART is an all-day DATE: "not before that day", never a time window of its own. */
		public readonly bool $startAllDay = false,
		/** The top ancestor's DTSTART, which ranks the whole tree (like rootDue). */
		public readonly ?\DateTimeImmutable $rootStart = null,
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
			'rootDue' => $this->rootDue?->setTimezone($tz),
			'rootStart' => $this->rootStart?->setTimezone($tz),
		]);
	}

	/** A copy needing *minutes* of block time (what is left of a split task). */
	public function withEstimatedMinutes(int $minutes): self {
		return $this->with(['estimatedMinutes' => $minutes]);
	}

	/** A copy flagged for scheduling (a task of a calendar flagged as a whole). */
	public function withAutoSchedule(): self {
		return $this->with(['autoSchedule' => true]);
	}

	/** The deadline the scheduler honours: a subtask's tree is ruled by its top ancestor's due date. */
	public function schedulingDue(): ?\DateTimeImmutable {
		return $this->ancestors !== [] ? $this->rootDue : $this->due;
	}

	/** The start that ranks the task: a subtask's tree is ranked by its top ancestor's start. */
	public function schedulingStart(): ?\DateTimeImmutable {
		return $this->ancestors !== [] ? $this->rootStart : $this->start;
	}

	/**
	 * The task is never placed before this: the later of its own DTSTART and,
	 * for a subtask, its top ancestor's (a course starting in November keeps
	 * all its lessons there).
	 */
	public function scheduledFrom(): ?\DateTimeImmutable {
		$root = $this->ancestors !== [] ? $this->rootStart : null;
		return $root !== null && ($this->start === null || $root > $this->start) ? $root : $this->start;
	}

	/** The priority weight the scheduler ranks by: a subtask's tree by its top ancestor's priority. */
	public function schedulingWeight(): float {
		return Defaults::priorityWeight($this->ancestors !== [] ? $this->rootPriority : $this->priority);
	}

	/** UID of the top ancestor, or null for a task without parent. */
	public function rootUid(): ?string {
		return $this->ancestors !== [] ? $this->ancestors[count($this->ancestors) - 1] : null;
	}

	/** A copy with the given constructor arguments replaced (by name). */
	private function with(array $changes): self {
		return new self(...array_merge(get_object_vars($this), $changes));
	}

	/**
	 * A copy with its relationships within the calendar (TaskLimits::resolve):
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

	/**
	 * A copy with the settings inherited along the parent chain.
	 *
	 * @param list<array{id: string, minutes: int, blocks: int}> $limitGroups
	 * @param list<string> $ancestors
	 * @param list<int> $sequence
	 * @param list<string> $ancestorTitles
	 */
	public function withInherited(array $limitGroups, bool $canSplit, array $ancestors = [], ?\DateTimeImmutable $rootDue = null, int $rootPriority = 0, array $sequence = [], array $ancestorTitles = [], bool $hasScheduledSubtasks = false, ?\DateTimeImmutable $rootStart = null): self {
		return $this->with([
			'limitGroups' => array_values($limitGroups),
			'canSplit' => $canSplit,
			'ancestors' => array_values($ancestors),
			'rootDue' => $rootDue,
			'rootPriority' => $rootPriority,
			'sequence' => array_values($sequence),
			'ancestorTitles' => array_values($ancestorTitles),
			'hasScheduledSubtasks' => $hasScheduledSubtasks,
			'rootStart' => $rootStart,
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
}
