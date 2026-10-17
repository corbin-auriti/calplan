<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\Model\Task;

/**
 * A task tree is planned as a whole. Settings set on a task apply to the task
 * and all its subtasks: per-day limits (a course limited to 2 h and 3 blocks a
 * day caps the sum of its lessons' blocks) and "split if needed". The top
 * task's due date, start and priority rule the whole tree, and the subtasks
 * follow each other in the tree's depth-first order, siblings in Tasks'
 * manual order.
 */
final class TaskLimits {
	/**
	 * Attach to every task its relationships within the calendar and what it
	 * inherits along its RELATED-TO parent chain: the parent's and subtasks'
	 * titles, the limits of itself and each ancestor, whether itself or any
	 * ancestor allows splitting, the ancestors' UIDs and titles, the top
	 * ancestor's due date, start and priority, its position in the tree, and
	 * whether it is a container.
	 *
	 * A parent link that closes a cycle (A -> B -> A, or a task naming itself)
	 * is ignored, so every task of the cycle is planned on its own. A
	 * completed ancestor ends the chain: a finished course no longer rules or
	 * limits the lessons left open under it.
	 *
	 * @param Task[] $tasks
	 * @return Task[]
	 */
	public static function resolve(array $tasks): array {
		$byUid = [];
		foreach ($tasks as $task) {
			$byUid[$task->id] = $task;
		}
		$parentOf = self::acyclicParents($tasks, $byUid);
		$children = [];
		foreach ($tasks as $task) {
			if (isset($parentOf[$task->id])) {
				$children[$parentOf[$task->id]][] = $task;
			}
		}
		// Siblings: Tasks' manual order (X-APPLE-SORT-ORDER, else creation
		// time), then the title. A subtask's own due date and priority play no
		// part: only the top task's rule the tree.
		$position = [];
		foreach ($children as $parent => $siblings) {
			usort($siblings, function (Task $a, Task $b): int {
				$bySort = $a->sortOrder <=> $b->sortOrder;
				return $bySort !== 0 ? $bySort : strcmp($a->title . "\0" . $a->id, $b->title . "\0" . $b->id);
			});
			$children[$parent] = $siblings;
			foreach ($siblings as $i => $sibling) {
				$position[$sibling->id] = $i;
			}
		}

		$scheduledBelow = [];
		$out = [];
		foreach ($tasks as $task) {
			$groups = [];
			$canSplit = false;
			$ancestors = [];
			$ancestorTitles = [];
			$sequence = [];
			$root = $task;
			for ($node = $task; $node !== null; $node = isset($parentOf[$node->id]) ? $byUid[$parentOf[$node->id]] : null) {
				if ($node !== $task && $node->isDone()) {
					break;
				}
				$root = $node;
				if ($node !== $task) {
					$ancestors[] = $node->id;
					$ancestorTitles[] = $node->title;
				}
				if ($node->dailyLimitMinutes > 0 || $node->dailyLimitBlocks > 0) {
					$groups[] = ['id' => $node->id, 'minutes' => $node->dailyLimitMinutes, 'blocks' => $node->dailyLimitBlocks];
				}
				$canSplit = $canSplit || $node->splittable;
			}
			// The path from the top (open) ancestor down: each level's sibling position.
			for ($node = $task; $node !== $root; $node = $byUid[$parentOf[$node->id]]) {
				array_unshift($sequence, $position[$node->id]);
			}
			$parentTitle = isset($parentOf[$task->id]) ? $byUid[$parentOf[$task->id]]->title : null;
			$related = $task->withRelationships($parentTitle, array_map(fn (Task $child) => $child->title, $children[$task->id] ?? []));
			$hasScheduledSubtasks = self::hasScheduledBelow($task->id, $children, $scheduledBelow);
			$out[] = $groups === [] && !$canSplit && $ancestors === [] && !$hasScheduledSubtasks
				? $related
				: $related->withInherited($groups, $canSplit, $ancestors, $root->due, $root->priority, $sequence, $ancestorTitles, $hasScheduledSubtasks, $root->start);
		}
		return $out;
	}

	/**
	 * Parent links between tasks of the calendar, by child UID, without the
	 * links that would close a cycle.
	 *
	 * @param Task[] $tasks
	 * @param array<string, Task> $byUid
	 * @return array<string, string>
	 */
	private static function acyclicParents(array $tasks, array $byUid): array {
		$parentOf = [];
		foreach ($tasks as $task) {
			if ($task->parentUid !== null && $task->parentUid !== $task->id && isset($byUid[$task->parentUid])) {
				$parentOf[$task->id] = $task->parentUid;
			}
		}
		// Walk up from every task; a walk that returns to a task it has
		// already visited found a cycle, whose links are all dropped.
		$state = []; // uid => 1 while on the current walk, 2 when known to reach a root
		foreach (array_keys($parentOf) as $start) {
			$path = [];
			$node = (string)$start;
			while (isset($parentOf[$node]) && !isset($state[$node])) {
				$state[$node] = 1;
				$path[] = $node;
				$node = $parentOf[$node];
			}
			if (($state[$node] ?? 0) === 1) {
				// $node is on this walk: the walk from $node onwards is the cycle.
				$cycle = array_slice($path, (int)array_search($node, $path, true));
				foreach ($cycle as $member) {
					unset($parentOf[$member]);
				}
			}
			foreach ($path as $member) {
				$state[$member] = 2;
			}
		}
		return $parentOf;
	}

	/**
	 * Whether a subtask of *id*, at any depth, is itself still to be
	 * scheduled - Auto-schedule on, or awaiting review after its block ended
	 * (`calplan-review`), and not done: only then is *id* a container.
	 * Memoized.
	 *
	 * @param array<string, Task[]> $children
	 * @param array<string, bool> $memo
	 */
	private static function hasScheduledBelow(string $id, array $children, array &$memo): bool {
		if (isset($memo[$id])) {
			return $memo[$id];
		}
		$memo[$id] = false;
		foreach ($children[$id] ?? [] as $child) {
			if ((!$child->isDone() && ($child->autoSchedule || $child->needsReview())) || self::hasScheduledBelow($child->id, $children, $memo)) {
				return $memo[$id] = true;
			}
		}
		return false;
	}
}
