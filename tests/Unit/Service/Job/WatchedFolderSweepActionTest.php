<?php

/**
 * Unit tests for the scheduled watched-folder sweep: the job action runs the
 * real sweep and reports, in the job log's shape, what it queued and which
 * synchronizations it stopped.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/tasks.md#task-6-the-listener-the-job-and-the-sweep
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Job;

use OCA\Integriq\Service\Job\WatchedFolderSweepAction;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\WatchedFolder\WatchedFileDelivery;
use OCA\Integriq\Service\WatchedFolder\WatchedFileMarker;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\Integriq\Service\WatchedFolderService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Job\WatchedFolderSweepAction
 * @uses   \OCA\Integriq\Service\WatchedFolderService
 * @uses   \OCA\Integriq\Service\WatchedFolder\WatchedFolder
 */
final class WatchedFolderSweepActionTest extends TestCase {

	/**
	 * The jobs queued, as `fileId@synchronizationId`.
	 *
	 * @var list<string>
	 */
	private array $queued = [];

	/**
	 * A file no event announced is queued by the scheduled run, which logs a success.
	 *
	 * @return void
	 */
	public function testTheScheduledRunQueuesAnUnannouncedFile(): void {
		$action = new WatchedFolderSweepAction(
			watchedFolders: $this->service(watched: [$this->watched(id: 'sync-1', targetId: 'opencatalogi/publication')], listed: ['sync-1' => [42]])
		);

		$log = $action->run();

		$this->assertSame(expected: ['42@sync-1'], actual: $this->queued);
		$this->assertSame(expected: 'SUCCESS', actual: $log['level']);
		$this->assertSame(expected: 'Watched-folder sweep queued 1 file(s)', actual: $log['message']);
	}//end testTheScheduledRunQueuesAnUnannouncedFile()

	/**
	 * A synchronization whose target does not resolve is named in a warning, and the others still run.
	 *
	 * @return void
	 */
	public function testAStoppedSynchronizationIsAWarning(): void {
		$action = new WatchedFolderSweepAction(
			watchedFolders: $this->service(
				watched: [$this->watched(id: 'sync-bad', targetId: ''), $this->watched(id: 'sync-2', targetId: 'opencatalogi/publication')],
				listed: ['sync-2' => [7]]
			)
		);

		$log = $action->run(arguments: ['ignored' => true]);

		$this->assertSame(expected: ['7@sync-2'], actual: $this->queued);
		$this->assertSame(expected: 'WARNING', actual: $log['level']);
		$this->assertStringContainsString(needle: 'sync-bad', haystack: $log['message']);
		$this->assertSame(
			expected: ['Watched-folder sweep queued 1 file(s)', 'Stopped, the target does not resolve: sync-bad'],
			actual: $log['stackTrace']
		);
	}//end testAStoppedSynchronizationIsAWarning()

	/**
	 * With no watched folder the run does nothing and succeeds.
	 *
	 * @return void
	 */
	public function testNoWatchedFolderIsANoOp(): void {
		$log = (new WatchedFolderSweepAction(watchedFolders: $this->service(watched: [], listed: [])))->run();

		$this->assertSame(expected: [], actual: $this->queued);
		$this->assertSame(expected: ['SUCCESS', 'Watched-folder sweep queued 0 file(s)'], actual: [$log['level'], $log['message']]);
	}//end testNoWatchedFolderIsANoOp()

	/**
	 * One watched folder.
	 *
	 * @param string $id       The synchronization id.
	 * @param string $targetId The synchronization's target.
	 *
	 * @return WatchedFolder
	 */
	private function watched(string $id, string $targetId): WatchedFolder {
		return new WatchedFolder(
			synchronization: ['id' => $id, 'sourceType' => 'nextcloud-folder', 'targetId' => $targetId, 'sourceConfig' => ['pattern' => '*.pdf']],
			source: ['type' => 'nextcloud-folder', 'configuration' => ['ownerUid' => 'alice', 'rootPath' => '/Inbox']]
		);
	}//end watched()

	/**
	 * The real sweep over doubled folders and an empty contract store.
	 *
	 * @param list<WatchedFolder>      $watched The watched folders.
	 * @param array<string, list<int>> $listed  The file ids in each folder.
	 *
	 * @return WatchedFolderService
	 */
	private function service(array $watched, array $listed): WatchedFolderService {
		$folders = $this->createMock(originalClassName: WatchedFolders::class);
		$folders->method('all')->willReturn($watched);
		$folders->method('listed')->willReturnCallback(
			static fn (WatchedFolder $folder): array => $listed[$folder->synchronizationId()] ?? []
		);
		$folders->method('queue')->willReturnCallback(
			function (int $fileId, string $synchronizationId): bool {
				$this->queued[] = $fileId . '@' . $synchronizationId;
				return true;
			}
		);

		return new WatchedFolderService(
			folders: $folders,
			contracts: $this->createMock(originalClassName: SynchronizationContractServiceInterface::class),
			delivery: $this->createMock(originalClassName: WatchedFileDelivery::class),
			marker: $this->createMock(originalClassName: WatchedFileMarker::class)
		);
	}//end service()
}//end class
