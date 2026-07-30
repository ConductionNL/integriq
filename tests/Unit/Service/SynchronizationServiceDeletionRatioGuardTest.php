<?php
/**
 * Unit tests for the deletion-ratio guard and `forceDeletion` override on
 * SynchronizationService::deleteInvalidObjects().
 *
 * The guard (spec REQ-010, change sync-safety-guardrails) aborts a bulk
 * source-diff cleanup pass when it would delete more than
 * `sourceConfig.deletionRatioThreshold` (default 10%) of a synchronization's
 * existing contracts, unless the caller supplied the explicit `forceDeletion`
 * override. The `deleteRestriction` single-object path is exempt, and the
 * fetch-completeness gate is unconditional (forceDeletion never bypasses it).
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

use OCA\OpenConnector\Event\SynchronizationDeletionGuardedEvent;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-010's ratio guard, its threshold override, the forceDeletion
 * escape hatch, and the exemptions (deleteRestriction, small contract sets).
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
 */
class SynchronizationServiceDeletionRatioGuardTest extends TestCase
{

    private const SYNC_ID = 'sync-uuid-guard';

    /**
     * @var SynchronizationService&MockObject
     */
    private $service;

    /**
     * @var ORObjectService&MockObject
     */
    private $orObjectService;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var IEventDispatcher&MockObject
     */
    private $eventDispatcher;

    /**
     * Set up test fixtures: a partial mock isolating `updateTarget()`, with a
     * container that resolves the lazily-fetched IEventDispatcher so guard
     * trips can be asserted on the dispatched event.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $callService = $this->createMock(CallService::class);
        $callService->method('applyConfigDot')->willReturnArgument(0);

        $mappingService = $this->createMock(MappingService::class);
        $objectService  = $this->createMock(ObjectService::class);
        $logService     = $this->createMock(SynchronizationLogService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        // The container resolves IEventDispatcher (the new lazy dependency)
        // and returns null for everything else — mirroring both the DI wiring
        // and the bare-container fixtures used elsewhere in the suite.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id) => ($id === IEventDispatcher::class) ? $this->eventDispatcher : null
        );

        $this->service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $callService,
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
            ->onlyMethods(['updateTarget'])
            ->getMock();
    }//end setUp()

    /**
     * Build a synchronization payload with `targetType: register/schema`.
     *
     * @param array $sourceConfig The sourceConfig to attach.
     *
     * @return array
     */
    private function makeSync(array $sourceConfig=[]): array
    {
        return [
            'id'           => self::SYNC_ID,
            'uuid'         => self::SYNC_ID,
            'targetType'   => 'register/schema',
            'targetId'     => '1/2',
            'sourceConfig' => $sourceConfig,
        ];
    }//end makeSync()

    /**
     * Seed N contracts (targetIds target-1..target-N) into findAll() and make
     * the scope-check find() resolve every target in-scope.
     *
     * @param int $count The number of contracts to seed.
     *
     * @return string[] All seeded target ids.
     */
    private function seedContracts(int $count): array
    {
        $contracts = [];
        $targetIds = [];
        for ($i = 1; $i <= $count; $i++) {
            $targetIds[] = 'target-'.$i;
            $contracts[] = ObjectServiceMockBuilder::objectEntity(
                $this,
                ['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-'.$i, 'targetId' => 'target-'.$i],
                'contract-uuid-'.$i
            );
        }

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => $contracts, 'total' => $count]);

        $this->orObjectService->method('find')->willReturnCallback(
            fn (...$args) => ObjectServiceMockBuilder::objectEntity($this, [], (string) $args[0])
        );

        return $targetIds;
    }//end seedContracts()

    /**
     * TC-6: 100 contracts, complete fetch, 15 candidates (15% > default 10%)
     * → 0 deleted, warning logged once, SynchronizationDeletionGuardedEvent
     * dispatched once with the computed ratio/threshold.
     *
     * @return void
     */
    public function testDefaultThresholdAbortsLogsAndDispatches(): void
    {
        $targetIds = $this->seedContracts(100);
        $keptIds   = array_slice($targetIds, 0, 85);

        $this->service->expects($this->never())->method('updateTarget');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('deletion ratio guard tripped'));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with(
                $this->callback(
                    function ($event) {
                        return $event instanceof SynchronizationDeletionGuardedEvent
                            && $event->getReason() === 'ratio_threshold_exceeded'
                            && abs($event->getRatio() - 0.15) < 0.0001
                            && abs($event->getThreshold() - 0.10) < 0.0001
                            && $event->getCandidateCount() === 15
                            && $event->getTotalContracts() === 100
                            && $event->getSynchronizationId() === self::SYNC_ID;
                    }
                )
            );

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(),
            $keptIds,
            false,
            [],
            true,
            false,
            $guardInfo
        );

        $this->assertSame(0, $deleted);
        $this->assertTrue($guardInfo['guarded']);
        $this->assertSame('ratio_threshold_exceeded', $guardInfo['reason']);
        $this->assertSame(15, $guardInfo['candidateCount']);
        $this->assertSame(100, $guardInfo['totalContracts']);
    }//end testDefaultThresholdAbortsLogsAndDispatches()

    /**
     * TC-7: the same over-threshold scenario with `forceDeletion: true`
     * proceeds through the existing per-target deletion loop unchanged.
     *
     * @return void
     */
    public function testForceDeletionOverridesRatioGuard(): void
    {
        $targetIds = $this->seedContracts(100);
        $keptIds   = array_slice($targetIds, 0, 85);

        $this->service->expects($this->exactly(15))
            ->method('updateTarget')
            ->willReturnCallback(
                static fn (array $synchronizationContract, ...$rest) => $synchronizationContract
            );

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(),
            $keptIds,
            false,
            [],
            true,
            true,
            $guardInfo
        );

        $this->assertSame(15, $deleted);
        $this->assertFalse($guardInfo['guarded']);
    }//end testForceDeletionOverridesRatioGuard()

    /**
     * TC-8: `sourceConfig.deletionRatioThreshold: 0.5` with a 30% deletion
     * proceeds without `forceDeletion` (30% < 50%).
     *
     * @return void
     */
    public function testPerSyncThresholdOverride(): void
    {
        $targetIds = $this->seedContracts(10);
        $keptIds   = array_slice($targetIds, 0, 7);

        $this->service->expects($this->exactly(3))
            ->method('updateTarget')
            ->willReturnCallback(
                static fn (array $synchronizationContract, ...$rest) => $synchronizationContract
            );

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(['deletionRatioThreshold' => 0.5]),
            $keptIds,
            false,
            [],
            true,
            false,
            $guardInfo
        );

        $this->assertSame(3, $deleted);
        $this->assertFalse($guardInfo['guarded']);
        $this->assertEqualsWithDelta(0.5, $guardInfo['threshold'], 0.0001);
    }//end testPerSyncThresholdOverride()

    /**
     * TC-9: the `deleteRestriction` (single-object, event-driven) path is
     * exempt from the ratio guard: with 4 contracts and an empty
     * `$synchronizedTargetIds`, ALL 4 are diff candidates (100%, far over any
     * threshold), yet the one object named in `$data` is still deleted.
     *
     * @return void
     */
    public function testDeleteRestrictionExemptFromRatioGuard(): void
    {
        $this->seedContracts(4);

        $this->service->expects($this->once())
            ->method('updateTarget')
            ->willReturnCallback(
                static fn (array $synchronizationContract, ...$rest) => $synchronizationContract
            );

        $this->logger->expects($this->never())->method('warning');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(['restrictDeletion' => true]),
            [],
            true,
            ['originId' => 'origin-2'],
            true,
            false,
            $guardInfo
        );

        $this->assertSame(1, $deleted, 'The explicitly-named object is deleted despite a 100% diff ratio');
        $this->assertFalse($guardInfo['guarded']);
    }//end testDeleteRestrictionExemptFromRatioGuard()

    /**
     * TC-10: zero existing contracts (first-ever sync) — no division by zero,
     * no spurious guard trip, returns 0.
     *
     * @return void
     */
    public function testZeroExistingContractsNoDivisionByZero(): void
    {
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->logger->expects($this->never())->method('warning');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');
        $this->service->expects($this->never())->method('updateTarget');

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(),
            [],
            false,
            [],
            true,
            false,
            $guardInfo
        );

        $this->assertSame(0, $deleted);
        $this->assertFalse($guardInfo['guarded']);
        $this->assertNull($guardInfo['ratio']);
    }//end testZeroExistingContractsNoDivisionByZero()

    /**
     * The fetch-completeness gate is unconditional: `forceDeletion: true`
     * does NOT bypass `fetchComplete: false` (design.md Decision 2 — a known
     * incomplete fetch is never a safe basis for deletion).
     *
     * @return void
     */
    public function testForceDeletionDoesNotBypassFetchIncompleteGate(): void
    {
        $this->seedContracts(10);

        $this->service->expects($this->never())->method('updateTarget');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with(
                $this->callback(
                    static fn ($event) => $event instanceof SynchronizationDeletionGuardedEvent
                        && $event->getReason() === 'fetch_incomplete'
                )
            );

        $guardInfo = null;
        $deleted   = $this->service->deleteInvalidObjects(
            $this->makeSync(),
            [],
            false,
            [],
            false,
            true,
            $guardInfo
        );

        $this->assertSame(0, $deleted);
        $this->assertSame('fetch_incomplete', $guardInfo['reason']);
    }//end testForceDeletionDoesNotBypassFetchIncompleteGate()
}//end class
