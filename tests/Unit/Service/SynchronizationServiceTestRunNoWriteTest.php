<?php
/**
 * Unit tests for the absolute no-write guarantee of test (dry) runs.
 *
 * Reproduces ConductionNL/openconnector#1008/#1017: before the
 * sync-safety-guardrails change, a "Test (dry run)" click ran the
 * `deleteInvalidObjects()` cleanup unconditionally — diffing the test fetch's
 * single sampled object against every existing contract and deleting real
 * objects — and unconditionally persisted the Synchronization entity's
 * `targetLastSynced`. A test run must never write anything: no contract, no
 * target object, no Source, no Synchronization mutation (spec REQ-011).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-011: a run with `isTest: true` makes no writes whatsoever.
 *
 * The main `orObjectService` mock is shared by every persistence path the
 * engine has (contracts, targets, sources, the synchronization itself); the
 * run-log service writes through its own separate OR mock, so asserting
 * `saveObject` is never called on the main mock proves the no-write guarantee
 * without forbidding the (intentional, audit-grade) run-log row.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011
 */
class SynchronizationServiceTestRunNoWriteTest extends TestCase
{

    private const SYNC_ID = 'sync-uuid-testrun';

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
     * Set up shared mocks (the service itself is built per-test so each test
     * can pick which methods to isolate).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->callService     = $this->createMock(CallService::class);
        $this->callService->method('applyConfigDot')->willReturnArgument(0);
    }//end setUp()

    /**
     * Build the service as a partial mock isolating the given methods.
     *
     * @param string[] $onlyMethods The methods to replace with mocks.
     *
     * @return SynchronizationService&MockObject
     */
    private function makeService(array $onlyMethods): MockObject
    {
        $mappingService = $this->createMock(MappingService::class);
        $container      = $this->createMock(ContainerInterface::class);
        $objectService  = $this->createMock(ObjectService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $logOrService = ObjectServiceMockBuilder::make($this);
        $userSession  = $this->createMock(\OCP\IUserSession::class);
        $session      = $this->createMock(\OCP\ISession::class);
        $logService   = new SynchronizationLogService($logOrService, $userSession, $session);

        return $this->getMockBuilder(SynchronizationService::class)
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
                    $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
                ]
            )
            ->onlyMethods($onlyMethods)
            ->getMock();
    }//end makeService()

    /**
     * Synchronization payload with an api source and register/schema target.
     *
     * @return array
     */
    private function makeSyncPayload(): array
    {
        return [
            'id'           => self::SYNC_ID,
            'uuid'         => self::SYNC_ID,
            'sourceId'     => 'source-uuid-tr',
            'sourceType'   => 'api',
            'targetType'   => 'register/schema',
            'targetId'     => '1/2',
            'sourceConfig' => [
                'endpoint'        => '/items',
                'resultsPosition' => 'items',
                'usesPagination'  => true,
            ],
        ];
    }//end makeSyncPayload()

    /**
     * Stub the source lookup and a single-page 200 response with one object.
     *
     * @return void
     */
    private function stubSourceAndOnePage(): void
    {
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['location' => 'https://example.test', 'enabled' => true],
            'source-uuid-tr'
        );
        $this->orObjectService->method('find')->willReturn($sourceEntity);

        $callLog = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'response' => [
                    'statusCode' => 200,
                    'body'       => json_encode(['items' => [['id' => 'origin-1', 'name' => 'Object One']]]),
                    'encoding'   => 'UTF-8',
                    'headers'    => [],
                ],
            ],
            'call-log-tr'
        );
        $this->callService->method('call')->willReturn($callLog);
    }//end stubSourceAndOnePage()

    /**
     * TC-11 (#1008, the most severe pre-existing bug): a test run against a
     * synchronization with 50 previously-synced contracts never invokes
     * `deleteInvalidObjects()` and reports 0 deletions.
     *
     * @return void
     */
    public function testTestRunDeletesNothing(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);
        $this->stubSourceAndOnePage();

        // 50 pre-existing contracts — before this change, the test-mode fetch
        // (truncated to 1 object) diffed against these and deleted 49.
        $contracts = [];
        for ($i = 1; $i <= 50; $i++) {
            $contracts[] = ObjectServiceMockBuilder::objectEntity(
                $this,
                ['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-'.$i, 'targetId' => 'target-'.$i],
                'contract-uuid-'.$i
            );
        }

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => $contracts, 'total' => 50]);

        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => [],
                'contract'     => ['uuid' => 'contract-uuid-1', 'targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        $service->expects($this->never())->method('deleteInvalidObjects');
        $this->orObjectService->expects($this->never())->method('deleteObject');

        $result = $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);

        $this->assertSame(0, $result['result']['objects']['deleted']);
    }//end testTestRunDeletesNothing()

    /**
     * TC-12: a test run never persists the synchronization's own state — no
     * `saveObject` reaches OR for `targetLastSynced` (or anything else).
     *
     * @return void
     */
    public function testTestRunDoesNotPersistSynchronizationState(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);
        $this->stubSourceAndOnePage();

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => [],
                'contract'     => ['uuid' => 'contract-uuid-1', 'targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        // NOTHING may be written through the engine's OR service in test mode:
        // not the synchronization (targetLastSynced/currentPage), not a
        // contract, not a target, not a source.
        $this->orObjectService->expects($this->never())->method('saveObject');

        $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);
    }//end testTestRunDoesNotPersistSynchronizationState()

    /**
     * TC-13: a test run against a CHANGED object (source hash differs from
     * the existing contract's originHash) exercises the real
     * `synchronizeContract()` and still writes nothing — its pre-existing
     * `isTest` early-return happens before `updateTarget()`/`persistContract()`
     * (non-regression control per design.md Decision 4).
     *
     * @return void
     */
    public function testTestRunNoWriteOnChangedObject(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'updateTarget']);
        $this->stubSourceAndOnePage();

        // An existing contract whose originHash is stale — the object changed.
        $contract = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'synchronizationId' => self::SYNC_ID,
                'originId'          => 'origin-1',
                'originHash'        => 'stale-hash-from-previous-run',
                'targetId'          => 'target-1',
                'targetHash'        => 'stale-target-hash',
            ],
            'contract-uuid-1'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$contract], 'total' => 1]);

        $service->expects($this->never())->method('updateTarget');
        $this->orObjectService->expects($this->never())->method('saveObject');

        $result = $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);

        // The transformed result is still reported to the caller (skipped, not lost).
        $this->assertSame(1, $result['result']['objects']['found']);
        $this->assertSame(1, $result['result']['objects']['skipped']);
    }//end testTestRunNoWriteOnChangedObject()

    /**
     * TC (Task 13, ad-hoc overlap): a test run with an ad-hoc `source`
     * location matching no configured Source never persists a Source object
     * — exercised specifically through the `isTest` path here (Task 8/14
     * cover the non-test path).
     *
     * @return void
     */
    public function testTestRunWithAdHocSourceDoesNotPersistSource(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);

        // The location lookup finds no configured Source.
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $callLog = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'response' => [
                    'statusCode' => 200,
                    'body'       => json_encode(['items' => [['id' => 'origin-1']]]),
                    'encoding'   => 'UTF-8',
                    'headers'    => [],
                ],
            ],
            'call-log-adhoc'
        );
        $this->callService->method('call')->willReturn($callLog);

        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => [],
                'contract'     => ['uuid' => 'contract-uuid-1', 'targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        $this->orObjectService->expects($this->never())->method('saveObject');

        $result = $service->synchronize(
            synchronization: $this->makeSyncPayload(),
            isTest: true,
            source: 'https://example.test/ad-hoc-feed'
        );

        $this->assertSame(0, $result['result']['objects']['deleted']);
    }//end testTestRunWithAdHocSourceDoesNotPersistSource()
}//end class
