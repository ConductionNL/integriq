<?php

/**
 * Unit tests for fetch-completeness tracking and the fetch-incomplete deletion gate.
 *
 * Reproduces the ConductionNL/integriq#1000/#1001/#1002 mass-deletion
 * scenarios: a source erroring mid-fetch, failing partway through pagination,
 * or rate-limiting (HTTP 429) must never cause `deleteInvalidObjects()` to
 * garbage-collect the objects the failed fetch did not see (spec REQ-009 +
 * REQ-010, change sync-safety-guardrails).
 *
 * Note on the "#1002 deleteInvalidObjects never invoked" phrasing in the test
 * plan: per design.md Decision 2 the fetch-completeness guard lives INSIDE
 * `deleteInvalidObjects()` (single source of truth), so the method IS called
 * with `fetchComplete: false` and returns 0 before any deletion work. These
 * tests therefore assert via the internal spy alternative the test plan
 * allows: `updateTarget()` (the only path to an actual delete) is never
 * invoked, the reported deleted count is 0, and the run's `deletionGuard`
 * result records the fetch_incomplete reason.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Tests REQ-009 (fetch-completeness tracking) and its REQ-010 deletion gate.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
 */
class SynchronizationServiceFetchCompletenessTest extends TestCase {

	private const SYNC_ID = 'sync-uuid-fc';

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
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Set up test fixtures: a partial mock isolating `updateTarget()` (the
	 * only path to an actual target delete/write) so the real fetch, cleanup
	 * and guard logic run against stubbed CallService/OR responses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		$mappingService = $this->createMock(MappingService::class);
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		// The run-log write path uses a real SynchronizationLogService against
		// its own OR mock so log persistence never interferes with the main
		// orObjectService expectations below.
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
					$this->logger,
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
	 * @param array $sourceConfigExtra Extra sourceConfig keys to merge in.
	 *
	 * @return array
	 */
	private function makeSyncPayload(array $sourceConfigExtra = []): array {
		return [
			'id' => self::SYNC_ID,
			'uuid' => self::SYNC_ID,
			'sourceId' => 'source-uuid-fc',
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => array_merge(
				[
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => true,
				],
				$sourceConfigExtra
			),
		];
	}//end makeSyncPayload()

	/**
	 * Stub `orObjectService::find()` to return a source entity (used by both
	 * findSource() and callSourceObject()'s source resolution).
	 *
	 * @return void
	 */
	private function stubSourceFind(): void {
		$sourceEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['location' => 'https://example.test', 'enabled' => true],
			'source-uuid-fc'
		);
		$this->orObjectService->method('find')->willReturn($sourceEntity);
	}//end stubSourceFind()

	/**
	 * Build a call-log entity for one page response.
	 *
	 * @param array|null $response The `response` sub-array, or null for a
	 *                             response-less log (429/network failure).
	 * @param int|null $status Top-level statusCode when $response is null.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function callLogEntity(?array $response, ?int $status = null): \OCA\OpenRegister\Db\ObjectEntity {
		$body = [];
		if ($response !== null) {
			$body['response'] = $response;
		}

		if ($status !== null) {
			$body['statusCode'] = $status;
		}

		return ObjectServiceMockBuilder::objectEntity($this, $body, 'call-log-' . md5(json_encode($body)));
	}//end callLogEntity()

	/**
	 * TC reproducing #1000: HTTP 500 on the first (only) page of a run against
	 * a synchronization with existing contracts → 0 objects deleted, guard
	 * records `fetch_incomplete`, no target delete is ever attempted.
	 *
	 * @return void
	 */
	public function testHttp500OnFirstPageBlocksDeletion(): void {
		$this->stubSourceFind();

		$this->callService->method('call')->willReturn(
			$this->callLogEntity(['statusCode' => 500, 'body' => 'Internal Server Error', 'encoding' => 'UTF-8', 'headers' => []])
		);

		// 20 pre-existing contracts would all be deletion candidates if the
		// failed (empty) fetch were diffed against them.
		$contracts = [];
		for ($i = 1; $i <= 20; $i++) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-' . $i, 'targetId' => 'target-' . $i],
				'contract-uuid-' . $i
			);
		}

		$this->orObjectService->method('findAll')
			->willReturn(['results' => $contracts, 'total' => count($contracts)]);

		// The only path to an actual delete must never be reached.
		$this->service->expects($this->never())->method('updateTarget');

		$result = $this->service->synchronize(synchronization: $this->makeSyncPayload());

		$this->assertSame(0, $result['result']['objects']['deleted']);
		$this->assertTrue($result['result']['objects']['deletionGuard']['guarded']);
		$this->assertSame('fetch_incomplete', $result['result']['objects']['deletionGuard']['reason']);
	}//end testHttp500OnFirstPageBlocksDeletion()

	/**
	 * TC reproducing #1001: 2 valid pages then a non-2xx on page 3 → the
	 * page-1/2 objects are still returned, the fetch is marked incomplete, and
	 * a cleanup pass fed `fetchComplete: false` deletes nothing even though
	 * `$synchronizedTargetIds` only covers pages 1-2.
	 *
	 * @return void
	 */
	public function testPartialPaginationMarksIncompleteAndBlocksDeletion(): void {
		$this->stubSourceFind();

		$page = 0;
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$page) {
				$page++;
				if ($page <= 2) {
					return $this->callLogEntity(
						[
							'statusCode' => 200,
							'body' => json_encode(['items' => [['id' => 'origin-' . (($page * 2) - 1)], ['id' => 'origin-' . ($page * 2)]]]),
							'encoding' => 'UTF-8',
							'headers' => [],
						]
					);
				}

				return $this->callLogEntity(['statusCode' => 502, 'body' => 'Bad Gateway', 'encoding' => 'UTF-8', 'headers' => []]);
			}
		);

		$fetchInfo = null;
		$objects = $this->service->getAllObjectsFromApi(
			synchronization: $this->makeSyncPayload(),
			isTest: false,
			data: null,
			fetchInfo: $fetchInfo
		);

		// Partial results are preserved, not discarded (REQ-009 scenario 1).
		$this->assertCount(4, $objects);
		$this->assertFalse($fetchInfo['complete']);
		$this->assertSame('page_fetch_failed', $fetchInfo['failureReason']);

		// Contracts from a previous, fully-successful run: 6 total, only 4
		// seen this run — the diff would delete 2 if the gate were missing.
		$contracts = [];
		for ($i = 1; $i <= 6; $i++) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-' . $i, 'targetId' => 'target-' . $i],
				'contract-uuid-' . $i
			);
		}

		$this->orObjectService->method('findAll')
			->willReturn(['results' => $contracts, 'total' => count($contracts)]);

		$this->service->expects($this->never())->method('updateTarget');

		$guardInfo = null;
		$deleted = $this->service->deleteInvalidObjects(
			$this->makeSyncPayload(),
			['target-1', 'target-2', 'target-3', 'target-4'],
			false,
			[],
			false,
			false,
			$guardInfo
		);

		$this->assertSame(0, $deleted);
		$this->assertSame('fetch_incomplete', $guardInfo['reason']);
	}//end testPartialPaginationMarksIncompleteAndBlocksDeletion()

	/**
	 * TC reproducing #1002: HTTP 429 on page 1 → the TooManyRequestsHttpException
	 * still surfaces to the caller with rate-limit headers intact, and no
	 * deletion work happens (`updateTarget()` never reached).
	 *
	 * @return void
	 */
	public function testRateLimit429BlocksDeletionAndStillThrows(): void {
		$this->stubSourceFind();

		// A 429 call log: no `response` payload, top-level statusCode 429 —
		// the exact shape fetchSinglePageData()'s rate-limit check reads.
		$this->callService->method('call')->willReturn($this->callLogEntity(null, 429));

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		$this->service->expects($this->never())->method('updateTarget');

		try {
			$this->service->synchronize(synchronization: $this->makeSyncPayload());
			$this->fail('Expected TooManyRequestsHttpException was not thrown');
		} catch (TooManyRequestsHttpException $exception) {
			$this->assertSame(429, $exception->getStatusCode());
			$this->assertIsArray($exception->getHeaders());
		}
	}//end testRateLimit429BlocksDeletionAndStillThrows()

	/**
	 * TC: pagination exhausting DEFAULT_MAX_PAGES (50) while a next page was
	 * still available → exactly 50 page fetches occur (cap unchanged) and the
	 * fetch is marked incomplete with `failureReason: max_pages_reached`.
	 *
	 * @return void
	 */
	public function testMaxPagesCapMarksIncomplete(): void {
		$this->stubSourceFind();

		$calls = 0;
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$calls) {
				$calls++;

				return $this->callLogEntity(
					[
						'statusCode' => 200,
						'body' => json_encode(['items' => [['id' => 'origin-' . $calls]]]),
						'encoding' => 'UTF-8',
						'headers' => [],
					]
				);
			}
		);

		$fetchInfo = null;
		$objects = $this->service->getAllObjectsFromApi(
			synchronization: $this->makeSyncPayload(),
			isTest: false,
			data: null,
			fetchInfo: $fetchInfo
		);

		$this->assertSame(SynchronizationService::DEFAULT_MAX_PAGES, $calls, 'The safety cap itself is unchanged');
		$this->assertCount(SynchronizationService::DEFAULT_MAX_PAGES, $objects);
		$this->assertFalse($fetchInfo['complete']);
		$this->assertSame('max_pages_reached', $fetchInfo['failureReason']);
		$this->assertSame(SynchronizationService::DEFAULT_MAX_PAGES, $fetchInfo['pagesFetched']);
	}//end testMaxPagesCapMarksIncomplete()

	/**
	 * TC (natural end of pagination, TC-2): the final page returning zero
	 * objects with HTTP 200 is marked complete — indistinguishable from the
	 * pre-existing correct behaviour for a genuinely-empty final page.
	 *
	 * @return void
	 */
	public function testNaturalEndOfPaginationIsComplete(): void {
		$this->stubSourceFind();

		$page = 0;
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$page) {
				$page++;
				$items = [];
				if ($page <= 2) {
					$items = [['id' => 'origin-' . $page]];
				}

				return $this->callLogEntity(
					[
						'statusCode' => 200,
						'body' => json_encode(['items' => $items]),
						'encoding' => 'UTF-8',
						'headers' => [],
					]
				);
			}
		);

		$fetchInfo = null;
		$objects = $this->service->getAllObjectsFromApi(
			synchronization: $this->makeSyncPayload(),
			isTest: false,
			data: null,
			fetchInfo: $fetchInfo
		);

		$this->assertCount(2, $objects);
		$this->assertTrue($fetchInfo['complete']);
		$this->assertNull($fetchInfo['failureReason']);
	}//end testNaturalEndOfPaginationIsComplete()

	/**
	 * Non-regression control: a fully successful, complete fetch with a
	 * within-threshold deletion count still deletes exactly as before.
	 *
	 * @return void
	 */
	public function testCompleteFetchWithinThresholdStillDeletes(): void {
		// 20 contracts, 19 still present in the source, 1 orphan (5% < 10%).
		$contracts = [];
		$keptIds = [];
		for ($i = 1; $i <= 20; $i++) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-' . $i, 'targetId' => 'target-' . $i],
				'contract-uuid-' . $i
			);
			if ($i < 20) {
				$keptIds[] = 'target-' . $i;
			}
		}

		$this->orObjectService->method('findAll')
			->willReturn(['results' => $contracts, 'total' => count($contracts)]);

		// Scope-check lookup for the orphan target resolves in-scope.
		$this->orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, [], 'target-20')
		);

		$this->service->expects($this->once())
			->method('updateTarget')
			->willReturn(['synchronizationId' => self::SYNC_ID, 'targetId' => 'target-20']);

		$guardInfo = null;
		$deleted = $this->service->deleteInvalidObjects(
			$this->makeSyncPayload(),
			$keptIds,
			false,
			[],
			true,
			false,
			$guardInfo
		);

		$this->assertSame(1, $deleted);
		$this->assertFalse($guardInfo['guarded']);
	}//end testCompleteFetchWithinThresholdStillDeletes()
}//end class
