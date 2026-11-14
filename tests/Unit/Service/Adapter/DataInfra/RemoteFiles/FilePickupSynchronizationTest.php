<?php

/**
 * Unit tests for FilePickupSynchronization.
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
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderStore;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A pickup run as a synchronization: files into the owner's Files, one contract per file.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
#[CoversClass(FilePickupSynchronization::class)]
#[UsesClass(FilePickup::class)]
#[UsesClass(NextcloudFolderStore::class)]
#[UsesClass(RemoteFileEntry::class)]
#[UsesClass(RemoteFileException::class)]
final class FilePickupSynchronizationTest extends TestCase {

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
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * What happened, in order.
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
	 * The synchronization under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $synchronization = [
		'id'           => 'sync-1',
		'sourceType'   => 'sftp',
		'sourceId'     => 'source-1',
		'@self'        => ['owner' => 'alice'],
		'sourceConfig' => [
			'path'        => '',
			'pattern'     => 'besluiten-*.csv',
			'after'       => 'move',
			'archivePath' => 'processed',
			'filesFolder' => 'Partner/besluiten',
		],
	];

	/**
	 * Set up a stand-in server, a contract store and Files.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(RemoteFileClient::class);
		$this->client->method('listFiles')->willReturnCallback(
			function (array $source, string $path, string $pattern): array {
				$this->events[] = 'list ' . $pattern;
				return [
					new RemoteFileEntry(name: 'besluiten-1.csv', path: 'besluiten-1.csv', size: 10, modifiedAt: self::NOW - 3600),
					new RemoteFileEntry(name: 'besluiten-2.csv', path: 'besluiten-2.csv', size: 20, modifiedAt: self::NOW - 3600),
					new RemoteFileEntry(name: 'besluiten-3.csv', path: 'besluiten-3.csv', size: 30, modifiedAt: self::NOW - 10),
				];
			}
		);
		$this->client->method('fetch')->willReturnCallback(
			function (array $source, string $path, $target): void {
				$this->events[] = 'fetch ' . $path;
				fwrite($target, 'rows of ' . $path);
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
				$this->events[] = 'contract create ' . $object['originId'];
				$object['id']   = 'contract-' . count($this->stored);
				$this->stored[] = $object;
				return $object;
			}
		);
		$this->contracts->method('updateFromArray')->willReturnCallback(
			function (string|int $id, array $object): array {
				$this->events[] = 'contract update ' . $id . ' ' . $object['originId'];
				return $object;
			}
		);

		$this->rootFolder = $this->createMock(IRootFolder::class);

	}//end setUp()

	/**
	 * The run under test.
	 *
	 * @return FilePickupSynchronization
	 */
	private function runner(): FilePickupSynchronization {
		return new FilePickupSynchronization(
			pickup: new FilePickup(client: $this->client, logger: $this->createMock(LoggerInterface::class)),
			client: $this->client,
			contracts: $this->contracts,
			rootFolder: $this->rootFolder
		);

	}//end runner()

	/**
	 * Files for one user: a folder chain that records what is written into it.
	 *
	 * @param string $user The user whose Files are expected.
	 *
	 * @return void
	 */
	private function filesOf(string $user): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($folder);
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content): File {
				$this->events[] = 'store ' . $name . ' = ' . stream_get_contents($content);
				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn('/alice/files/Partner/besluiten/' . $name);
				return $file;
			}
		);
		$this->rootFolder->expects($this->atLeastOnce())->method('getUserFolder')->with($user)->willReturn($folder);

	}//end filesOf()

	/**
	 * The stable files go into the owner's Files, one contract each, and a second run fetches nothing.
	 *
	 * @return void
	 */
	public function testPicksUpIntoTheOwnersFilesAndRemembersEachFile(): void {
		$this->filesOf(user: 'alice');

		$first = $this->runner()->run(synchronization: $this->synchronization, source: ['type' => 'sftp'], now: self::NOW);

		$this->assertSame(['found' => 3, 'created' => 2, 'updated' => 0, 'skipped' => 1, 'invalid' => 0], array_diff_key($first, ['files' => true]));
		$this->assertSame('besluiten-3.csv', $first['files']['skipped'][0]['name']);
		$this->assertSame(
			[
				'list besluiten-*.csv',
				'fetch besluiten-1.csv', 'store besluiten-1.csv = rows of besluiten-1.csv', 'move besluiten-1.csv processed/besluiten-1.csv',
				'fetch besluiten-2.csv', 'store besluiten-2.csv = rows of besluiten-2.csv', 'move besluiten-2.csv processed/besluiten-2.csv',
				'contract create besluiten-1.csv', 'contract create besluiten-2.csv',
			],
			$this->events
		);
		$this->assertSame(
			[
				'synchronizationId' => 'sync-1',
				'originId'          => 'besluiten-1.csv',
				'originHash'        => (self::NOW - 3600) . ':10',
				'targetId'          => '/alice/files/Partner/besluiten/besluiten-1.csv',
				'targetLastAction'  => 'create',
				'sourceLastSynced'  => date(DATE_ATOM, self::NOW),
				'id'                => 'contract-0',
			],
			$this->stored[0]
		);

		$this->events = [];
		$second       = $this->runner()->run(synchronization: $this->synchronization, source: ['type' => 'sftp'], now: self::NOW + 60);

		$this->assertSame(1, $second['created'], 'only the file that has become stable is new');
		$this->assertSame(
			['list besluiten-*.csv', 'fetch besluiten-3.csv', 'store besluiten-3.csv = rows of besluiten-3.csv', 'move besluiten-3.csv processed/besluiten-3.csv', 'contract create besluiten-3.csv'],
			$this->events
		);

	}//end testPicksUpIntoTheOwnersFilesAndRemembersEachFile()

	/**
	 * A file the partner sends again with new content is picked up again, and its contract updated.
	 *
	 * @return void
	 */
	public function testAFileSentAgainUpdatesItsContract(): void {
		$this->filesOf(user: 'alice');
		$this->stored = [
			['id' => 'c-1', 'synchronizationId' => 'sync-1', 'originId' => 'besluiten-1.csv', 'originHash' => 'an older version'],
			['id' => 'c-2', 'synchronizationId' => 'sync-1', 'originId' => 'besluiten-2.csv', 'originHash' => (self::NOW - 3600) . ':20'],
			['id' => 'c-9', 'synchronizationId' => 'another-sync', 'originId' => 'besluiten-3.csv', 'originHash' => (self::NOW - 10) . ':30'],
		];

		$result = $this->runner()->run(synchronization: $this->synchronization, source: [], now: self::NOW + 3600);

		$this->assertSame(1, $result['updated']);
		$this->assertSame(1, $result['created'], 'a contract of another synchronization does not count');
		$this->assertContains('contract update c-1 besluiten-1.csv', $this->events);
		$this->assertNotContains('fetch besluiten-2.csv', $this->events);
		$this->assertContains('contract create besluiten-3.csv', $this->events);

	}//end testAFileSentAgainUpdatesItsContract()

	/**
	 * A test run lists the new files and fetches, stores, archives and records nothing.
	 *
	 * @return void
	 */
	public function testATestRunOnlyLists(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->contracts->expects($this->never())->method('createFromArray');
		$this->stored = [['id' => 'c-1', 'synchronizationId' => 'sync-1', 'originId' => 'besluiten-1.csv', 'originHash' => (self::NOW - 3600) . ':10']];

		$result = $this->runner()->run(synchronization: $this->synchronization, source: [], isTest: true, now: self::NOW);

		$this->assertSame(['list besluiten-*.csv'], $this->events);
		$this->assertSame(['besluiten-2.csv'], array_column($result['files']['new'], 'name'));
		$this->assertSame(['besluiten-3.csv'], array_column($result['files']['skipped'], 'name'));
		$this->assertSame(2, $result['found']);
		$this->assertSame(0, $result['created']);

	}//end testATestRunOnlyLists()

	/**
	 * A synchronization that cannot be run is refused before the server is asked anything.
	 *
	 * @return void
	 */
	public function testRefusesWhatCannotBeRun(): void {
		$cases = [
			'no id'            => [array_diff_key($this->synchronization, ['id' => true]), 'needs a saved synchronization'],
			'no owner'         => [['@self' => []] + $this->synchronization, 'has no owner'],
			'a mapping target' => [array_replace_recursive($this->synchronization, ['sourceConfig' => ['target' => 'mapping']]), 'sourceConfig.target "mapping" is not supported'],
			'a scalar config'  => [['sourceConfig' => 'x'] + $this->synchronization, 'sourceConfig must be an object'],
		];

		foreach ($cases as $case => [$synchronization, $message]) {
			try {
				$this->runner()->run(synchronization: $synchronization, source: [], now: self::NOW);
				$this->fail($case . ' was run');
			} catch (RemoteFileException $exception) {
				$this->assertStringContainsString($message, $exception->getMessage(), $case);
			}
		}

		$this->assertSame([], $this->events, 'nothing was listed or fetched');

	}//end testRefusesWhatCannotBeRun()

	/**
	 * Only an sftp or ftps source type is a pickup.
	 *
	 * @return void
	 */
	public function testHandlesOnlyFileSourceTypes(): void {
		$this->assertTrue(FilePickupSynchronization::handles(synchronization: ['sourceType' => 'sftp']));
		$this->assertTrue(FilePickupSynchronization::handles(synchronization: ['sourceType' => 'ftps']));
		$this->assertFalse(FilePickupSynchronization::handles(synchronization: ['sourceType' => 'ftp']));
		$this->assertFalse(FilePickupSynchronization::handles(synchronization: ['sourceType' => 'api']));
		$this->assertFalse(FilePickupSynchronization::handles(synchronization: []));

	}//end testHandlesOnlyFileSourceTypes()
}//end class
