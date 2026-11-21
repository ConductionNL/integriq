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

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\WatchedFolder\WatchedFileMarker;
use OCA\Integriq\Service\WatchedFolder\WatchedFileWriter;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\Integriq\Service\WatchedFolderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	 * A file without a contract is queued; a file with one is not; a file already queued is not counted again.
	 *
	 * @return void
	 */
	public function testAnUnannouncedFileIsQueued(): void {
		$files = $this->createMock(originalClassName: NextcloudFolderFiles::class);
		$files->expects($this->once())->method('listFiles')->with($this->anything(), '', '*.pdf')->willReturn(
			[
				['fileId' => 41, 'name' => 'a.pdf'],
				['fileId' => 42, 'name' => 'b.pdf'],
				['fileId' => 43, 'name' => 'c.pdf'],
			]
		);

		$result = $this->service(watched: [$this->watched(id: 'sync-1', targetId: 'opencatalogi/publication')], files: $files, alreadyQueued: [43])->sweep();

		$this->assertSame(expected: ['queued' => 1, 'stopped' => []], actual: $result);
		$this->assertSame(expected: ['42@sync-1'], actual: $this->queued);
	}//end testAnUnannouncedFileIsQueued()

	/**
	 * A synchronization whose target names no register and schema is stopped before its folder is listed;
	 * the next one still runs, and a folder that cannot be listed queues nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvedTargetStopsBeforeAnyFile(): void {
		$files = $this->createMock(originalClassName: NextcloudFolderFiles::class);
		$files->expects($this->once())->method('listFiles')->willThrowException(new RemoteFileException('The folder "/Inbox" does not exist.'));

		$result = $this->service(
			watched: [$this->watched(id: 'sync-bad', targetId: ''), $this->watched(id: 'sync-2', targetId: 'opencatalogi/publication')],
			files: $files,
			alreadyQueued: []
		)->sweep();

		$this->assertSame(expected: ['queued' => 0, 'stopped' => ['sync-bad']], actual: $result);
		$this->assertSame(expected: [], actual: $this->queued);
	}//end testAnUnresolvedTargetStopsBeforeAnyFile()

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
	 * @param list<WatchedFolder>         $watched       The watched folders.
	 * @param NextcloudFolderFiles&MockObject $files     The folder files.
	 * @param list<int>                   $alreadyQueued Files whose job is already queued.
	 *
	 * @return WatchedFolderService
	 */
	private function service(array $watched, NextcloudFolderFiles $files, array $alreadyQueued): WatchedFolderService {
		$folders = $this->createMock(originalClassName: WatchedFolders::class);
		$folders->method('all')->willReturn($watched);
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
			files: $files,
			contracts: $contracts,
			writer: $this->createMock(originalClassName: WatchedFileWriter::class),
			marker: $this->createMock(originalClassName: WatchedFileMarker::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()
}//end class
