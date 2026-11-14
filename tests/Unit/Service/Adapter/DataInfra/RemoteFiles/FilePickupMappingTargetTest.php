<?php

/**
 * Unit tests for a file pickup that feeds the mapping (FilePickupSynchronization collect/complete).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FilePickup;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FilePickupSynchronization;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\MappingRecordStore;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\PickedFileRecords;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The records of new files go to the caller first; the files are archived and remembered only after.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
#[CoversClass(FilePickupSynchronization::class)]
#[CoversClass(FilePickup::class)]
#[UsesClass(MappingRecordStore::class)]
#[UsesClass(PickedFileRecords::class)]
#[UsesClass(RemoteFileEntry::class)]
#[UsesClass(RemoteFileException::class)]
final class FilePickupMappingTargetTest extends TestCase {

	private const NOW = 1763100000;

	/**
	 * @var RemoteFileClient&MockObject
	 */
	private RemoteFileClient $client;

	/**
	 * @var SynchronizationContractServiceInterface&MockObject
	 */
	private SynchronizationContractServiceInterface $contracts;

	/**
	 * What happened on the server and in the contracts, in order.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * The contracts the store holds.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The files on the server, path to content.
	 *
	 * @var array<string, string>
	 */
	private array $files = [
		'in/besluiten-1.csv' => "id,titel\n1,Eerste\n2,Tweede\n",
		'in/besluiten-2.csv' => "id,titel\n3,Derde\n",
		'in/besluiten-3.csv' => "id,titel\n4\n",
	];

	/**
	 * The synchronization under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $synchronization = [
		'id'           => 'sync-1',
		'sourceType'   => 'sftp',
		'@self'        => ['owner' => 'alice'],
		'sourceConfig' => [
			'path'        => 'in',
			'pattern'     => 'besluiten-*.csv',
			'after'       => 'move',
			'archivePath' => 'processed',
			'target'      => 'mapping',
		],
	];

	/**
	 * Set up a stand-in server and a contract store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(RemoteFileClient::class);
		$this->client->method('listFiles')->willReturnCallback(
			function (): array {
				$entries = [];
				foreach (array_keys($this->files) as $path) {
					$entries[] = new RemoteFileEntry(name: basename($path), path: $path, size: strlen($this->files[$path]), modifiedAt: self::NOW - 3600);
				}

				return $entries;
			}
		);
		$this->client->method('fetch')->willReturnCallback(
			function (array $source, string $path, $target): void {
				$this->events[] = 'fetch ' . $path;
				fwrite($target, $this->files[$path]);
			}
		);
		$this->client->method('move')->willReturnCallback(
			function (array $source, string $from, string $to): void {
				$this->events[] = 'move ' . $from . ' ' . $to;
			}
		);

		$this->contracts = $this->createMock(SynchronizationContractServiceInterface::class);
		$this->contracts->method('findAllObjects')->willReturnCallback(fn (): array => $this->stored);
		$this->contracts->method('createFromArray')->willReturnCallback(
			function (array $object): array {
				$this->events[] = 'contract ' . $object['originId'] . ' -> ' . $object['targetId'];
				$object['id']   = 'contract-' . count($this->stored);
				$this->stored[] = $object;
				return $object;
			}
		);
	}//end setUp()

	/**
	 * The run under test.
	 *
	 * @return FilePickupSynchronization
	 */
	private function runner(): FilePickupSynchronization {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		return new FilePickupSynchronization(
			pickup: new FilePickup(client: $this->client, logger: $this->createMock(LoggerInterface::class)),
			client: $this->client,
			contracts: $this->contracts,
			rootFolder: $rootFolder
		);
	}//end runner()

	/**
	 * Collect reads the records without touching the server; complete archives and remembers.
	 *
	 * @return void
	 */
	public function testCollectReadsRecordsAndOnlyCompleteArchivesAndRemembers(): void {
		$runner    = $this->runner();
		$collected = $runner->collect(synchronization: $this->synchronization, source: [], now: self::NOW);

		$this->assertSame(
			[['id' => '1', 'titel' => 'Eerste'], ['id' => '2', 'titel' => 'Tweede'], ['id' => '3', 'titel' => 'Derde']],
			$collected['objects']
		);
		$this->assertSame(['pending', 'pending'], array_column($collected['pickup']['picked'], 'after'));
		$this->assertSame(['besluiten-3.csv'], array_column($collected['pickup']['failed'], 'name'), 'the broken file is not read');
		$this->assertSame(['fetch in/besluiten-1.csv', 'fetch in/besluiten-2.csv', 'fetch in/besluiten-3.csv'], $this->events, 'nothing moved, nothing remembered yet');

		$this->events = [];
		$summary = $runner->complete(synchronization: $this->synchronization, source: [], pickup: $collected['pickup'], now: self::NOW);

		$this->assertSame(
			[
				'move in/besluiten-1.csv processed/besluiten-1.csv',
				'move in/besluiten-2.csv processed/besluiten-2.csv',
				'contract besluiten-1.csv -> mapping:besluiten-1.csv (2 records)',
				'contract besluiten-2.csv -> mapping:besluiten-2.csv (1 records)',
			],
			$this->events
		);
		$this->assertSame(['found' => 3, 'created' => 2, 'updated' => 0, 'skipped' => 0, 'invalid' => 1], array_diff_key($summary, ['files' => true]));
		$this->assertSame(['moved', 'moved'], array_column($summary['files']['picked'], 'after'));

		$this->events = [];
		$second = $runner->collect(synchronization: $this->synchronization, source: [], now: self::NOW);
		$this->assertSame([], $second['objects'], 'a second run reads nothing new');
		$this->assertSame(['fetch in/besluiten-3.csv'], $this->events, 'only the broken file is tried again');
	}//end testCollectReadsRecordsAndOnlyCompleteArchivesAndRemembers()

	/**
	 * A mapping pickup needs no Files folder, but still checks what follows the write.
	 *
	 * @return void
	 */
	public function testMappingNeedsNoFilesFolderButAnArchiveFolderForMove(): void {
		$synchronization = $this->synchronization;
		unset($synchronization['sourceConfig']['archivePath']);

		try {
			$this->runner()->collect(synchronization: $synchronization, source: [], now: self::NOW);
			$this->fail('a move without an archive folder was collected');
		} catch (RemoteFileException $exception) {
			$this->assertSame('sourceConfig.archivePath is required when after is move.', $exception->getMessage());
		}

		$this->assertSame([], $this->events);
	}//end testMappingNeedsNoFilesFolderButAnArchiveFolderForMove()

	/**
	 * Collect and complete refuse a synchronization configured for files.
	 *
	 * @return void
	 */
	public function testCollectRefusesAFilesTarget(): void {
		$synchronization = array_replace_recursive($this->synchronization, ['sourceConfig' => ['target' => 'files']]);

		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('This pickup runs target "mapping", the synchronization is configured for "files".');

		$this->runner()->collect(synchronization: $synchronization, source: [], now: self::NOW);
	}//end testCollectRefusesAFilesTarget()

	/**
	 * Only a mapping target feeds the mapping.
	 *
	 * @return void
	 */
	public function testFeedsMappingOnlyForTheMappingTarget(): void {
		$this->assertTrue(FilePickupSynchronization::feedsMapping(synchronization: $this->synchronization));
		$this->assertFalse(FilePickupSynchronization::feedsMapping(synchronization: ['sourceConfig' => ['target' => 'files']]));
		$this->assertFalse(FilePickupSynchronization::feedsMapping(synchronization: ['sourceConfig' => 'mapping']));
		$this->assertFalse(FilePickupSynchronization::feedsMapping(synchronization: []));
	}//end testFeedsMappingOnlyForTheMappingTarget()

	/**
	 * Archiving after the fact skips entries that are not pending and reports a failed move.
	 *
	 * @return void
	 */
	public function testArchivePickedOnlyArchivesPendingEntries(): void {
		$client = $this->createMock(RemoteFileClient::class);
		$client->expects($this->once())->method('delete')->with([], 'in/a.csv')->willThrowException(new RemoteFileException('permission denied'));
		$client->expects($this->never())->method('move');

		$pickup = new FilePickup(client: $client, logger: $this->createMock(LoggerInterface::class));
		$picked = $pickup->archivePicked(
			source: [],
			config: ['after' => 'delete'],
			picked: [
				['name' => 'a.csv', 'path' => 'in/a.csv', 'storedAt' => 'mapping:a.csv', 'after' => 'pending'],
				['name' => 'b.csv', 'path' => 'in/b.csv', 'storedAt' => 'mapping:b.csv', 'after' => 'kept'],
			]
		);

		$this->assertSame(['delete failed: permission denied', 'kept'], array_column($picked, 'after'));
	}//end testArchivePickedOnlyArchivesPendingEntries()
}//end class
