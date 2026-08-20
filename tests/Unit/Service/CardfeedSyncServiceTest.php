<?php

/**
 * Unit tests for CardfeedSyncService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-3
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-4
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\CardfeedProviderException;
use OCA\OpenConnector\Service\Cardfeed\LogCardfeedProvider;
use OCA\OpenConnector\Service\Cardfeed\RestCardfeedProvider;
use OCA\OpenConnector\Service\CardfeedSyncService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for enrollment, the scheduled cardfeed sync, and transaction-id idempotency.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md
 */
class CardfeedSyncServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogCardfeedProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var RestCardfeedProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $restProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var CardfeedSyncService
	 */
	private CardfeedSyncService $service;

	/**
	 * Every saveObject invocation captured as [schema => list of saved objects].
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $saved = [];

	/**
	 * Every emitted CloudEvent captured as [type, data] tuples.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $events = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->logProvider = $this->createMock(LogCardfeedProvider::class);
		$this->restProvider = $this->createMock(RestCardfeedProvider::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->saved = [];
		$this->events = [];

		$this->eventService->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data): array {
				$this->events[] = [$type, $data];
				return [];
			}
		);

		$this->service = new CardfeedSyncService(
			$this->objectService,
			$this->logProvider,
			$this->restProvider,
			$this->eventService,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, $data, $uuid);
	}//end entity()

	/**
	 * Route saveObject through a capturing callback that returns a real entity
	 * wrapping the saved payload (so magic getters keep working downstream).
	 *
	 * @return void
	 */
	private function captureSaves(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$key = (string)$schema;
				$this->saved[$key][] = $object;
				return $this->entity($object, ($uuid ?? 'saved-uuid-' . count($this->saved[$key])));
			}
		);

	}//end captureSaves()

	/**
	 * A cardfeed source entity (type cardfeed, log provider).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = []): ObjectEntity {
		return $this->entity(
			[
				'type' => 'cardfeed',
				'isEnabled' => true,
				'configuration' => array_merge(['provider' => 'log', 'cardProvider' => 'sandbox'], $configuration),
			],
			'source-uuid'
		);

	}//end sourceEntity()

	/**
	 * Build an active account with one card for sync tests.
	 *
	 * @param array $overrides Data merged over the defaults.
	 *
	 * @return ObjectEntity
	 */
	private function activeAccount(array $overrides = []): ObjectEntity {
		return $this->entity(
			array_merge(
				[
					'accountId' => 'acct-1',
					'cardfeedSourceSlug' => 'card-provider-sandbox',
					'provider' => 'sandbox',
					'cards' => [
						['cardId' => 'SANDBOX-CARD-1', 'last4' => '4242', 'cardholderName' => 'A. Example', 'currency' => 'EUR'],
					],
					'seenTransactionIds' => [],
					'lastSyncAt' => '2026-07-10T00:00:00+00:00',
					'lifecycleState' => 'active',
				],
				$overrides
			),
			'acct-uuid'
		);

	}//end activeAccount()

	/**
	 * List every emitted event type.
	 *
	 * @return array<int, string>
	 */
	private function emittedTypes(): array {
		return array_map(static fn (array $event) => $event[0], $this->events);
	}//end emittedTypes()

	// ---- provider resolution -------------------------------------------------

	/**
	 * resolveProvider selects the log provider by default and the REST provider for `rest`.
	 *
	 * @return void
	 */
	public function testResolveProviderSelectsBinding(): void {
		$this->assertSame($this->logProvider, $this->service->resolveProvider([]));
		$this->assertSame($this->logProvider, $this->service->resolveProvider(['provider' => 'log']));
		$this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

	}//end testResolveProviderSelectsBinding()

	// ---- enrollment (REQ-002) ------------------------------------------------

	/**
	 * enroll discovers cards and persists an `active` account carrying no secret — REQ-002.
	 *
	 * @return void
	 */
	public function testEnrollCreatesAccountAndRecordsCards(): void {
		$this->objectService->method('find')->willReturn($this->sourceEntity());
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->captureSaves();

		$this->logProvider->method('listCards')->willReturn(
			[
				['cardId' => 'SANDBOX-CARD-1', 'last4' => '4242', 'cardholderName' => 'A. Example', 'currency' => 'EUR'],
			]
		);

		$result = $this->service->enrollSource(sourceSlug: 'card-provider-sandbox');

		$this->assertNotEmpty($result['accountId']);
		$this->assertSame('active', $result['lifecycleState']);
		$this->assertCount(1, $result['cards']);

		$account = $this->saved['cardfeed_account'][0];
		$this->assertSame('active', $account['lifecycleState']);
		$this->assertSame('card-provider-sandbox', $account['cardfeedSourceSlug']);
		$this->assertSame('sandbox', $account['provider']);
		$this->assertSame('SANDBOX-CARD-1', $account['cards'][0]['cardId']);
		$this->assertSame([], $account['seenTransactionIds']);
		$this->assertArrayNotHasKey('credentialRef', $account);

	}//end testEnrollCreatesAccountAndRecordsCards()

	/**
	 * Re-enrolling updates the card set in place on the existing account and does
	 * NOT create a second account — REQ-002 idempotency.
	 *
	 * @return void
	 */
	public function testEnrollReenrollUpdatesInPlaceNoDuplicateAccount(): void {
		$existing = $this->entity(
			[
				'accountId' => 'acct-existing',
				'cardfeedSourceSlug' => 'card-provider-sandbox',
				'provider' => 'sandbox',
				'cards' => [['cardId' => 'OLD-CARD', 'last4' => '0000', 'cardholderName' => 'Old', 'currency' => 'EUR']],
				'seenTransactionIds' => ['CTX-OLD'],
				'lifecycleState' => 'active',
			],
			'acct-existing-uuid'
		);
		$this->objectService->method('find')->willReturn($this->sourceEntity());
		$this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);
		$this->captureSaves();

		// Provider answer contains an internal duplicate — the recorded set must
		// still be unique per cardId.
		$this->logProvider->method('listCards')->willReturn(
			[
				['cardId' => 'SANDBOX-CARD-1', 'last4' => '4242', 'cardholderName' => 'A. Example', 'currency' => 'EUR'],
				['cardId' => 'SANDBOX-CARD-1', 'last4' => '4242', 'cardholderName' => 'A. Example', 'currency' => 'EUR'],
			]
		);

		$result = $this->service->enrollSource(sourceSlug: 'card-provider-sandbox');

		$this->assertSame('acct-existing', $result['accountId']);
		$this->assertCount(1, $this->saved['cardfeed_account']);

		$account = $this->saved['cardfeed_account'][0];
		$this->assertSame('acct-existing', $account['accountId']);
		$this->assertCount(1, $account['cards']);
		$this->assertSame('SANDBOX-CARD-1', $account['cards'][0]['cardId']);

	}//end testEnrollReenrollUpdatesInPlaceNoDuplicateAccount()

	/**
	 * enroll rejects a source that is not a cardfeed source.
	 *
	 * @return void
	 */
	public function testEnrollRejectsNonCardfeedSource(): void {
		$this->objectService->method('find')->willReturn($this->entity(['type' => 'api'], 'source-uuid'));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(CardfeedProviderException::class);

		$this->service->enrollSource(sourceSlug: 'example-rest-api');

	}//end testEnrollRejectsNonCardfeedSource()

	// ---- scheduled sync (REQ-003) --------------------------------------------

	/**
	 * A scheduled pull persists a cardfeed_batch and emits the synced event with
	 * {accountId, cardId, since, until, transactionCount, batchUri} — REQ-003.
	 *
	 * @return void
	 */
	public function testSyncAllPersistsBatchAndEmitsSyncedEvent(): void {
		$account = $this->activeAccount();
		$this->objectService->method('findAll')->willReturn(['results' => [$account], 'total' => 1]);
		$this->objectService->method('find')->willReturn($this->sourceEntity());
		$this->captureSaves();

		$rows = [['transactionId' => 'CTX-1'], ['transactionId' => 'CTX-2']];
		$this->logProvider->method('listTransactions')->willReturn($rows);

		$batches = $this->service->syncAll();

		$this->assertSame(1, $batches);

		$batch = $this->saved['cardfeed_batch'][0];
		$this->assertSame('acct-1', $batch['accountId']);
		$this->assertSame('SANDBOX-CARD-1', $batch['cardId']);
		$this->assertSame('2026-07-10T00:00:00+00:00', $batch['since']);
		$this->assertSame(2, $batch['transactionCount']);
		$this->assertSame($rows, $batch['transactions']);

		$this->assertContains(CardfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());
		$syncedIndex = array_search(CardfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes(), true);
		$payload = $this->events[$syncedIndex][1];
		$this->assertSame('acct-1', $payload['accountId']);
		$this->assertSame('SANDBOX-CARD-1', $payload['cardId']);
		$this->assertSame('2026-07-10T00:00:00+00:00', $payload['since']);
		$this->assertNotEmpty($payload['until']);
		$this->assertSame(2, $payload['transactionCount']);
		$this->assertStringContainsString('/apps/openregister/api/objects/openconnector/cardfeed_batch/', $payload['batchUri']);

		// Watermark advanced and the two ids recorded in the seen set.
		$accountSaves = $this->saved['cardfeed_account'];
		$lastSave = end($accountSaves);
		$this->assertSame($payload['until'], $lastSave['lastSyncAt']);
		$this->assertSame(['CTX-1', 'CTX-2'], $lastSave['seenTransactionIds']);

	}//end testSyncAllPersistsBatchAndEmitsSyncedEvent()

	/**
	 * A replayed sync — the provider returning the same transactions — persists no
	 * new batch and emits no synced event on the second sweep. REQ-004.
	 *
	 * @return void
	 */
	public function testSyncAllIsIdempotentOnReplay(): void {
		$account = $this->activeAccount();
		$this->objectService->method('findAll')->willReturn(['results' => [$account], 'total' => 1]);
		$this->objectService->method('find')->willReturn($this->sourceEntity());
		$this->captureSaves();

		// Stable ids across sweeps — exactly what the log sandbox provider does.
		$rows = [['transactionId' => 'CTX-1'], ['transactionId' => 'CTX-2']];
		$this->logProvider->method('listTransactions')->willReturn($rows);

		// First sweep: a batch is persisted and the synced event emitted.
		$firstBatches = $this->service->syncAll();
		$this->assertSame(1, $firstBatches);
		$this->assertCount(1, $this->saved['cardfeed_batch']);
		$this->assertContains(CardfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());

		// Second sweep on the (now-seen) account: no new batch, no synced event.
		$this->events = [];
		$secondBatches = $this->service->syncAll();

		$this->assertSame(0, $secondBatches);
		$this->assertCount(1, $this->saved['cardfeed_batch']);
		$this->assertNotContains(CardfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());

	}//end testSyncAllIsIdempotentOnReplay()

	/**
	 * A disabled account is skipped — no provider call, no synced event (REQ-003).
	 *
	 * @return void
	 */
	public function testSyncAllSkipsDisabledAccount(): void {
		$account = $this->activeAccount(['lifecycleState' => 'disabled']);
		$this->objectService->method('findAll')->willReturn(['results' => [$account], 'total' => 1]);
		$this->captureSaves();

		$this->logProvider->expects($this->never())->method('listTransactions');

		$batches = $this->service->syncAll();

		$this->assertSame(0, $batches);
		$this->assertSame([], $this->events);
		$this->assertArrayNotHasKey('cardfeed_batch', $this->saved);

	}//end testSyncAllSkipsDisabledAccount()

	/**
	 * A failed pull persists nothing and does NOT advance the watermark, so the
	 * same window is re-attempted next sweep — REQ-003.
	 *
	 * @return void
	 */
	public function testSyncAllFailedPullDoesNotAdvanceWatermark(): void {
		$account = $this->activeAccount();
		$this->objectService->method('findAll')->willReturn(['results' => [$account], 'total' => 1]);
		$this->objectService->method('find')->willReturn($this->sourceEntity());
		$this->captureSaves();

		$this->logProvider->method('listTransactions')
			->willThrowException(new CardfeedProviderException(message: 'card provider unreachable'));

		$batches = $this->service->syncAll();

		$this->assertSame(0, $batches);
		// Nothing persisted at all: no batch, no watermark advance.
		$this->assertSame([], $this->saved);
		$this->assertSame('2026-07-10T00:00:00+00:00', $account->getObject()['lastSyncAt']);
		$this->assertSame([], $this->events);

	}//end testSyncAllFailedPullDoesNotAdvanceWatermark()
}//end class
