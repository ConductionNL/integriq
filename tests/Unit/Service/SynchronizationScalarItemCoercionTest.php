<?php

/**
 * Unit tests for the bare-scalar source item coercion guard in
 * SynchronizationService::synchronizeExternToIntern()'s per-item loop
 * (change sync-engine-scalar-items, closes #1050).
 *
 * Before this change, `getOriginId()` and `processSynchronizationObject()`
 * are `array`-typed; PHP does not coerce a scalar across a strict type
 * hint, so a source returning bare scalars (e.g.
 * `https://endoflife.date/api/all.json` returning `["php","nodejs",...]`)
 * threw a `TypeError` at the call boundary before either method body ran,
 * and every scalar item dead-lettered with an opaque low-level type
 * message. These tests pin: a pure-scalar source list is coerced and
 * synced rather than dead-lettered; a mixed scalar+object source list
 * syncs both; and a misconfigured scalar source (default `idPosition`)
 * fails with the pre-existing, actionable `getOriginId()` exception rather
 * than a `TypeError`.
 *
 * Follows the existing SynchronizationItemIsolationTest technique: drive
 * the real per-item loop + getOriginId() + conditions logic via a partial
 * mock, but additionally stub the public `synchronizeContract()` boundary
 * so a "synced" (created) outcome can be asserted without mocking the full
 * mapping/target-write pipeline, which is out of scope for this change.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SyncItemDeadLetterService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationRunLog;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
 */
class SynchronizationScalarItemCoercionTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var SynchronizationLogService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationLogService;

    /**
     * @var SyncItemDeadLetterService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncItemDeadLetterService;


    /**
     * Build a partial SynchronizationService mock whose
     * `getAllObjectsFromSource()` returns the given object list and whose
     * `synchronizeContract()` always reports a successful create — every
     * other method (including the per-item loop's coercion guard,
     * conditions evaluation, and `getOriginId()`) runs for real.
     *
     * @param array $objectList The objects `getAllObjectsFromSource()` should return.
     *
     * @return SynchronizationService
     */
    private function buildServiceReturningObjectsWithStubbedContractCreate(array $objectList): SynchronizationService
    {
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $this->callService = $this->createMock(CallService::class);
        $this->callService->method('applyConfigDot')->willReturnArgument(0);

        $this->synchronizationLogService = $this->createMock(SynchronizationLogService::class);
        $this->synchronizationLogService->method('createFromArray')->willReturnCallback(
            static fn(array $object) => (new SynchronizationRunLog())->hydrate($object)
        );
        $this->synchronizationLogService->method('update')->willReturnCallback(
            static fn(SynchronizationRunLog $log) => $log
        );

        $this->syncItemDeadLetterService = $this->createMock(SyncItemDeadLetterService::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === SyncItemDeadLetterService::class) {
                    return $this->syncItemDeadLetterService;
                }

                return null;
            }
        );

        $objectService = $this->createMock(ObjectService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $this->callService,
                    $this->createMock(MappingService::class),
                    $container,
                    $this->orObjectService,
                    $objectService,
                    $this->createMock(LoggerInterface::class),
                    $this->synchronizationLogService,
                    $appConfig,
                    $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
                ]
            )
            ->onlyMethods(['getAllObjectsFromSource', 'synchronizeContract'])
            ->getMock();

        $service->method('getAllObjectsFromSource')->willReturn($objectList);
        $service->method('synchronizeContract')->willReturn(
            [
                'contract'     => ['uuid' => 'contract-uuid', 'targetId' => 'target-uuid'],
                'log'          => ['uuid' => 'contract-log-uuid'],
                'resultAction' => 'create',
            ]
        );

        return $service;
    }//end buildServiceReturningObjectsWithStubbedContractCreate()


    /**
     * Regression for #1050 — a source returning a bare scalar item (the
     * `https://endoflife.date/api/all.json` shape: a JSON array of plain
     * strings) is coerced to `['value' => 'php']` by the per-item loop and
     * flows all the way through to a created contract, instead of throwing
     * a `TypeError` at the `array`-typed `getOriginId()`/
     * `processSynchronizationObject()` boundary and dead-lettering.
     *
     * @return void
     */
    public function testScalarSourceItemIsCoercedAndSynced(): void
    {
        $objectList = ['php'];

        $service = $this->buildServiceReturningObjectsWithStubbedContractCreate($objectList);

        // The coercion must make the item succeed — no dead-letter capture
        // at all for this run.
        $this->syncItemDeadLetterService->expects($this->never())->method('recordFailure');

        $synchronization = [
            'uuid'          => 'sync-scalar-1',
            'id'            => 'sync-scalar-1',
            'name'          => 'scalar-coercion-test',
            'sourceId'      => 'source-1',
            'sourceConfig'  => ['idPosition' => 'value'],
        ];

        $result = $service->synchronize(synchronization: $synchronization);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['result']['objects']['found']);
        $this->assertSame(1, $result['result']['objects']['created']);
        $this->assertSame(0, $result['result']['objects']['invalid']);
    }//end testScalarSourceItemIsCoercedAndSynced()


    /**
     * A source list mixing bare-scalar items with already-array-shaped
     * items (both exposing a `value` key so a single `idPosition: 'value'`
     * config resolves identity for both shapes) syncs both — the
     * coercion guard only touches non-array items and leaves array items
     * completely unchanged.
     *
     * @return void
     */
    public function testMixedScalarAndObjectSourceSyncsBoth(): void
    {
        $objectList = [
            'php',
            ['value' => 'nodejs', 'meta' => ['category' => 'runtime']],
        ];

        $service = $this->buildServiceReturningObjectsWithStubbedContractCreate($objectList);

        $this->syncItemDeadLetterService->expects($this->never())->method('recordFailure');

        $synchronization = [
            'uuid'          => 'sync-scalar-mixed',
            'id'            => 'sync-scalar-mixed',
            'name'          => 'scalar-mixed-coercion-test',
            'sourceId'      => 'source-1',
            'sourceConfig'  => ['idPosition' => 'value'],
        ];

        $result = $service->synchronize(synchronization: $synchronization);

        $this->assertIsArray($result);
        $this->assertSame(2, $result['result']['objects']['found']);
        $this->assertSame(2, $result['result']['objects']['created']);
        $this->assertSame(0, $result['result']['objects']['invalid']);
    }//end testMixedScalarAndObjectSourceSyncsBoth()


    /**
     * A scalar-sourced synchronization that has NOT set
     * `sourceConfig.idPosition` to `'value'` (the documented contract) is
     * still coerced successfully (no `TypeError`) but fails at
     * `getOriginId()`'s default `idPosition` ('id') lookup, which does not
     * exist on the coerced `['value' => ...]` shape. This must dead-letter
     * with the pre-existing, actionable
     * "Could not find origin id in object for key: id" message — never
     * with a raw `TypeError`/"must be of type array" message, which is the
     * failure mode #1050 eliminates.
     *
     * @return void
     */
    public function testMisconfiguredScalarSourceFailsWithActionableOriginIdErrorNotTypeError(): void
    {
        $objectList = ['php'];

        $service = $this->buildServiceReturningObjectsWithStubbedContractCreate($objectList);

        $this->syncItemDeadLetterService->expects($this->once())
            ->method('recordFailure')
            ->with(
                $this->anything(),
                $this->callback(static fn($payload) => $payload === ['value' => 'php']),
                $this->callback(
                    static function ($errorMessage) {
                        return str_contains($errorMessage, 'Could not find origin id') === true
                            && str_contains($errorMessage, 'must be of type array') === false;
                    }
                ),
                $this->isNull()
            );

        $synchronization = [
            'uuid'     => 'sync-scalar-misconfigured',
            'id'       => 'sync-scalar-misconfigured',
            'name'     => 'scalar-misconfigured-test',
            'sourceId' => 'source-1',
            // No sourceConfig.idPosition override — defaults to 'id', which
            // does not exist on the coerced ['value' => 'php'] shape.
        ];

        $result = $service->synchronize(synchronization: $synchronization);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['result']['objects']['found']);
        $this->assertSame(1, $result['result']['objects']['invalid']);
        $this->assertSame(0, $result['result']['objects']['created']);
    }//end testMisconfiguredScalarSourceFailsWithActionableOriginIdErrorNotTypeError()


    /**
     * Regression: an existing, array-shaped source item's identity
     * resolution (default `idPosition: 'id'`) and successful-create path
     * are completely unaffected by the coercion guard — the guard's
     * `is_array($object) === false` condition is never true for this item,
     * so it is passed through untouched.
     *
     * @return void
     */
    public function testExistingArrayShapedSourceIdentityBehaviourIsUnchanged(): void
    {
        $objectList = [['id' => 'obj-1', 'name' => 'Existing Object']];

        $service = $this->buildServiceReturningObjectsWithStubbedContractCreate($objectList);

        $this->syncItemDeadLetterService->expects($this->never())->method('recordFailure');

        $synchronization = [
            'uuid'     => 'sync-array-unchanged',
            'id'       => 'sync-array-unchanged',
            'name'     => 'array-unchanged-test',
            'sourceId' => 'source-1',
        ];

        $result = $service->synchronize(synchronization: $synchronization);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['result']['objects']['found']);
        $this->assertSame(1, $result['result']['objects']['created']);
        $this->assertSame(0, $result['result']['objects']['invalid']);
    }//end testExistingArrayShapedSourceIdentityBehaviourIsUnchanged()

}//end class
