<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AutoSchedule\Service;

use OCA\AutoSchedule\AppInfo\Application;
use OCA\AutoSchedule\Model\Slot;
use OCA\AutoSchedule\Model\Task;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

class BehavioralHistoryService {
	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private ConfigService $config,
	) {
	}

	public function recordScheduled(string $userId, Task $task, Slot $slot, \DateTimeImmutable $at): void {
		$titleShape = self::titleShapeMetadata($task->title);
		$this->appendChanged($userId, $task->id, 'scheduled|' . $slot->start->format(DATE_ATOM) . '|' . $slot->end->format(DATE_ATOM) . '|' . json_encode($titleShape), [
			'v' => 1,
			'at' => $at->format(DATE_ATOM),
			'type' => 'scheduled',
			'task' => $this->taskKey($userId, $task->id),
			'start' => $slot->start->format(DATE_ATOM),
			'end' => $slot->end->format(DATE_ATOM),
			'duration_minutes' => $task->estimatedMinutes,
			'priority' => $task->priority,
			'has_due' => $task->due !== null,
			'title_shape' => $titleShape,
		]);
	}

	public function recordUnscheduled(string $userId, Task $task, string $reason, \DateTimeImmutable $at): void {
		$titleShape = self::titleShapeMetadata($task->title);
		$this->appendChanged($userId, $task->id, 'unscheduled|' . $reason . '|' . json_encode($titleShape), [
			'v' => 1,
			'at' => $at->format(DATE_ATOM),
			'type' => 'unscheduled',
			'task' => $this->taskKey($userId, $task->id),
			'reason' => $reason,
			'duration_minutes' => $task->estimatedMinutes,
			'priority' => $task->priority,
			'has_due' => $task->due !== null,
			'title_shape' => $titleShape,
		]);
	}

	/**
	 * Non-reversible title structure for initiation/complexity heuristics. Broad
	 * buckets deliberately avoid plaintext, token hashes, n-grams or fingerprints.
	 *
	 * @return array<string, string|bool>
	 */
	public static function titleShapeMetadata(string $title): array {
		preg_match_all('/./us', $title, $characters);
		$characterCount = count($characters[0] ?? []);
		preg_match_all('/[\p{L}\p{N}]+/u', $title, $wordMatches);
		$words = $wordMatches[0] ?? [];
		$wordCount = count($words);
		$letterCount = 0;
		foreach ($words as $word) {
			preg_match_all('/./us', $word, $wordCharacters);
			$letterCount += count($wordCharacters[0] ?? []);
		}
		$averageWordLength = $wordCount > 0 ? $letterCount / $wordCount : 0.0;

		$frequencies = [];
		foreach ($characters[0] ?? [] as $character) {
			$key = strtolower($character);
			$frequencies[$key] = ($frequencies[$key] ?? 0) + 1;
		}
		$entropy = 0.0;
		if ($characterCount > 0) {
			foreach ($frequencies as $count) {
				$probability = $count / $characterCount;
				$entropy -= $probability * log($probability, 2);
			}
		}

		return [
			'characters' => match (true) {
				$characterCount <= 12 => 'very_short',
				$characterCount <= 30 => 'short',
				$characterCount <= 60 => 'medium',
				default => 'long',
			},
			'words' => match (true) {
				$wordCount <= 1 => 'one',
				$wordCount <= 3 => 'few',
				$wordCount <= 7 => 'several',
				default => 'many',
			},
			'average_word_length' => match (true) {
				$averageWordLength < 4 => 'short',
				$averageWordLength < 7 => 'medium',
				default => 'long',
			},
			'character_entropy' => match (true) {
				$entropy < 2.5 => 'low',
				$entropy < 3.75 => 'medium',
				default => 'high',
			},
			'has_question' => str_contains($title, '?'),
			'has_digits' => preg_match('/\d/u', $title) === 1,
			'has_separator' => preg_match('/[:;\-–—|\/]/u', $title) === 1,
			'has_checklist_marker' => preg_match('/(^|\s)(?:\[.?\]|☐|☑|✓)(\s|$)/u', $title) === 1,
		];
	}
	public function recordRemoved(string $userId, string $taskUid, \DateTimeImmutable $at): void {
		$this->appendChanged($userId, $taskUid, 'removed', [
			'v' => 1,
			'at' => $at->format(DATE_ATOM),
			'type' => 'slot_removed',
			'task' => $this->taskKey($userId, $taskUid),
		]);
	}

	public function delete(string $userId): void {
		try {
			$this->userFolder($userId, false)?->delete();
		} catch (NotFoundException $_) {
		}
	}

	/** @return array{path:string,records:int} */
	public function export(string $userId): array {
		$lines = [];
		$folder = $this->userFolder($userId, false);
		if ($folder !== null) {
			$files = $folder->getDirectoryListing();
			usort($files, fn ($a, $b) => $a->getName() <=> $b->getName());
			foreach ($files as $file) {
				if (preg_match('/^behavioral_history-\d{4}-\d{2}\.jsonl$/', $file->getName())) {
					$content = trim($file->getContent());
					if ($content !== '') {
						$lines[] = $content;
					}
				}
			}
		}
		$content = $lines === [] ? '' : implode("\n", $lines) . "\n";
		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$exportFolder = $userFolder->get('CalPlan');
		} catch (NotFoundException $_) {
			$exportFolder = $userFolder->newFolder('CalPlan');
		}
		$name = 'behavioral-history-' . gmdate('Ymd-His') . '.jsonl';
		$exportFolder->newFile($name, $content);
		return ['path' => 'CalPlan/' . $name, 'records' => $content === '' ? 0 : substr_count($content, "\n")];
	}

	private function appendChanged(string $userId, string $taskUid, string $signature, array $record): void {
		if (!$this->config->isBehavioralHistoryEnabled($userId)) {
			return;
		}
		$folder = $this->userFolder($userId, true);
		$stateName = 'behavioral_state.json';
		$state = [];
		if ($folder->fileExists($stateName)) {
			$decoded = json_decode($folder->getFile($stateName)->getContent(), true);
			$state = is_array($decoded) ? $decoded : [];
		}
		$key = $this->taskKey($userId, $taskUid);
		if (($state[$key] ?? null) === $signature) {
			return;
		}
		$this->append($userId, $record, $folder);
		$state[$key] = $signature;
		$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if ($folder->fileExists($stateName)) {
			$folder->getFile($stateName)->putContent($json);
		} else {
			$folder->newFile($stateName, $json);
		}
	}

	private function append(string $userId, array $record, ?ISimpleFolder $folder = null): void {
		if (!$this->config->isBehavioralHistoryEnabled($userId)) {
			return;
		}
		$folder ??= $this->userFolder($userId, true);
		$name = 'behavioral_history-' . gmdate('Y-m') . '.jsonl';
		$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if ($folder->fileExists($name)) {
			$file = $folder->getFile($name);
			$file->putContent($file->getContent() . $line);
		} else {
			$folder->newFile($name, $line);
		}
		$this->prune($folder, $this->config->getBehavioralHistoryRetentionDays($userId));
	}

	private function prune(ISimpleFolder $folder, int $retentionDays): void {
		$cutoff = new \DateTimeImmutable('-' . $retentionDays . ' days', new \DateTimeZone('UTC'));
		foreach ($folder->getDirectoryListing() as $file) {
			if (preg_match('/^behavioral_history-(\d{4}-\d{2})\.jsonl$/', $file->getName(), $match)) {
				$monthEnd = new \DateTimeImmutable($match[1] . '-01 00:00:00', new \DateTimeZone('UTC'));
				if ($monthEnd->modify('last day of this month 23:59:59') < $cutoff) {
					$file->delete();
				}
			}
		}
	}

	private function taskKey(string $userId, string $taskUid): string {
		return hash_hmac('sha256', $taskUid, $this->config->getBehavioralHistorySalt($userId));
	}

	private function userFolder(string $userId, bool $create): ?ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			$behavior = $appData->getFolder('behavior');
		} catch (NotFoundException $_) {
			if (!$create) return null;
			$behavior = $appData->newFolder('behavior');
		}
		$name = hash('sha256', $userId);
		try {
			return $behavior->getFolder($name);
		} catch (NotFoundException $_) {
			return $create ? $behavior->newFolder($name) : null;
		}
	}
}
