<?php

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Db\Synchronization;
use OCA\OpenConnector\Db\SynchronizationContract;
use OCA\OpenConnector\Db\SynchronizationContractLogMapper;
use OCA\OpenConnector\Db\SynchronizationContractMapper;
use OCA\OpenConnector\Db\SynchronizationLogMapper;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the scope-checked cleanup behavior in SynchronizationService::deleteInvalidObjects.
 *
 * These tests use a partial mock of SynchronizationService so the cleanup logic can be
 * exercised independently of updateTarget (which has its own deep dependency chain).
 */
class SynchronizationServiceCleanupTest extends TestCase
{
    private const SYNC_DB_ID = 1;
    private const REGISTER_ID = '1';
    private const SCHEMA_ID = '2';
    private const TARGET_IN_SCOPE = 'object-in-scope-uuid';
    private const TARGET_OUT_OF_SCOPE = 'object-out-of-scope-uuid';
    private const TARGET_MISSING = 'object-missing-uuid';

    /** @var SynchronizationContractMapper&MockObject */
    private $contractMapper;
    /** @var ContainerInterface&MockObject */
    private $container;
    /** @var ObjectService&MockObject Mock for OpenRegister ObjectService (resolved via container) */
    private $openRegisterObjectService;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @var SynchronizationService&MockObject */
    private $service;

    protected function setUp(): void
    {
        $this->contractMapper = $this->createMock(SynchronizationContractMapper::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->openRegisterObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // The cleanup pass resolves OpenRegister's ObjectService via the container.
        $this->container->method('get')
            ->with('OCA\\OpenRegister\\Service\\ObjectService')
            ->willReturn($this->openRegisterObjectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $constructorArgs = [
            $this->createMock(CallService::class),
            $this->createMock(MappingService::class),
            $this->container,
            $this->createMock(SourceMapper::class),
            $this->createMock(MappingMapper::class),
            $this->createMock(SynchronizationMapper::class),
            $this->createMock(SynchronizationLogMapper::class),
            $this->contractMapper,
            $this->createMock(SynchronizationContractLogMapper::class),
            $this->createMock(ObjectService::class),
            $this->createMock(StorageService::class),
            $this->createMock(RuleMapper::class),
            $this->logger,
            $appConfig,
        ];

        $this->service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs($constructorArgs)
            ->onlyMethods(['updateTarget'])
            ->getMock();
    }

    private function makeSync(): Synchronization
    {
        $sync = new Synchronization();
        $sync->setId(self::SYNC_DB_ID);
        $sync->setUuid('sync-uuid-1');
        $sync->setTargetType('register/schema');
        $sync->setTargetId(self::REGISTER_ID . '/' . self::SCHEMA_ID);
        return $sync;
    }

    private function makeContract(string $targetId, string $originId = 'origin-x'): SynchronizationContract
    {
        $contract = new SynchronizationContract();
        $contract->setSynchronizationId((string) self::SYNC_DB_ID);
        $contract->setTargetId($targetId);
        $contract->setOriginId($originId);
        return $contract;
    }

    /**
     * Map ObjectService::find parameter names to positional indices.
     *
     * Production calls find() with named arguments; PHPUnit's stub receives positional
     * arguments after the runtime expansion. Using reflection here keeps assertions
     * robust against signature changes — adding a parameter shifts all later positions
     * but the named lookup follows automatically.
     */
    private function findParamPositions(): array
    {
        $rm = new ReflectionMethod(\OCA\OpenRegister\Service\ObjectService::class, 'find');
        $positions = [];
        foreach ($rm->getParameters() as $i => $p) {
            $positions[$p->getName()] = $i;
        }
        return $positions;
    }

    public function testInScopeOrphanIsDeleted(): void
    {
        $sync = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_IN_SCOPE);
        $pos = $this->findParamPositions();

        $this->contractMapper->method('findAllBySynchronization')
            ->with(self::SYNC_DB_ID)
            ->willReturn([$contract]);

        $this->contractMapper->method('findOnTarget')
            ->with(self::SYNC_DB_ID, self::TARGET_IN_SCOPE)
            ->willReturn($contract);

        $this->openRegisterObjectService->expects($this->once())
            ->method('find')
            ->willReturnCallback(function (...$args) use ($pos) {
                $this->assertSame(self::TARGET_IN_SCOPE, $args[$pos['id']]);
                $this->assertSame(self::REGISTER_ID, $args[$pos['register']]);
                $this->assertSame(self::SCHEMA_ID, $args[$pos['schema']]);
                $this->assertFalse($args[$pos['_rbac']], '_rbac must be false to match prior unscoped JOIN behavior');
                $this->assertFalse($args[$pos['_multitenancy']], '_multitenancy must be false to match prior unscoped JOIN behavior');
                return $this->createMock(ObjectEntity::class);
            });

        $this->service->expects($this->once())
            ->method('updateTarget')
            ->with($contract, [], 'delete')
            ->willReturn($contract);

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(1, $deleted, 'Orphan in scope should be deleted');
    }

    public function testCrossScopeContractIsSkipped(): void
    {
        $sync = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_OUT_OF_SCOPE);

        $this->contractMapper->method('findAllBySynchronization')
            ->with(self::SYNC_DB_ID)
            ->willReturn([$contract]);

        // Foreign-scope find returns null — object exists but lives in a different register/schema.
        $this->openRegisterObjectService->method('find')->willReturn(null);

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted, 'Out-of-scope contract must not trigger deletion');
    }

    public function testMissingTargetIsSkipped(): void
    {
        $sync = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_MISSING);

        $this->contractMapper->method('findAllBySynchronization')
            ->with(self::SYNC_DB_ID)
            ->willReturn([$contract]);

        // No object with that UUID exists anywhere.
        $this->openRegisterObjectService->method('find')->willReturn(null);

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted);
    }

    public function testWarningLoggedWhenContractLookupThrows(): void
    {
        $sync = $this->makeSync();
        $contract = $this->makeContract(self::TARGET_IN_SCOPE);

        $this->contractMapper->method('findAllBySynchronization')
            ->with(self::SYNC_DB_ID)
            ->willReturn([$contract]);

        $this->openRegisterObjectService->method('find')
            ->willReturn($this->createMock(ObjectEntity::class));

        $this->contractMapper->method('findOnTarget')
            ->willThrowException(new DoesNotExistException('contract gone'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Contract not found on second lookup'),
                $this->callback(function ($context) {
                    return isset($context['synchronizationId'])
                        && isset($context['targetId'])
                        && isset($context['error']);
                })
            );

        $this->service->expects($this->never())->method('updateTarget');

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(0, $deleted);
    }

    public function testInScopeAndOutOfScopeMixedBatch(): void
    {
        $sync = $this->makeSync();
        $inScope = $this->makeContract(self::TARGET_IN_SCOPE, 'origin-a');
        $outOfScope = $this->makeContract(self::TARGET_OUT_OF_SCOPE, 'origin-b');
        $pos = $this->findParamPositions();

        $this->contractMapper->method('findAllBySynchronization')
            ->with(self::SYNC_DB_ID)
            ->willReturn([$inScope, $outOfScope]);

        $this->openRegisterObjectService->method('find')
            ->willReturnCallback(function (...$args) use ($pos) {
                return $args[$pos['id']] === self::TARGET_IN_SCOPE
                    ? $this->createMock(ObjectEntity::class)
                    : null;
            });

        $this->contractMapper->method('findOnTarget')
            ->willReturnCallback(function ($syncId, $targetId) use ($inScope) {
                return $targetId === self::TARGET_IN_SCOPE ? $inScope : null;
            });

        $this->service->expects($this->once())
            ->method('updateTarget')
            ->with($inScope, [], 'delete')
            ->willReturn($inScope);

        $deleted = $this->service->deleteInvalidObjects($sync, []);

        $this->assertSame(1, $deleted, 'Only in-scope orphan should be deleted in mixed batch');
    }
}
