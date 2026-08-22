<?php

/**
 * Unit tests for incremental-sync cursor templating (REQ-016) and cursor
 * watermark advance/no-advance gating (REQ-017).
 *
 * Change: cdc-incremental-sync. Composes with the existing
 * sync-safety-guardrails fetch-completeness signal (REQ-009/REQ-011) — a
 * rate-limited, partially-paginated, or test run must never advance the
 * stored cursorWatermark, mirroring the existing deletion guard's own
 * treatment of the same `$fetchComplete` boolean.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\FileService as OrFileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Twig\Loader\ArrayLoader;

/**
 * Tests REQ-016 (cursor-filtered fetch request) and REQ-017 (watermark
 * advance gating).
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017
 */
class SynchronizationServiceIncrementalCursorTest extends TestCase {

	private const SYNC_ID = 'sync-uuid-cursor';

	/**
	 * @var SynchronizationService&MockObject
	 */
	private $service;

	/**
	 * @var ORObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * @var array<int, string> Endpoints captured from every CallService::call() invocation.
	 */
	private array $calledEndpoints = [];

	/**
	 * @var array<int, array> Configs (headers/query/body) captured from every CallService::call().
	 */
	private array $calledConfigs = [];

	/**
	 * Set up test fixtures: a partial mock isolating `updateTarget()` (the
	 * only path to an actual target write/delete) so the real fetch and
	 * cursor/watermark logic run against a stubbed CallService/OR.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$logger = $this->createMock(LoggerInterface::class);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		// A real MappingService (not a mock) so renderTemplateString() actually
		// Twig-renders the `{{ cursor }}` context key under test — every
		// constructor dependency beyond the ArrayLoader is unused by that
		// one method and is safely stubbed.
		$mappingService = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(OrFileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class)
		);

		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($logOrService, $userSession, $session);

		$this->service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$this->callService,
					$mappingService,
					$container,
					$this->orObjectService,
					$objectService,
					$logger,
					$logService,
					$appConfig,
					$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();
	}//end setUp()

	/**
	 * Build the synchronization payload used by every scenario here.
	 *
	 * @param array $extra Extra top-level synchronization keys to merge in.
	 * @param array $sourceConfigExtra Extra sourceConfig keys to merge in.
	 *
	 * @return array
	 */
	private function makeSyncPayload(array $extra = [], array $sourceConfigExtra = []): array {
		return array_merge(
			[
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'sourceId' => 'source-uuid-cursor',
				'sourceType' => 'api',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => array_merge(
					[
						'resultsPosition' => 'items',
						'usesPagination' => false,
					],
					$sourceConfigExtra
				),
			],
			$extra
		);
	}//end makeSyncPayload()

	/**
	 * Stub `orObjectService::find()` to return a source entity.
	 *
	 * @return void
	 */
	private function stubSourceFind(): void {
		$sourceEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['location' => 'https://example.test', 'enabled' => true],
			'source-uuid-cursor'
		);
		$this->orObjectService->method('find')->willReturn($sourceEntity);
	}//end stubSourceFind()

	/**
	 * Wire `callService::call()` to record the requested endpoint/config and
	 * return a single page of the given items, then (if pagination is
	 * enabled) a naturally-empty terminating page.
	 *
	 * @param array $items The item(s) the single/first page returns.
	 *
	 * @return void
	 */
	private function stubCallReturningItems(array $items): void {
		$this->calledEndpoints = [];
		$this->calledConfigs = [];
		$page = 0;

		$this->callService->method('call')->willReturnCallback(
			function ($source, $endpoint, $method, $config) use (&$page, $items) {
				$page++;
				$this->calledEndpoints[] = $endpoint;
				$this->calledConfigs[] = $config;

				$body = ($page === 1) ? $items : [];

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode(['items' => $body]),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-' . $page
				);
			}
		);
	}//end stubCallReturningItems()

	/**
	 * Scenario: an incremental run injects the stored watermark into a
	 * templated endpoint.
	 *
	 * @return void
	 */
	public function testIncrementalRunInjectsWatermarkIntoTemplatedEndpoint(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems([['id' => 'origin-1', 'updatedAt' => '2026-07-10T00:00:00Z']]);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'cursorWatermark' => '2026-07-01T00:00:00Z'],
			['endpoint' => '/items?updatedAfter={{ cursor }}']
		);

		$fetchInfo = null;
		$this->service->getAllObjectsFromApi(synchronization: $synchronization, isTest: false, data: null, fetchInfo: $fetchInfo);

		$this->assertSame('/items?updatedAfter=2026-07-01T00:00:00Z', $this->calledEndpoints[0]);
	}//end testIncrementalRunInjectsWatermarkIntoTemplatedEndpoint()

	/**
	 * Scenario: an incremental run injects the stored watermark into a
	 * templated query parameter.
	 *
	 * @return void
	 */
	public function testIncrementalRunInjectsWatermarkIntoTemplatedQueryParameter(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems([['id' => 'origin-1', 'updatedAt' => '2026-07-10T00:00:00Z']]);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'cursorWatermark' => '42'],
			['endpoint' => '/items', 'query' => ['updatedAfter' => '{{ cursor }}']]
		);

		$fetchInfo = null;
		$this->service->getAllObjectsFromApi(synchronization: $synchronization, isTest: false, data: null, fetchInfo: $fetchInfo);

		$this->assertSame('42', $this->calledConfigs[0]['query']['updatedAfter']);
	}//end testIncrementalRunInjectsWatermarkIntoTemplatedQueryParameter()

	/**
	 * Scenario: an incremental run with no prior watermark passes an empty
	 * cursor.
	 *
	 * @return void
	 */
	public function testIncrementalRunWithNoWatermarkPassesEmptyCursor(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems([['id' => 'origin-1', 'updatedAt' => '2026-07-10T00:00:00Z']]);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental'],
			['endpoint' => '/items?updatedAfter={{ cursor }}']
		);

		$fetchInfo = null;
		$this->service->getAllObjectsFromApi(synchronization: $synchronization, isTest: false, data: null, fetchInfo: $fetchInfo);

		$this->assertSame('/items?updatedAfter=', $this->calledEndpoints[0]);
	}//end testIncrementalRunWithNoWatermarkPassesEmptyCursor()

	/**
	 * Scenario: a full-mode (syncMode absent) run is unaffected — the
	 * `sourceConfig.query` extension is byte-identical to pre-existing
	 * unrendered behavior (regression check).
	 *
	 * @return void
	 */
	public function testFullModeRunLeavesQueryValuesUnrendered(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems([['id' => 'origin-1']]);

		$synchronization = $this->makeSyncPayload(
			[],
			['endpoint' => '/items', 'query' => ['updatedAfter' => '{{ cursor }}']]
		);

		$fetchInfo = null;
		$this->service->getAllObjectsFromApi(synchronization: $synchronization, isTest: false, data: null, fetchInfo: $fetchInfo);

		// No syncMode/cursor context: the literal template string is passed
		// through unrendered, exactly as it was before this change.
		$this->assertSame('{{ cursor }}', $this->calledConfigs[0]['query']['updatedAfter']);
	}//end testFullModeRunLeavesQueryValuesUnrendered()

	/**
	 * Scenario: watermark advances after a complete fetch — the maximum
	 * `cursorField` value across all fetched records is persisted.
	 *
	 * @return void
	 */
	public function testWatermarkAdvancesAfterCompleteFetch(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems(
			[
				['id' => 'origin-1', 'updatedAt' => '2026-07-10T09:00:00Z'],
				['id' => 'origin-2', 'updatedAt' => '2026-07-15T09:00:00Z'],
			]
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$captured = null;
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				$captured = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, self::SYNC_ID);
			}
		);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental'],
			['endpoint' => '/items', 'cursorField' => 'updatedAt']
		);

		$this->service->synchronize(synchronization: $synchronization);

		$this->assertNotNull($captured);
		$this->assertSame('2026-07-15T09:00:00Z', $captured['cursorWatermark']);
	}//end testWatermarkAdvancesAfterCompleteFetch()

	/**
	 * Scenario: watermark does not advance after a page failure mid-fetch —
	 * the next run retries from the same watermark.
	 *
	 * @return void
	 */
	public function testWatermarkDoesNotAdvanceAfterIncompleteFetch(): void {
		$this->stubSourceFind();

		$page = 0;
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$page) {
				$page++;
				if ($page === 1) {
					return ObjectServiceMockBuilder::objectEntity(
						$this,
						[
							'response' => [
								'statusCode' => 200,
								'body' => json_encode(['items' => [['id' => 'origin-1', 'updatedAt' => '2026-07-10T09:00:00Z']]]),
								'encoding' => 'UTF-8',
								'headers' => [],
							],
						],
						'call-log-1'
					);
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					['response' => ['statusCode' => 500, 'body' => 'Internal Server Error', 'encoding' => 'UTF-8', 'headers' => []]],
					'call-log-2'
				);
			}
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$captured = null;
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				$captured = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, self::SYNC_ID);
			}
		);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'cursorWatermark' => '2026-07-01T00:00:00Z'],
			['endpoint' => '/items', 'cursorField' => 'updatedAt', 'usesPagination' => true]
		);

		$this->service->synchronize(synchronization: $synchronization);

		$this->assertNotNull($captured, 'Synchronization must still be persisted (targetLastSynced) even on an incomplete fetch.');
		// The watermark must be left exactly as it was, not advanced and not cleared.
		$this->assertSame('2026-07-01T00:00:00Z', $captured['cursorWatermark']);
	}//end testWatermarkDoesNotAdvanceAfterIncompleteFetch()

	/**
	 * Scenario: watermark does not advance after a 429 rate-limit — the
	 * caller still receives the TooManyRequestsHttpException.
	 *
	 * @return void
	 */
	public function testWatermarkDoesNotAdvanceAfterRateLimit(): void {
		$this->stubSourceFind();

		$this->callService->method('call')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 429], 'call-log-429')
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$captured = null;
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				$captured = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, self::SYNC_ID);
			}
		);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'cursorWatermark' => '2026-07-01T00:00:00Z'],
			['endpoint' => '/items', 'cursorField' => 'updatedAt']
		);

		try {
			$this->service->synchronize(synchronization: $synchronization);
			$this->fail('Expected TooManyRequestsHttpException was not thrown');
		} catch (TooManyRequestsHttpException $exception) {
			$this->assertSame(429, $exception->getStatusCode());
		}

		// A rate-limited run throws before reaching persistSynchronization()
		// at all (synchronizeExternToIntern() re-throws before the
		// `$isTest === false` persist block) — no watermark write happens.
		$this->assertNull($captured);
	}//end testWatermarkDoesNotAdvanceAfterRateLimit()

	/**
	 * Scenario: watermark does not advance for a test run even when the
	 * fetch is complete (REQ-011 parity).
	 *
	 * @return void
	 */
	public function testWatermarkDoesNotAdvanceForTestRun(): void {
		$this->stubSourceFind();
		$this->stubCallReturningItems([['id' => 'origin-1', 'updatedAt' => '2026-07-15T09:00:00Z']]);

		// Seed a pre-existing contract carrying a real uuid so the real
		// (unmocked) synchronizeContract() call — reached via
		// processSynchronizationObject() — has an existing contract id to
		// report back, mirroring the established isTest-run test convention
		// in SynchronizationServiceTestRunNoWriteTest::testTestRunNoWriteOnChangedObject().
		$contract = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'synchronizationId' => self::SYNC_ID,
				'originId' => 'origin-1',
				'originHash' => 'stale-hash-from-previous-run',
				'targetId' => 'target-1',
				'targetHash' => 'stale-target-hash',
			],
			'contract-uuid-1'
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => [$contract], 'total' => 1]);

		$this->orObjectService->expects($this->never())->method('saveObject');

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'cursorWatermark' => '2026-07-01T00:00:00Z'],
			['endpoint' => '/items', 'cursorField' => 'updatedAt']
		);

		$this->service->synchronize(synchronization: $synchronization, isTest: true);
	}//end testWatermarkDoesNotAdvanceForTestRun()

	/**
	 * Scenario: a record missing the configured cursorField throws rather
	 * than silently computing a wrong watermark.
	 *
	 * @return void
	 */
	public function testMissingCursorFieldThrows(): void {
		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental'],
			['cursorField' => 'updatedAt']
		);

		$reflection = new \ReflectionMethod(SynchronizationService::class, 'computeCursorWatermark');
		$reflection->setAccessible(true);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/updatedAt/');

		$reflection->invoke(
			$this->service,
			$synchronization,
			[['id' => 'origin-1', 'updatedAt' => '2026-07-15T09:00:00Z'], ['id' => 'origin-2']]
		);
	}//end testMissingCursorFieldThrows()

	/**
	 * Direct-unit coverage of `computeCursorWatermark()`: the maximum value
	 * across all records wins, not the last-processed record (REQ-002
	 * optimized-parallel-fetch safety).
	 *
	 * @return void
	 */
	public function testComputeCursorWatermarkTakesMaximumAcrossRecords(): void {
		$synchronization = $this->makeSyncPayload([], ['cursorField' => 'updatedAt']);

		$reflection = new \ReflectionMethod(SynchronizationService::class, 'computeCursorWatermark');
		$reflection->setAccessible(true);

		$result = $reflection->invoke(
			$this->service,
			$synchronization,
			[
				['id' => 'origin-1', 'updatedAt' => '2026-07-10T00:00:00Z'],
				['id' => 'origin-2', 'updatedAt' => '2026-07-20T00:00:00Z'],
				['id' => 'origin-3', 'updatedAt' => '2026-07-05T00:00:00Z'],
			]
		);

		$this->assertSame('2026-07-20T00:00:00Z', $result);
	}//end testComputeCursorWatermarkTakesMaximumAcrossRecords()

	/**
	 * Direct-unit coverage: no `cursorField` configured yields a null
	 * watermark (nothing to compute from) rather than throwing.
	 *
	 * @return void
	 */
	public function testComputeCursorWatermarkReturnsNullWithoutCursorField(): void {
		$synchronization = $this->makeSyncPayload();

		$reflection = new \ReflectionMethod(SynchronizationService::class, 'computeCursorWatermark');
		$reflection->setAccessible(true);

		$result = $reflection->invoke($this->service, $synchronization, [['id' => 'origin-1']]);

		$this->assertNull($result);
	}//end testComputeCursorWatermarkReturnsNullWithoutCursorField()

	/**
	 * Integration-style coverage (proposal.md Scope item 5 / TC-17): two
	 * successive incremental runs against a mutable source — run 1 fetches
	 * the full initial dataset and advances the watermark; run 2 (using the
	 * Synchronization array run 1 persisted) requests a cursor-filtered
	 * fetch reflecting run 1's watermark, and only the newer delta records
	 * are returned/processed.
	 *
	 * @return void
	 */
	public function testTwoSuccessiveIncrementalRunsFetchOnlyTheDelta(): void {
		$this->stubSourceFind();

		// Run 1's page returns two records; run 2's page returns a single,
		// newer delta record — a hand-rolled stand-in for "the source only
		// returned what changed since the requested cursor."
		$callCount = 0;
		$this->callService->method('call')->willReturnCallback(
			function ($source, $endpoint, $method, $config) use (&$callCount) {
				$callCount++;
				$this->calledEndpoints[] = $endpoint;
				$this->calledConfigs[] = $config;

				if ($callCount === 1) {
					$items = [
						['id' => 'origin-1', 'updatedAt' => '2026-07-10T00:00:00Z'],
						['id' => 'origin-2', 'updatedAt' => '2026-07-12T00:00:00Z'],
					];
				} else {
					$items = [['id' => 'origin-3', 'updatedAt' => '2026-07-14T00:00:00Z']];
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode(['items' => $items]),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-' . $callCount
				);
			}
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$persisted = null;
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$persisted) {
				// Only the top-level Synchronization save (identified by its
				// own uuid) is tracked here; per-object contract saves reuse
				// this same stub but are irrelevant to the watermark assertion.
				if (($object['uuid'] ?? null) === self::SYNC_ID) {
					$persisted = $object;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $object, ($object['uuid'] ?? 'contract-uuid'));
			}
		);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental'],
			['endpoint' => '/items?updatedAfter={{ cursor }}', 'cursorField' => 'updatedAt']
		);

		// --- Run 1: first-ever incremental pass, empty cursor. ---
		$this->service->synchronize(synchronization: $synchronization);

		$this->assertSame('/items?updatedAfter=', $this->calledEndpoints[0]);
		$this->assertNotNull($persisted);
		$this->assertSame('2026-07-12T00:00:00Z', $persisted['cursorWatermark']);

		// --- Run 2: uses the Synchronization array run 1 persisted. ---
		$result2 = $this->service->synchronize(synchronization: $persisted);

		$this->assertSame('/items?updatedAfter=2026-07-12T00:00:00Z', $this->calledEndpoints[1]);
		// Only the single delta record from the stub's second page was fetched/processed.
		$this->assertSame(1, $result2['result']['objects']['found']);
		$this->assertSame('2026-07-14T00:00:00Z', $persisted['cursorWatermark']);
	}//end testTwoSuccessiveIncrementalRunsFetchOnlyTheDelta()
}//end class
