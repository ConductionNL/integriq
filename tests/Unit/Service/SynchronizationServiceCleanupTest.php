<?php
/**
 * Unit tests for SynchronizationService::deleteInvalidObjects scope-check guard.
 *
 * Ported from openconnector PR #733 (author @rjzondervan), adapted to the
 * post-cutover OR-object-based contract layer. The original test mocked the
 * deleted SynchronizationContractMapper; here we mock the OR ObjectService
 * which the cleanup path uses to look up contract and target objects.
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
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the scope-checked cleanup behaviour in deleteInvalidObjects.
 *
 * The cleanup pass must verify each candidate target lives in the sync's
 * register/schema before invoking the scoped `objectService->deleteObject($uuid, $register, $schema)`
 * inside `updateTargetOpenRegister`. The scoped API (OR#1638 / hydra#309) prevents a UUID
 * collision across magic tables from silently deleting a foreign-scope object; this
 * pre-flight guard provides defence in depth and avoids unnecessary OR calls + audit noise
 * when a contract's `targetId` UUID accidentally collides with an object in a foreign
 * register/schema.
 */
class SynchronizationServiceCleanupTest extends TestCase
{

    private const SYNC_UUID           = 'sync-uuid-1';
    private const REGISTER_ID         = '1';
    private const SCHEMA_ID           = '2';
    private const TARGET_IN_SCOPE     = 'object-in-scope-uuid';
    private const TARGET_OUT_OF_SCOPE = 'object-out-of-scope-uuid';

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $callService               = $this->createMock(CallService::class);
        $mappingService            = $this->createMock(MappingService::class);
        $container                 = $this->createMock(ContainerInterface::class);
        $objectService             = $this->createMock(ObjectService::class);
        $storageService            = $this->createMock(StorageService::class);
        $synchronizationLogService = $this->createMock(SynchronizationLogService::class);
        $appConfig                 = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        // Partial-mock `updateTarget` so we can assert whether the delete path
        // is reached without exercising the deep OR delete chain underneath.
        $this->service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $callService,
                    $mappingService,
                    $container,
                    $this->orObjectService,
                    $objectService,
                    $storageService,
                    $this->logger,
                    $synchronizationLogService,
                    $appConfig,
                ]
            )
            ->onlyMethods(['updateTarget'])
            ->getMock();
    }//end setUp()

    /**
     * Build a Synchronization OR-object stub with `targetType: register/schema`
     * and `targetId: {registerId}/{schemaId}`.
     *
     * @return ObjectEntity
     */
    private function makeSync(): ObjectEntity
    {
        return ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'targetType' => 'register/schema',
                'targetId'   => self::REGISTER_ID.'/'.self::SCHEMA_ID,
            ],
            self::SYNC_UUID
        );
    }//end makeSync()

    /**
     * Build a SynchronizationContract OR-object stub bound to this sync.
     *
     * @param string $targetId The targetId UUID on the contract.
     * @param string $originId The originId on the contract.
     *
     * @return ObjectEntity
     */
    private function makeContract(string $targetId, string $originId='origin-x'): ObjectEntity
    {
        return ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'synchronizationId' => self::SYNC_UUID,
                'targetId'          => $targetId,
                'originId'          => $originId,
            ],
            'contract-uuid-'.substr(md5($targetId), 0, 8)
        );
    }//end makeContract()

    /**
     * Map ORObjectService::find parameter names to positional indices.
     *
     * Production calls find() with named arguments; PHPUnit's stub receives
     * positional arguments after the runtime expansion. Using reflection here
     * keeps assertions robust against signature changes.
     *
     * @return array<string, int>
     */
    private function findParamPositions(): array
    {
        $rm        = new ReflectionMethod(ORObjectService::class, 'find');
        $positions = [];
        foreach ($rm->getParameters() as $i => $p) {
            $positions[$p->getName()] = $i;
        }

        return $positions;
    }//end findParamPositions()

    /**
     * In-scope orphan: target object lives in this sync's register/schema → delete proceeds.
     *
     * @return void
     */
    public function testInScopeOrphanIsDeleted(): void
    {
        $sync         = $this->makeSync();
        $contract     = $this->makeContract(self::TARGET_IN_SCOPE);
        $pos          = $this->findParamPositions();
        $targetEntity = ObjectServiceMockBuilder::objectEntity($this, [], self::TARGET_IN_SCOPE);

        // First findAll: enumerate contracts for the sync. Second findAll: re-lookup by targetId.
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$contract], 'total' => 1]);

        // find() is the scope-check call — assert it carries the correct scope flags.
        $this->orObjectService->expects($this->once())
            ->method('find')
            ->willReturnCallback(
                function (...$args) use ($pos, $targetEntity) {
                    $this->assertSame(self::TARGET_IN_SCOPE, $args[$pos['id']]);
                    $this->assertSame(self::REGISTER_ID, $args[$pos['register']]);
                    $this->assertSame(self::SCHEMA_ID, $args[$pos['schema']]);
                    $this->assertFalse($args[$pos['_rbac']], '_rbac must be false to match prior unscoped-JOIN behaviour');
                    $this->assertFalse($args[$pos['_multitenancy']], '_multitenancy must be false to match prior unscoped-JOIN behaviour');
                    return $targetEntity;
                }
            );

        // updateTarget returns the typed contract value object the engine then
        // persists; hydrate one from the contract OR-object stub.
        $this->service->expects($this->once())
            ->method('updateTarget')
            ->willReturn((new \OCA\OpenConnector\Db\SynchronizationContract())->hydrate($contract->jsonSerialize()));

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(1, $deleted, 'In-scope orphan should be deleted');
    }//end testInScopeOrphanIsDeleted()

    /**
     * Cross-scope contract: target UUID does NOT live in this sync's register/schema → skip delete.
     *
     * Without the scope check the unscoped delete inside `updateTargetOpenRegister`
     * would happily delete the foreign-scope object that happens to share the UUID.
     *
     * @return void
     */
    public function testCrossScopeContractIsSkipped(): void
    {
        $sync     = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_OUT_OF_SCOPE);

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$contract], 'total' => 1]);

        // Scope-check find returns null — object is in a different register/schema or gone.
        $this->orObjectService->method('find')->willReturn(null);

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted, 'Out-of-scope contract must not trigger deletion');
    }//end testCrossScopeContractIsSkipped()

    /**
     * Scope-check throws DoesNotExistException → swallowed silently, no delete.
     *
     * @return void
     */
    public function testDoesNotExistExceptionIsSwallowed(): void
    {
        $sync     = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_OUT_OF_SCOPE);

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$contract], 'total' => 1]);

        $this->orObjectService->method('find')
            ->willThrowException(new DoesNotExistException('object gone'));

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted);
    }//end testDoesNotExistExceptionIsSwallowed()

    /**
     * Scope-check throws an unexpected Throwable → warning logged, candidate skipped, loop continues.
     *
     * @return void
     */
    public function testUnexpectedThrowableLogsWarningAndContinues(): void
    {
        $sync     = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_IN_SCOPE);

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$contract], 'total' => 1]);

        $this->orObjectService->method('find')
            ->willThrowException(new \RuntimeException('database connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Scope check failed'),
                $this->callback(
                    function ($context) {
                        return isset($context['synchronizationId'])
                            && isset($context['targetId'])
                            && isset($context['error']);
                    }
                )
            );

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted);
    }//end testUnexpectedThrowableLogsWarningAndContinues()

    /**
     * Malformed targetId (no `/` separator) → warning logged, no contracts inspected.
     *
     * @return void
     */
    public function testMalformedTargetIdLogsWarningAndBails(): void
    {
        $sync = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'targetType' => 'register/schema',
                'targetId'   => 'not-a-slash-separated-string',
            ],
            self::SYNC_UUID
        );

        $this->orObjectService->expects($this->never())->method('findAll');
        $this->orObjectService->expects($this->never())->method('find');
        $this->service->expects($this->never())->method('updateTarget');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('targetId not in register/schema format'));

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted);
    }//end testMalformedTargetIdLogsWarningAndBails()

    /**
     * Mixed batch: one in-scope, one out-of-scope → only in-scope orphan deleted.
     *
     * @return void
     */
    public function testInScopeAndOutOfScopeMixedBatch(): void
    {
        $sync         = $this->makeSync();
        $inScope      = $this->makeContract(self::TARGET_IN_SCOPE, 'origin-a');
        $outOfScope   = $this->makeContract(self::TARGET_OUT_OF_SCOPE, 'origin-b');
        $pos          = $this->findParamPositions();
        $targetEntity = ObjectServiceMockBuilder::objectEntity($this, [], self::TARGET_IN_SCOPE);

        // First findAll: list of contracts. Subsequent findAlls: per-target re-lookup.
        $this->orObjectService->method('findAll')
            ->willReturnOnConsecutiveCalls(
                ['results' => [$inScope, $outOfScope], 'total' => 2],
                ['results' => [$inScope], 'total' => 1]
            );

        $this->orObjectService->method('find')
            ->willReturnCallback(
                function (...$args) use ($pos, $targetEntity) {
                    return $args[$pos['id']] === self::TARGET_IN_SCOPE ? $targetEntity : null;
                }
            );

        $this->service->expects($this->once())
            ->method('updateTarget')
            ->willReturn((new \OCA\OpenConnector\Db\SynchronizationContract())->hydrate($inScope->jsonSerialize()));

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(1, $deleted, 'Only in-scope orphan should be deleted in mixed batch');
    }//end testInScopeAndOutOfScopeMixedBatch()
}//end class
