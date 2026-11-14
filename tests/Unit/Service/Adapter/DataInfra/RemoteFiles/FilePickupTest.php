<?php

/**
 * New files are picked up once and archived only after a safe local write.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FilePickup;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\PickedFileStore;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers FilePickup over a mocked remote client and an in-memory store.
 */
#[CoversClass(FilePickup::class)]
#[UsesClass(RemoteFileEntry::class)]
#[UsesClass(RemoteFileException::class)]
final class FilePickupTest extends TestCase {

	/**
	 * The time of the run.
	 */
	private const NOW = 1763100000;

	/**
	 * The remote client.
	 *
	 * @var RemoteFileClient&MockObject
	 */
	private RemoteFileClient $client;

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * The morning batch: three new besluiten files.
	 *
	 * @return list<RemoteFileEntry>
	 */
	private function batch(): array {
		return [
			new RemoteFileEntry(name: 'besluiten-1.csv', path: 'besluiten-1.csv', size: 10, modifiedAt: self::NOW - 3600),
			new RemoteFileEntry(name: 'besluiten-2.csv', path: 'besluiten-2.csv', size: 20, modifiedAt: self::NOW - 3600),
			new RemoteFileEntry(name: 'besluiten-3.csv', path: 'besluiten-3.csv', size: 30, modifiedAt: self::NOW - 3600),
		];
	}//end batch()

	/**
	 * Set up a client that serves the batch and logs every call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(RemoteFileClient::class);
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
		$this->client->method('delete')->willReturnCallback(
			function (array $source, string $path): void {
				$this->events[] = 'delete ' . $path;
			}
		);
	}//end setUp()

	/**
	 * A store that keeps files in memory, or fails for the names given.
	 *
	 * @param list<string> $failFor Names whose write fails.
	 *
	 * @return PickedFileStore
	 */
	private function store(array $failFor=[]): PickedFileStore {
		$events = &$this->events;
		return new class($events, $failFor) implements PickedFileStore {
			/**
			 * @param list<string> $events  The shared event log.
			 * @param list<string> $failFor Names whose write fails.
			 */
			public function __construct(private array &$events, private array $failFor) {
			}

			/** {@inheritDoc} */
			public function store(string $folder, string $name, $stream): string {
				if (in_array($name, $this->failFor, true) === true) {
					throw new \RuntimeException('disk full');
				}

				$this->events[] = 'store ' . $folder . '/' . $name . ' = ' . stream_get_contents($stream);
				return '/admin/files/' . $folder . '/' . $name;
			}
		};
	}//end store()

	/**
	 * The pickup under test.
	 *
	 * @return FilePickup
	 */
	private function pickup(): FilePickup {
		return new FilePickup(client: $this->client, logger: $this->createMock(LoggerInterface::class));
	}//end pickup()

	/**
	 * The morning batch lands in the Files folder, moves to the archive, and the next run fetches nothing.
	 *
	 * @return void
	 */
	public function testTheMorningBatchIsStoredArchivedAndNotFetchedAgain(): void {
		$this->client->method('listFiles')->willReturnCallback(
			function (array $source, string $path, string $pattern): array {
				$this->events[] = 'list ' . $path . ' ' . $pattern;
				return $this->batch();
			}
		);
		$config = ['path' => '', 'pattern' => 'besluiten-*.csv', 'after' => 'move', 'archivePath' => 'processed', 'filesFolder' => 'Partner/besluiten'];

		$first = $this->pickup()->run(source: [], config: $config, store: $this->store(), seen: [], now: self::NOW);

		$this->assertSame(['besluiten-1.csv', 'besluiten-2.csv', 'besluiten-3.csv'], array_column($first['picked'], 'name'));
		$this->assertSame(['moved', 'moved', 'moved'], array_column($first['picked'], 'after'));
		$this->assertSame('/admin/files/Partner/besluiten/besluiten-1.csv', $first['picked'][0]['storedAt']);
		$this->assertSame(
			[
				'list  besluiten-*.csv',
				'fetch besluiten-1.csv', 'store Partner/besluiten/besluiten-1.csv = rows of besluiten-1.csv', 'move besluiten-1.csv processed/besluiten-1.csv',
				'fetch besluiten-2.csv', 'store Partner/besluiten/besluiten-2.csv = rows of besluiten-2.csv', 'move besluiten-2.csv processed/besluiten-2.csv',
				'fetch besluiten-3.csv', 'store Partner/besluiten/besluiten-3.csv = rows of besluiten-3.csv', 'move besluiten-3.csv processed/besluiten-3.csv',
			],
			$this->events,
			'each remote move follows its own local write'
		);

		$this->events = [];
		$second       = $this->pickup()->run(source: [], config: $config, store: $this->store(), seen: $first['seen'], now: self::NOW + 60);
		$this->assertSame([], $second['picked']);
		$this->assertSame(['list  besluiten-*.csv'], $this->events, 'the second run fetches nothing');
	}//end testTheMorningBatchIsStoredArchivedAndNotFetchedAgain()

	/**
	 * A failed local write leaves the remote file where it was, and the next run tries it again.
	 *
	 * @return void
	 */
	public function testAFailedLocalWriteLeavesTheRemoteFileInPlace(): void {
		$this->client->method('listFiles')->willReturn($this->batch());
		$config = ['pattern' => '*.csv', 'after' => 'delete', 'filesFolder' => 'Partner'];

		$result = $this->pickup()->run(source: [], config: $config, store: $this->store(failFor: ['besluiten-2.csv']), now: self::NOW);

		$this->assertSame([['name' => 'besluiten-2.csv', 'reason' => 'disk full']], $result['failed']);
		$this->assertNotContains('delete besluiten-2.csv', $this->events);
		$this->assertContains('delete besluiten-1.csv', $this->events);
		$this->assertContains('delete besluiten-3.csv', $this->events);
		$this->assertArrayNotHasKey('besluiten-2.csv', $result['seen'], 'not seen, so the next run picks it up');
	}//end testAFailedLocalWriteLeavesTheRemoteFileInPlace()

	/**
	 * A file still being written is skipped; a changed file under the same name is picked up again; keep leaves it.
	 *
	 * @return void
	 */
	public function testStabilityWindowChangedFilesAndKeep(): void {
		$this->client->method('listFiles')->willReturn(
			[
				new RemoteFileEntry(name: 'busy.csv', path: 'busy.csv', size: 5, modifiedAt: self::NOW - 10),
				new RemoteFileEntry(name: 'resent.csv', path: 'resent.csv', size: 99, modifiedAt: self::NOW - 600),
			]
		);
		$seen = ['resent.csv' => (self::NOW - 7200) . ':40'];

		$result = $this->pickup()->run(source: [], config: ['after' => 'keep', 'filesFolder' => 'Partner'], store: $this->store(), seen: $seen, now: self::NOW);

		$this->assertSame([['name' => 'busy.csv', 'reason' => 'modified less than 60 seconds ago']], $result['skipped']);
		$this->assertSame([['name' => 'resent.csv', 'storedAt' => '/admin/files/Partner/resent.csv', 'after' => 'kept']], $result['picked']);
		$this->assertSame((self::NOW - 600) . ':99', $result['seen']['resent.csv']);
		$this->assertSame([], array_filter($this->events, fn (string $e): bool => str_starts_with($e, 'move') || str_starts_with($e, 'delete')));
	}//end testStabilityWindowChangedFilesAndKeep()

	/**
	 * A remote move that fails after the local write is reported, and the file still counts as seen.
	 *
	 * @return void
	 */
	public function testAFailedArchiveIsReportedAndTheFileIsNotFetchedTwice(): void {
		$client = $this->createMock(RemoteFileClient::class);
		$client->method('listFiles')->willReturn([$this->batch()[0]]);
		$client->method('move')->willThrowException(new RemoteFileException('permission denied'));
		$pickup = new FilePickup(client: $client, logger: $this->createMock(LoggerInterface::class));

		$result = $pickup->run(source: [], config: ['after' => 'move', 'archivePath' => 'processed', 'filesFolder' => 'P'], store: $this->store(), now: self::NOW);

		$this->assertSame('move failed: permission denied', $result['picked'][0]['after']);
		$this->assertArrayHasKey('besluiten-1.csv', $result['seen']);
	}//end testAFailedArchiveIsReportedAndTheFileIsNotFetchedTwice()

	/**
	 * A wrong configuration is refused before the server is listed.
	 *
	 * @return void
	 */
	public function testAWrongConfigurationIsRefused(): void {
		$this->client->expects($this->never())->method('listFiles');
		foreach ([
			[['after' => 'shred', 'filesFolder' => 'P'], 'must be move, delete or keep'],
			[['after' => 'move', 'filesFolder' => 'P'], 'archivePath is required'],
			[['after' => 'keep'], 'filesFolder is required'],
		] as [$config, $message]) {
			try {
				$this->pickup()->run(source: [], config: $config, store: $this->store());
				$this->fail('Accepted ' . json_encode($config));
			} catch (RemoteFileException $refusal) {
				$this->assertStringContainsString($message, $refusal->getMessage());
			}
		}
	}//end testAWrongConfigurationIsRefused()
}//end class
