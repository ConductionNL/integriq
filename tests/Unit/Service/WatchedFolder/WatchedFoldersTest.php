<?php

/**
 * Unit tests for WatchedFolders: the folder map the listener reads, the
 * watched folders the sweep walks, and queueing a file once.
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

use OCA\Integriq\BackgroundJob\WatchedFileIngestJob;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFolders
 * @uses   \OCA\Integriq\Service\WatchedFolder\WatchedFolder
 */
final class WatchedFoldersTest extends TestCase {

	/**
	 * The cache's entries.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * The jobs in the list, as their arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $jobs = [];

	/**
	 * How many times the synchronizations were listed.
	 *
	 * @var integer
	 */
	private int $listings = 0;

	/**
	 * The map holds each active folder synchronization under its folder's id; a disabled one,
	 * one on another source type and one whose folder is gone are left out. It is read once and cached.
	 *
	 * @return void
	 */
	public function testTheMapNamesTheSynchronizationsOfEachWatchedFolder(): void {
		$folders = $this->folders();

		$this->assertSame(expected: ['sync-1', 'sync-2'], actual: $folders->watching(folderId: 7));
		$this->assertSame(expected: [], actual: $folders->watching(folderId: 8));
		$this->assertSame(expected: 1, actual: $this->listings, message: 'the second lookup is answered from the cache');
	}//end testTheMapNamesTheSynchronizationsOfEachWatchedFolder()

	/**
	 * All watched folders come with their source; one by id is null for a synchronization that watches no folder.
	 *
	 * @return void
	 */
	public function testTheWatchedFoldersComeWithTheirSource(): void {
		$folders = $this->folders();

		$all = $folders->all();
		$this->assertSame(expected: ['sync-1', 'sync-2', 'sync-gone'], actual: array_map(static fn ($watched): string => $watched->id(), $all));
		$this->assertSame(expected: 'alice', actual: $all[0]->source['configuration']['ownerUid']);
		$this->assertSame(expected: 'sync-1', actual: $folders->byId(synchronizationId: 'sync-1')?->id());
		$this->assertNull(actual: $folders->byId(synchronizationId: 'sync-off'));
		$this->assertNull(actual: $folders->byId(synchronizationId: 'missing'));
	}//end testTheWatchedFoldersComeWithTheirSource()

	/**
	 * A file is queued once per synchronization.
	 *
	 * @return void
	 */
	public function testAFileIsQueuedOnce(): void {
		$folders = $this->folders();

		$this->assertTrue(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-1'));
		$this->assertFalse(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-1'));
		$this->assertTrue(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-2'));
		$this->assertCount(expectedCount: 2, haystack: $this->jobs);
	}//end testAFileIsQueuedOnce()

	/**
	 * The folders over the doubles.
	 *
	 * @return WatchedFolders
	 */
	private function folders(): WatchedFolders {
		$stored = [
			'sync-1'    => ['sourceType' => 'nextcloud-folder', 'sourceId' => 'src-inbox', 'status' => 'idle'],
			'sync-2'    => ['sourceType' => 'nextcloud-folder', 'sourceId' => 'src-inbox'],
			'sync-gone' => ['sourceType' => 'nextcloud-folder', 'sourceId' => 'src-gone'],
			'sync-off'  => ['sourceType' => 'nextcloud-folder', 'sourceId' => 'src-inbox', 'status' => 'disabled'],
			'sync-sftp' => ['sourceType' => 'sftp', 'sourceId' => 'src-inbox'],
			'src-inbox' => ['type' => 'nextcloud-folder', 'configuration' => ['ownerUid' => 'alice', 'rootPath' => '/Inbox']],
			'src-gone'  => ['type' => 'nextcloud-folder', 'configuration' => ['ownerUid' => 'bob', 'rootPath' => '/Gone']],
		];
		$entity = static function (string $id, array $data): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($id);
			$object->setObject(['id' => $id] + $data);
			return $object;
		};

		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($stored, $entity): array {
				$this->listings++;
				$this->assertSame(expected: ['register' => 'integriq', 'schema' => 'synchronization', 'sourceType' => 'nextcloud-folder'], actual: $config['filters']);
				$found = [];
				foreach (['sync-1', 'sync-2', 'sync-gone', 'sync-off'] as $id) {
					$found[] = $entity($id, $stored[$id]);
				}

				return ['results' => $found];
			}
		);
		$objects->method('find')->willReturnCallback(
			static function ($id, ?ObjectFindOptions $options = null) use ($stored, $entity): ?ObjectEntity {
				return isset($stored[$id]) === true ? $entity((string)$id, $stored[$id]) : null;
			}
		);

		$inbox = $this->createMock(originalClassName: Folder::class);
		$inbox->method('getId')->willReturn(7);
		$files = $this->createMock(originalClassName: NextcloudFolderFiles::class);
		$files->method('folder')->willReturnCallback(
			static function (array $source) use ($inbox): Folder {
				if ($source['configuration']['ownerUid'] === 'bob') {
					throw new RemoteFileException('The folder "/Gone" does not exist.');
				}

				return $inbox;
			}
		);

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('has')->willReturnCallback(
			fn (string $job, mixed $argument): bool => $job === WatchedFileIngestJob::class && in_array($argument, $this->jobs, true)
		);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->jobs[] = $argument;
			}
		);

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new WatchedFolders(objects: $objects, files: $files, jobs: $jobList, cacheFactory: $cacheFactory);
	}//end folders()
}//end class
