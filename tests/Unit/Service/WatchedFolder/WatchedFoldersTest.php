<?php

/**
 * Unit tests for WatchedFolders and WatchedFolderMap: the folder map the
 * listener reads, the watched folders the sweep walks, the files in them,
 * and queueing a file once.
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
use OCA\Integriq\Service\WatchedFolder\WatchedFileIngestJob;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolderMap;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFolders
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFolderMap
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
		$map = new WatchedFolderMap(folders: $this->folders(), cacheFactory: $this->cacheFactory());

		$this->assertSame(expected: ['sync-1', 'sync-2'], actual: $map->watching(folderId: 7));
		$this->assertSame(expected: [], actual: $map->watching(folderId: 8));
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
		$ids = array_map(static fn (WatchedFolder $watched): string => $watched->synchronizationId(), $all);
		$this->assertSame(expected: ['sync-1', 'sync-2', 'sync-gone'], actual: $ids);
		$this->assertSame(expected: 'alice', actual: $all[0]->source['configuration']['ownerUid']);
		$this->assertSame(expected: 'sync-1', actual: $folders->byId(synchronizationId: 'sync-1')?->synchronizationId());
		$this->assertNull(actual: $folders->byId(synchronizationId: 'sync-off'));
		$this->assertNull(actual: $folders->byId(synchronizationId: 'missing'));
	}//end testTheWatchedFoldersComeWithTheirSource()

	/**
	 * A file is queued once per synchronization.
	 *
	 * @return void
	 */
	public function testAFileIsQueuedOnce(): void {
		$folders = new WatchedFolderMap(folders: $this->folders(), cacheFactory: $this->cacheFactory());

		$this->assertTrue(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-1'));
		$this->assertFalse(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-1'));
		$this->assertTrue(condition: $folders->queue(fileId: 42, synchronizationId: 'sync-2'));
		$this->assertCount(expectedCount: 2, haystack: $this->jobs);
	}//end testAFileIsQueuedOnce()

	/**
	 * Only a file directly in the watched folder is found; a folder that cannot be listed lists nothing.
	 *
	 * @return void
	 */
	public function testTheFilesOfAWatchedFolder(): void {
		$folders = $this->folders();
		$watched = $folders->byId(synchronizationId: 'sync-1');

		$this->assertSame(expected: 42, actual: $folders->fileIn(watched: $watched, fileId: 42)?->getId());
		$this->assertNull(actual: $folders->fileIn(watched: $watched, fileId: 99), message: 'a file in the processed folder below');
		$this->assertSame(expected: [42, 43], actual: $folders->listed(watched: $watched));

		$gone = $folders->all()[2];
		$this->assertSame(expected: [], actual: $folders->listed(watched: $gone));
		$this->assertNull(actual: $folders->fileIn(watched: $gone, fileId: 42));
	}//end testTheFilesOfAWatchedFolder()

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
				$expected = ['register' => 'integriq', 'schema' => 'synchronization', 'sourceType' => 'nextcloud-folder'];
				$this->assertSame(expected: $expected, actual: $config['filters']);
				$found = [];
				foreach (['sync-1', 'sync-2', 'sync-gone', 'sync-off'] as $id) {
					$found[] = $entity($id, $stored[$id]);
				}

				return ['results' => $found];
			}
		);
		$objects->method('find')->willReturnCallback(
			static function ($id, ?ObjectFindOptions $options = null) use ($stored, $entity): ?ObjectEntity {
				if (isset($stored[$id]) === false) {
					return null;
				}

				return $entity((string)$id, $stored[$id]);
			}
		);

		$inbox     = $this->createMock(originalClassName: Folder::class);
		$processed = $this->createMock(originalClassName: Folder::class);
		$inbox->method('getId')->willReturn(7);
		$processed->method('getId')->willReturn(70);
		$inside = $this->createMock(originalClassName: File::class);
		$inside->method('getId')->willReturn(42);
		$inside->method('getParent')->willReturn($inbox);
		$below = $this->createMock(originalClassName: File::class);
		$below->method('getId')->willReturn(99);
		$below->method('getParent')->willReturn($processed);
		$inbox->method('getById')->willReturnCallback(static fn (int $id): array => [42 => [$inside], 99 => [$below]][$id] ?? []);

		$missing = static function (array $source): void {
			if ($source['configuration']['ownerUid'] === 'bob') {
				throw new RemoteFileException(message: 'The folder "/Gone" does not exist.');
			}
		};
		$files = $this->createMock(originalClassName: NextcloudFolderFiles::class);
		$files->method('folder')->willReturnCallback(
			static function (array $source) use ($inbox, $missing): Folder {
				$missing($source);
				return $inbox;
			}
		);
		$files->method('listFiles')->willReturnCallback(
			static function (array $source) use ($missing): array {
				$missing($source);
				return [['fileId' => 42, 'name' => 'a.pdf'], ['fileId' => 43, 'name' => 'b.pdf']];
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

		return new WatchedFolders(objects: $objects, files: $files, jobs: $jobList, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end folders()

	/**
	 * A distributed cache over an array.
	 *
	 * @return ICacheFactory
	 */
	private function cacheFactory(): ICacheFactory {
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

		return $cacheFactory;
	}//end cacheFactory()
}//end class
