<?php

/**
 * The pickup synchronization: fetch once, write locally, then archive (REQ-SFTP-002).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\FilePickupService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer\FileTransferAdapterTestCase;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

class FilePickupServiceTest extends FileTransferAdapterTestCase {

	/**
	 * Files written into Nextcloud: path => content.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	/**
	 * Contracts by origin id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $contracts = [];

	/**
	 * Objects saved: [register, schema, object].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * Whether the Nextcloud write fails.
	 *
	 * @var bool
	 */
	private bool $failWrite = false;

	/**
	 * The time the runs happen at.
	 *
	 * @var int
	 */
	private const NOW = 1791547200;

	/**
	 * The pickup on the fake server, a fake contract store and a fake Nextcloud folder tree.
	 *
	 * @return FilePickupService The service.
	 */
	private function pickup(): FilePickupService {
		$contracts = $this->createMock(SynchronizationContractService::class);
		$contracts->method('findBySyncAndOrigin')->willReturnCallback(
			fn (string $synchronizationId, string $originId): ?array => ($this->contracts[$synchronizationId . '|' . $originId] ?? null)
		);
		$contracts->method('createFromArray')->willReturnCallback(
			function (array $object): array {
				$this->contracts[$object['synchronizationId'] . '|' . $object['originId']] = $object;
				return $object;
			}
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $this->folder(path: $uid));

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register = null, ?string $schema = null) {
				$this->saved[] = [(string)$register, (string)$schema, $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'object-' . count($this->saved));
			}
		);

		return new FilePickupService(
			$this->adapter('sftp'),
			$this->adapter('ftps'),
			$contracts,
			$rootFolder,
			$this->createMock(MappingService::class),
			$objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end pickup()

	/**
	 * A folder double that knows its path and writes into $this->written.
	 *
	 * @param string $path The folder's path.
	 *
	 * @return Folder The folder.
	 */
	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => isset($this->written[$path . '/' . $name])
		);
		$folder->method('newFolder')->willReturnCallback(fn (string $name): Folder => $this->folder(path: $path . '/' . $name));
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content) use ($path, $folder) {
				if ($this->failWrite === true) {
					throw new NotPermittedException('Quota exceeded');
				}

				$this->written[$path . '/' . $name] = stream_get_contents($content);
				return $this->createMock(\OCP\Files\File::class);
			}
		);

		return $folder;
	}//end folder()

	/**
	 * The morning batch synchronization.
	 *
	 * @param array<string,mixed> $config sourceConfig overrides.
	 *
	 * @return array<string,mixed> The synchronization.
	 */
	private function synchronization(array $config = []): array {
		return [
			'id' => 'sync-1',
			'sourceId' => 'source-1',
			'sourceType' => 'sftp',
			'targetId' => 'woo/besluit',
			'sourceConfig' => array_merge(
				[
					'path' => '.',
					'pattern' => 'besluiten-*.csv',
					'after' => 'move',
					'archivePath' => '/outgoing/processed',
					'target' => 'files',
					'filesFolder' => 'Partner/besluiten',
					'filesOwner' => 'beheerder',
				],
				$config
			),
		];
	}//end synchronization()

	/**
	 * Three new files on the server, one old enough each.
	 *
	 * @return void
	 */
	private function morningBatch(): void {
		$this->server->files = [
			'/outgoing/besluiten-1.csv' => ['content' => "id,titel\n1,Eerste", 'mtime' => (self::NOW - 600)],
			'/outgoing/besluiten-2.csv' => ['content' => "id,titel\n2,Tweede", 'mtime' => (self::NOW - 600)],
			'/outgoing/besluiten-3.csv' => ['content' => "id,titel\n3,Derde", 'mtime' => (self::NOW - 600)],
			'/outgoing/readme.txt' => ['content' => 'not matched', 'mtime' => (self::NOW - 600)],
		];
	}//end morningBatch()

	/**
	 * Three files land in Nextcloud, move to the archive, and a second run fetches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testTheMorningBatchArrivesAndIsArchived(): void {
		$this->morningBatch();
		$service = $this->pickup();

		$first = $service->run(synchronization: $this->synchronization(), source: $this->source(), now: self::NOW);

		$this->assertSame(3, $first['pickup']['stored']);
		$this->assertSame(3, $first['pickup']['archived']);
		$this->assertSame([], $first['pickup']['failed']);
		$this->assertSame(
			['beheerder/Partner/besluiten/besluiten-1.csv', 'beheerder/Partner/besluiten/besluiten-2.csv', 'beheerder/Partner/besluiten/besluiten-3.csv'],
			array_keys($this->written)
		);
		$this->assertSame("id,titel\n2,Tweede", $this->written['beheerder/Partner/besluiten/besluiten-2.csv']);
		$this->assertSame(
			['/outgoing/readme.txt', '/outgoing/processed/besluiten-1.csv', '/outgoing/processed/besluiten-2.csv', '/outgoing/processed/besluiten-3.csv'],
			array_keys($this->server->files)
		);

		// Put a copy back under the same name and time: it was fetched once, so never again.
		$this->server->files['/outgoing/besluiten-1.csv'] = ['content' => 'again', 'mtime' => (self::NOW - 600)];
		$this->server->calls = [];
		$second = $service->run(synchronization: $this->synchronization(), source: $this->source(), now: self::NOW);

		$this->assertSame(0, $second['pickup']['fetched']);
		$this->assertSame([], preg_grep('/^download:/', $this->server->calls));
	}//end testTheMorningBatchArrivesAndIsArchived()

	/**
	 * A failed local write leaves the remote file where it was, and it is tried again next run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testAFailedLocalWriteLeavesTheRemoteFile(): void {
		$this->morningBatch();
		$this->failWrite = true;
		$service = $this->pickup();

		$result = $service->run(synchronization: $this->synchronization(), source: $this->source(), now: self::NOW);

		$this->assertSame(3, $result['pickup']['fetched']);
		$this->assertSame(0, $result['pickup']['stored']);
		$this->assertCount(3, $result['pickup']['failed']);
		$this->assertSame([], preg_grep('/^(rename|delete):/', $this->server->calls));
		$this->assertArrayHasKey('/outgoing/besluiten-1.csv', $this->server->files);
		$this->assertSame([], $this->contracts, 'A file that was not stored is not remembered as fetched.');

		$this->failWrite = false;
		$retry = $service->run(synchronization: $this->synchronization(), source: $this->source(), now: self::NOW);
		$this->assertSame(3, $retry['pickup']['stored']);
	}//end testAFailedLocalWriteLeavesTheRemoteFile()

	/**
	 * A file still being written (younger than stableSeconds) waits for the next run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testAFileStillBeingWrittenWaits(): void {
		$this->server->files = ['/outgoing/besluiten-9.csv' => ['content' => 'half', 'mtime' => (self::NOW - 10)]];

		$result = $this->pickup()->run(synchronization: $this->synchronization(), source: $this->source(), now: self::NOW);

		$this->assertSame(1, $result['pickup']['skipped']);
		$this->assertSame(0, $result['pickup']['fetched']);
	}//end testAFileStillBeingWrittenWaits()

	/**
	 * A test run lists what it would fetch and changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testATestRunWritesNothing(): void {
		$this->morningBatch();

		$result = $this->pickup()->run(synchronization: $this->synchronization(), source: $this->source(), isTest: true, now: self::NOW);

		$this->assertCount(3, $result['pickup']['wouldFetch']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->contracts);
		$this->assertCount(4, $this->server->files);
	}//end testATestRunWritesNothing()

	/**
	 * Target mapping saves each CSV row as an object, never published, then deletes the file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testAMappedPickupSavesRowsAndNeverPublishes(): void {
		$this->server->files = ['/outgoing/besluiten-1.csv' => ['content' => "id;titel;published\n1;Eerste;2026-10-01\n2;Tweede;", 'mtime' => (self::NOW - 600)]];

		$result = $this->pickup()->run(
			synchronization: $this->synchronization(['target' => 'mapping', 'after' => 'delete']),
			source: $this->source(),
			now: self::NOW
		);

		$this->assertSame(2, $result['objects']['created']);
		$this->assertSame([['woo', 'besluit', ['id' => '1', 'titel' => 'Eerste']], ['woo', 'besluit', ['id' => '2', 'titel' => 'Tweede']]], $this->saved);
		$this->assertSame([], $this->server->files);
	}//end testAMappedPickupSavesRowsAndNeverPublishes()

	/**
	 * JSON and XML parse to records; an unknown format is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testRecordFormats(): void {
		$this->assertSame([['a' => 1], ['a' => 2]], FilePickupService::parseRecords('x.json', '[{"a":1},{"a":2}]'));
		$this->assertSame([['a' => 1]], FilePickupService::parseRecords('x.JSON', '{"a":1}'));
		$this->assertSame([['titel' => 'Eerste']], FilePickupService::parseRecords('x.xml', '<besluit><titel>Eerste</titel></besluit>'));

		$this->expectException(\InvalidArgumentException::class);
		FilePickupService::parseRecords('x.pdf', '%PDF');
	}//end testRecordFormats()
}//end class
