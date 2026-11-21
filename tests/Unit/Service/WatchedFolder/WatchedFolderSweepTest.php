<?php

/**
 * Unit tests for the watched-folder sweep: a file no event announced is
 * queued once, and a synchronization whose target does not resolve stops
 * before any file is read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\WatchedFolder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\WatchedFolder;

use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\WatchedFolder\WatchedFileMarker;
use OCA\Integriq\Service\WatchedFolder\WatchedFileWriter;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\Integriq\Service\WatchedFolderService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\WatchedFolderService
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFolder
 */
final class WatchedFolderSweepTest extends TestCase {

	/**
	 * The jobs queued, as `fileId@synchronizationId`.
	 *
	 * @var list<string>
	 */
	private array $queued = [];

	/**
	 * The synchronizations whose folder was listed.
	 *
	 * @var list<string>
	 */
	private array $listings = [];

	/**
	 * A file without a contract is queued; a file with one is not; a file already queued is not counted again.
	 *
	 * @return void
	 */
	public function testAnUnannouncedFileIsQueued(): void {
		$watched = [$this->watched(id: 'sync-1', targetId: 'opencatalogi/publication')];

		$result = $this->service(watched: $watched, listed: ['sync-1' => [41, 42, 43]], alreadyQueued: [43])->sweep();

		$this->assertSame(expected: ['queued' => 1, 'stopped' => []], actual: $result);
		$this->assertSame(expected: ['42@sync-1'], actual: $this->queued);
	}//end testAnUnannouncedFileIsQueued()

	/**
	 * A synchronization whose target names no register and schema is stopped before its folder is listed,
	 * and the next one still runs.
	 *
	 * @return void
	 */
	public function testAnUnresolvedTargetStopsBeforeAnyFile(): void {
		$watched = [$this->watched(id: 'sync-bad', targetId: ''), $this->watched(id: 'sync-2', targetId: 'opencatalogi/publication')];

		$result = $this->service(watched: $watched, listed: ['sync-2' => []], alreadyQueued: [])->sweep();

		$this->assertSame(expected: ['queued' => 0, 'stopped' => ['sync-bad']], actual: $result);
		$this->assertSame(expected: ['sync-2'], actual: $this->listings, message: 'the stopped synchronization was not listed');
	}//end testAnUnresolvedTargetStopsBeforeAnyFile()

	/**
	 * An intake synchronization is not swept: its files wait for the hand-over.
	 *
	 * @return void
	 */
	public function testAnIntakeSynchronizationIsNotSwept(): void {
		$intake = new WatchedFolder(
			synchronization: ['id' => 'sync-intake', 'targetId' => 'a/b', 'sourceConfig' => ['target' => 'intake', 'intakeApp' => 'filinq']],
			source: []
		);

		$result = $this->service(watched: [$intake], listed: ['sync-intake' => [41]], alreadyQueued: [])->sweep();

		$this->assertSame(expected: ['queued' => 0, 'stopped' => []], actual: $result);
		$this->assertSame(expected: [], actual: $this->listings);
	}//end testAnIntakeSynchronizationIsNotSwept()

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
	 * The service over the doubles. Contract `nextcloud-file:41` exists for sync-1.
	 *
	 * @param list<WatchedFolder>      $watched       The watched folders.
	 * @param array<string, list<int>> $listed        The file ids in each folder.
	 * @param list<int>                $alreadyQueued Files whose job is already queued.
	 *
	 * @return WatchedFolderService
	 */
	private function service(array $watched, array $listed, array $alreadyQueued): WatchedFolderService {
		$folders = $this->createMock(originalClassName: WatchedFolders::class);
		$folders->method('all')->willReturn($watched);
		$folders->method('listed')->willReturnCallback(
			function (WatchedFolder $folder) use ($listed): array {
				$this->listings[] = $folder->synchronizationId();
				return $listed[$folder->synchronizationId()] ?? [];
			}
		);
		$folders->method('queue')->willReturnCallback(
			function (int $fileId, string $synchronizationId) use ($alreadyQueued): bool {
				if (in_array($fileId, $alreadyQueued, true) === true) {
					return false;
				}

				$this->queued[] = $fileId . '@' . $synchronizationId;
				return true;
			}
		);

		$contracts = $this->createMock(originalClassName: SynchronizationContractServiceInterface::class);
		$contracts->method('findAllObjects')->willReturnCallback(
			static fn (array $filters): array => [
				['synchronizationId' => $filters['synchronizationId'], 'originId' => 'nextcloud-file:41'],
				['synchronizationId' => 'another', 'originId' => 'nextcloud-file:42'],
			]
		);

		return new WatchedFolderService(
			folders: $folders,
			contracts: $contracts,
			writer: $this->createMock(originalClassName: WatchedFileWriter::class),
			marker: $this->createMock(originalClassName: WatchedFileMarker::class)
		);
	}//end service()
}//end class
