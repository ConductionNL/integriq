<?php

/**
 * Unit tests for WatchedFileIngestJob and the ingest it runs: once per file,
 * a young file waits, the publication keys are removed, and a file is marked
 * only after a safe write.
 *
 * The job runs the real WatchedFolderService, WatchedFileWriter and
 * WatchedFileMarker; OpenRegister (ObjectService, FileService), the mapping,
 * the contracts and Nextcloud Files are doubled.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\WatchedFileIngestJob;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\WatchedFolder\WatchedFileMarker;
use OCA\Integriq\Service\WatchedFolder\WatchedFileWriter;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCA\Integriq\Service\WatchedFolderService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\WatchedFileIngestJob
 * @covers \OCA\Integriq\Service\WatchedFolderService
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFileWriter
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFileMarker
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFolder
 */
final class WatchedFileIngestJobTest extends TestCase {

	/**
	 * The time the job runs at.
	 *
	 * @var integer
	 */
	private const NOW = 1_800_000_000;

	/**
	 * The synchronization's pickup settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $sourceConfig = ['stableSeconds' => 60, 'after' => 'move'];

	/**
	 * The contract the store holds for the file, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $contract = null;

	/**
	 * The contracts made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $made = [];

	/**
	 * The objects saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The files attached.
	 *
	 * @var list<FileUpload>
	 */
	private array $attached = [];

	/**
	 * Where the file was moved to.
	 *
	 * @var list<string>
	 */
	private array $moves = [];

	/**
	 * The tags assigned, as `fileId:tagId`.
	 *
	 * @var list<string>
	 */
	private array $tagged = [];

	/**
	 * What the mapping returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $mapped = ['title' => 'Besluit 12', 'published' => '2026-11-21T10:00:00+00:00', 'publicatiedatum' => '2026-11-21'];

	/**
	 * Whether saving the object fails.
	 *
	 * @var boolean
	 */
	private bool $saveFails = false;

	/**
	 * Whether attaching the file fails.
	 *
	 * @var boolean
	 */
	private bool $attachFails = false;

	/**
	 * The file's modification time.
	 *
	 * @var integer
	 */
	private int $mtime = (self::NOW - 600);

	/**
	 * A file already in the contract ends the job: nothing is read, saved or moved.
	 *
	 * @return void
	 */
	public function testAFileInTheContractIsNotIngestedAgain(): void {
		$this->contract = ['originId' => 'nextcloud-file:42'];

		$this->assertSame(expected: 'known', actual: $this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW));
		$this->assertSame(expected: [[], [], []], actual: [$this->saved, $this->made, $this->moves]);
	}//end testAFileInTheContractIsNotIngestedAgain()

	/**
	 * A file modified 10 seconds ago with `stableSeconds` 60 ends without a contract, for the next sweep.
	 *
	 * @return void
	 */
	public function testAYoungFileWaitsForTheSweep(): void {
		$this->mtime = (self::NOW - 10);

		$this->assertSame(expected: 'young', actual: $this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW));
		$this->assertSame(expected: [[], [], []], actual: [$this->saved, $this->made, $this->moves]);
	}//end testAYoungFileWaitsForTheSweep()

	/**
	 * A mapping that sets `published` and `publicatiedatum` saves a draft without either.
	 *
	 * @return void
	 */
	public function testThePublicationDateIsStripped(): void {
		$this->assertSame(expected: 'ingested', actual: $this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW));

		$this->assertSame(expected: [['title' => 'Besluit 12']], actual: $this->saved);
	}//end testThePublicationDateIsStripped()

	/**
	 * A safe write attaches the file, makes the contract and moves the file to `processedPath`.
	 *
	 * @return void
	 */
	public function testASuccessfulSaveMovesTheFileToProcessed(): void {
		$this->sourceConfig['processedPath'] = 'klaar';

		$this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW);

		$this->assertSame(expected: 'besluit.pdf', actual: $this->attached[0]->fileName);
		$this->assertSame(expected: 'nextcloud-file:42', actual: $this->made[0]['originId']);
		$this->assertSame(expected: 'object-1', actual: $this->made[0]['targetId']);
		$this->assertSame(expected: ['/alice/files/Inbox/klaar/besluit.pdf'], actual: $this->moves);
	}//end testASuccessfulSaveMovesTheFileToProcessed()

	/**
	 * A save or an attach that throws moves the file to `failedPath` and makes no contract.
	 *
	 * @return void
	 */
	public function testAFailedSaveMovesTheFileToFailed(): void {
		$this->saveFails = true;
		$this->assertSame(expected: 'failed', actual: $this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW));
		$this->assertSame(expected: [[], ['/alice/files/Inbox/failed/besluit.pdf']], actual: [$this->made, $this->moves]);

		$this->saveFails   = false;
		$this->attachFails = true;
		$this->moves       = [];
		$this->assertSame(expected: 'failed', actual: $this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW));
		$this->assertSame(expected: [[], ['/alice/files/Inbox/failed/besluit.pdf']], actual: [$this->made, $this->moves]);
	}//end testAFailedSaveMovesTheFileToFailed()

	/**
	 * With `after: tag` the file keeps its place and gets the ingested tag, or the failed one.
	 *
	 * @return void
	 */
	public function testTaggingInsteadOfMoving(): void {
		$this->sourceConfig['after'] = 'tag';
		$this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW);
		$this->saveFails = true;
		$this->service()->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW);

		$this->assertSame(expected: ['42:tag-integriq-ingested', '42:tag-integriq-ingest-failed'], actual: $this->tagged);
		$this->assertSame(expected: [], actual: $this->moves);
	}//end testTaggingInsteadOfMoving()

	/**
	 * A target that names no register and schema writes nothing and marks nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvedTargetWritesNothing(): void {
		$this->assertSame(
			expected: 'unresolved',
			actual: $this->service(targetId: 'not-a-target')->ingest(synchronizationId: 'sync-1', fileId: 42, now: self::NOW)
		);
		$this->assertSame(expected: [[], [], []], actual: [$this->saved, $this->made, $this->moves]);
	}//end testAnUnresolvedTargetWritesNothing()

	/**
	 * The job runs the ingest for its argument, and drops an argument without a file and a synchronization.
	 *
	 * @return void
	 */
	public function testTheJobRunsTheIngestAndDropsABadArgument(): void {
		$service = $this->createMock(originalClassName: WatchedFolderService::class);
		$service->expects($this->once())->method('ingest')->with('sync-1', 42)->willReturn('ingested');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$job = new WatchedFileIngestJob(time: $this->createMock(originalClassName: ITimeFactory::class), service: $service, logger: $logger);
		$run = new ReflectionMethod(objectOrMethod: $job, method: 'run');

		$run->invoke($job, ['fileId' => 42, 'synchronizationId' => 'sync-1']);
		$run->invoke($job, ['fileId' => '42']);
	}//end testTheJobRunsTheIngestAndDropsABadArgument()

	/**
	 * The service over the doubles.
	 *
	 * @param string $targetId The synchronization's target.
	 *
	 * @return WatchedFolderService
	 */
	private function service(string $targetId='opencatalogi/publication'): WatchedFolderService {
		$watched = new WatchedFolder(
			synchronization: ['id' => 'sync-1', 'sourceType' => 'nextcloud-folder', 'sourceTargetMapping' => 'map-1', 'targetId' => $targetId, 'sourceConfig' => $this->sourceConfig],
			source: ['id' => 'source-1', 'type' => 'nextcloud-folder', 'configuration' => ['ownerUid' => 'alice', 'rootPath' => '/Inbox']]
		);
		$folders = $this->createMock(originalClassName: WatchedFolders::class);
		$folders->method('byId')->willReturn($watched);

		$files = $this->createMock(originalClassName: NextcloudFolderFiles::class);
		$files->method('folder')->willReturn($this->inbox());

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new WatchedFolderService(
			folders: $folders,
			files: $files,
			contracts: $this->contracts(),
			writer: new WatchedFileWriter(mappings: $this->mappings(), objects: $this->objects(), files: $this->fileService(), logger: $logger),
			marker: new WatchedFileMarker(files: $files, tags: $this->tags(), tagMapper: $this->tagMapper()),
			logger: $logger
		);
	}//end service()

	/**
	 * The watched folder `/alice/files/Inbox`, holding `besluit.pdf` (id 42).
	 *
	 * @return Folder&MockObject
	 */
	private function inbox(): Folder {
		$inbox = $this->createMock(originalClassName: Folder::class);
		$inbox->method('getId')->willReturn(7);
		$inbox->method('getPath')->willReturn('/alice/files/Inbox');
		$inbox->method('nodeExists')->willReturn(false);
		$inbox->method('newFolder')->willReturnCallback(
			function (string $name): Folder {
				$made = $this->createMock(originalClassName: Folder::class);
				$made->method('getPath')->willReturn('/alice/files/Inbox/' . $name);
				$made->method('nodeExists')->willReturn(false);
				return $made;
			}
		);

		$file = $this->createMock(originalClassName: File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('getPath')->willReturn('/alice/files/Inbox/besluit.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(1024);
		$file->method('getMTime')->willReturnCallback(fn (): int => $this->mtime);
		$file->method('getContent')->willReturn('%PDF-1.7');
		$file->method('getParent')->willReturn($inbox);
		$file->method('move')->willReturnCallback(
			function (string $target) use ($file): File {
				$this->moves[] = $target;
				return $file;
			}
		);
		$inbox->method('getById')->willReturn([$file]);

		return $inbox;
	}//end inbox()

	/**
	 * The contracts store.
	 *
	 * @return SynchronizationContractServiceInterface&MockObject
	 */
	private function contracts(): SynchronizationContractServiceInterface {
		$contracts = $this->createMock(originalClassName: SynchronizationContractServiceInterface::class);
		$contracts->method('findBySyncAndOrigin')->willReturnCallback(fn (): ?array => $this->contract);
		$contracts->method('createFromArray')->willReturnCallback(
			function (array $object): array {
				$this->made[] = $object;
				return $object;
			}
		);

		return $contracts;
	}//end contracts()

	/**
	 * The mapping, which sees the file described and returns the mapped object.
	 *
	 * @return MappingService&MockObject
	 */
	private function mappings(): MappingService {
		$mappings = $this->createMock(originalClassName: MappingService::class);
		$mappings->method('executeMapping')->willReturnCallback(
			function (mixed $mapping, array $input): array {
				$this->assertSame(expected: 'map-1', actual: $mapping);
				$this->assertSame(expected: ['besluit.pdf', 'alice', 42], actual: [$input['name'], $input['owner'], $input['fileId']]);
				$this->assertArrayNotHasKey(key: 'content', array: $input, message: 'a PDF is not handed to the mapping as text');
				return $this->mapped;
			}
		);

		return $mappings;
	}//end mappings()

	/**
	 * OpenRegister's object service, saving into the target.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->assertSame(expected: ['opencatalogi', 'publication'], actual: [$options->target->register, $options->target->schema]);
				if ($this->saveFails === true) {
					throw new RuntimeException('register down');
				}

				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('object-1');
				return $entity;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * OpenRegister's file service, attaching to the saved object.
	 *
	 * @return FileService&MockObject
	 */
	private function fileService(): FileService {
		$files = $this->getMockBuilder(className: FileService::class)->disableOriginalConstructor()->onlyMethods(methods: ['addFile'])->getMock();
		$files->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload): File {
				$this->assertSame(expected: 'object-1', actual: $target->object);
				if ($this->attachFails === true) {
					throw new RuntimeException('files down');
				}

				$this->attached[] = $upload;
				return $this->createMock(originalClassName: File::class);
			}
		);

		return $files;
	}//end fileService()

	/**
	 * The system tags, each found by name.
	 *
	 * @return ISystemTagManager&MockObject
	 */
	private function tags(): ISystemTagManager {
		$tags = $this->createMock(originalClassName: ISystemTagManager::class);
		$tags->method('getTag')->willReturnCallback(
			function (string $name): ISystemTag {
				$tag = $this->createMock(originalClassName: ISystemTag::class);
				$tag->method('getId')->willReturn('tag-' . $name);
				return $tag;
			}
		);

		return $tags;
	}//end tags()

	/**
	 * The tag mapper, recording each assignment.
	 *
	 * @return ISystemTagObjectMapper&MockObject
	 */
	private function tagMapper(): ISystemTagObjectMapper {
		$mapper = $this->createMock(originalClassName: ISystemTagObjectMapper::class);
		$mapper->method('assignTags')->willReturnCallback(
			function (string $objectId, string $type, array $tagIds): void {
				$this->assertSame(expected: 'files', actual: $type);
				$this->tagged[] = $objectId . ':' . $tagIds[0];
			}
		);

		return $mapper;
	}//end tagMapper()
}//end class
