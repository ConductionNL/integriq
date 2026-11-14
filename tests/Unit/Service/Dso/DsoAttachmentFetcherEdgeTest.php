<?php

/**
 * Edge cases of DsoAttachmentFetcher: a missing request, nothing left to do,
 * an unusable FileService and an unreachable source.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\DsoIngestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * What fetchPending() does when it cannot or need not download anything.
 */
#[CoversClass(DsoAttachmentFetcher::class)]
class DsoAttachmentFetcherEdgeTest extends TestCase {

	/**
	 * The stored request, or null when there is none.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $stored = null;

	/**
	 * Every saveObject() payload.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	private ORObjectService&MockObject $objectService;

	private DsoIngestService&MockObject $ingest;

	private DsoClient&MockObject $client;

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$this->objectService->method('find')->willReturnCallback(fn () => $this->stored);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = (array)$object;
				$entity = new ObjectEntity();
				$entity->setUuid((string)$uuid);
				$entity->setObject((array)$object);
				$this->stored = $entity;

				return $entity;
			}
		);
		$this->ingest = $this->getMockBuilder(DsoIngestService::class)->disableOriginalConstructor()->getMock();
		$this->client = $this->createMock(DsoClient::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The fetcher over the doubles.
	 *
	 * @return DsoAttachmentFetcher
	 */
	private function fetcher(): DsoAttachmentFetcher {
		return new DsoAttachmentFetcher(
			objectService: $this->objectService,
			ingestService: $this->ingest,
			client: $this->client,
			container: $this->container,
			logger: $this->logger
		);

	}//end fetcher()

	/**
	 * Store a request with the given attachment entries.
	 *
	 * @param array<int, mixed> $attachments The entries.
	 *
	 * @return void
	 */
	private function storeRequest(array $attachments): void {
		$entity = new ObjectEntity();
		$entity->setUuid('verzoek-1');
		$entity->setObject(['verzoekId' => 'dso-1', 'attachments' => $attachments]);
		$this->stored = $entity;

	}//end storeRequest()

	/**
	 * A request that does not exist gives null, a warning, and touches nothing else.
	 *
	 * @return void
	 */
	public function testAMissingRequestGivesNullAndAWarning(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('[DsoAttachmentFetcher] no dso_verzoek verzoek-x; nothing fetched');
		$this->container->expects($this->never())->method('get');
		$this->ingest->expects($this->never())->method('resolveActiveSourceAsEngine');

		$this->assertNull($this->fetcher()->fetchPending('verzoek-x'));
		$this->assertSame([], $this->saves);

	}//end testAMissingRequestGivesNullAndAWarning()

	/**
	 * With nothing pending, the list comes back as stored, re-indexed, and nothing is resolved or saved.
	 *
	 * @return void
	 */
	public function testNothingPendingReturnsTheListUntouched(): void {
		$done = [
			3 => ['name' => 'a.pdf', 'status' => 'stored', 'fileId' => 7],
			5 => ['name' => 'b.pdf', 'status' => 'failed', 'attempts' => DsoAttachmentFetcher::MAX_ATTEMPTS],
			8 => 'not-an-entry',
		];
		$this->storeRequest($done);
		$this->container->expects($this->never())->method('get');
		$this->ingest->expects($this->never())->method('resolveActiveSourceAsEngine');

		$this->assertSame(array_values($done), $this->fetcher()->fetchPending('verzoek-1'));
		$this->assertSame([], $this->saves);

	}//end testNothingPendingReturnsTheListUntouched()

	/**
	 * A container entry that is not OpenRegister's FileService leaves every entry pending.
	 *
	 * @return void
	 */
	public function testAFileServiceOfTheWrongTypeLeavesEntriesPending(): void {
		$pending = [['name' => 'a.pdf', 'url' => 'https://dso.example/a.pdf', 'status' => 'pending', 'attempts' => 0]];
		$this->storeRequest($pending);
		$this->container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\FileService')
			->willReturn(new stdClass());
		$this->logger->expects($this->once())->method('warning')
			->with('[DsoAttachmentFetcher] OpenRegister FileService has an unexpected type; bijlagen stay pending');
		$this->ingest->expects($this->never())->method('resolveActiveSourceAsEngine');

		$this->assertSame($pending, $this->fetcher()->fetchPending('verzoek-1'));
		$this->assertSame([], $this->saves);

	}//end testAFileServiceOfTheWrongTypeLeavesEntriesPending()

	/**
	 * An unreachable source marks each due entry failed with the reason, keeps its attempts, and flags the request.
	 *
	 * @return void
	 */
	public function testAnUnreachableSourceFailsEachDueEntryWithoutSpendingAnAttempt(): void {
		$this->storeRequest([
			['name' => 'a.pdf', 'url' => 'https://dso.example/a.pdf', 'status' => 'pending', 'attempts' => 0],
			['name' => 'b.pdf', 'status' => 'stored', 'fileId' => 9],
			['name' => 'c.pdf', 'url' => 'https://dso.example/c.pdf', 'status' => 'failed', 'attempts' => 1, 'error' => 'old'],
		]);
		$fileService = $this->getMockBuilder(\OCA\OpenRegister\Service\FileService::class)->disableOriginalConstructor()->getMock();
		$fileService->expects($this->never())->method('addFile');
		$this->container->method('get')->willReturn($fileService);
		$this->ingest->expects($this->once())->method('resolveActiveSourceAsEngine')
			->willThrowException(new DsoProviderException('No active DSO source.'));
		$this->client->expects($this->never())->method($this->anything());

		$attachments = $this->fetcher()->fetchPending('verzoek-1');

		$this->assertSame(
			[
				['name' => 'a.pdf', 'url' => 'https://dso.example/a.pdf', 'status' => 'failed', 'attempts' => 0, 'error' => 'No active DSO source.'],
				['name' => 'b.pdf', 'status' => 'stored', 'fileId' => 9],
				['name' => 'c.pdf', 'url' => 'https://dso.example/c.pdf', 'status' => 'failed', 'attempts' => 1, 'error' => 'No active DSO source.'],
			],
			$attachments
		);
		$this->assertCount(2, $this->saves, 'one save per due entry');
		$this->assertTrue($this->saves[1]['attachmentMissing']);
		$this->assertSame($attachments, $this->stored->getObject()['attachments']);

	}//end testAnUnreachableSourceFailsEachDueEntryWithoutSpendingAnAttempt()

	/**
	 * Which entries still need a download.
	 *
	 * @return void
	 */
	public function testNeedsDownloadOnlyForPendingAndRetryableFailures(): void {
		$fetcher = $this->fetcher();

		$this->assertFalse($fetcher->needsDownload('pending'));
		$this->assertFalse($fetcher->needsDownload(null));
		$this->assertTrue($fetcher->needsDownload([]), 'no status means pending');
		$this->assertTrue($fetcher->needsDownload(['status' => 'pending']));
		$this->assertTrue($fetcher->needsDownload(['status' => 'failed']));
		$this->assertTrue($fetcher->needsDownload(['status' => 'failed', 'attempts' => DsoAttachmentFetcher::MAX_ATTEMPTS - 1]));
		$this->assertFalse($fetcher->needsDownload(['status' => 'failed', 'attempts' => DsoAttachmentFetcher::MAX_ATTEMPTS]));
		$this->assertFalse($fetcher->needsDownload(['status' => 'stored']));
		$this->assertFalse($fetcher->needsDownload(['status' => 'too-large']));

	}//end testNeedsDownloadOnlyForPendingAndRetryableFailures()

	/**
	 * A missing bijlage is flagged only by failed or too-large entries.
	 *
	 * @return void
	 */
	public function testIsAttachmentMissingOnlyForFailedOrTooLarge(): void {
		$fetcher = $this->fetcher();

		$this->assertFalse($fetcher->isAttachmentMissing([]));
		$this->assertFalse($fetcher->isAttachmentMissing([['status' => 'stored'], ['status' => 'pending'], []]));
		$this->assertTrue($fetcher->isAttachmentMissing([['status' => 'stored'], ['status' => 'too-large']]));
		$this->assertTrue($fetcher->isAttachmentMissing([['status' => 'failed']]));

	}//end testIsAttachmentMissingOnlyForFailedOrTooLarge()
}//end class
