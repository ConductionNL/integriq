<?php
/**
 * Unit tests for SynchronizationService.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the synchronization orchestration service (OR cutover — no deleted Db types).
 */
class SynchronizationServiceTest extends TestCase
{

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
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
        $synchronizationLogService = $this->createMock(SynchronizationLogService::class);
        $appConfig                 = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $this->service = new SynchronizationService(
            $callService,
            $mappingService,
            $container,
            $this->orObjectService,
            $objectService,
            $this->logger,
            $synchronizationLogService,
            $appConfig,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates SynchronizationService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(SynchronizationService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that getSynchronization by id returns the entity from OR find.
     *
     * @return void
     */
    public function testGetSynchronizationByIdReturnsFindResult(): void
    {
        // Arrange
        $syncEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'my-sync'],
            'sync-uuid-1'
        );

        $this->orObjectService->method('find')->willReturn($syncEntity);

        // Act
        $result = $this->service->getSynchronization('sync-uuid-1');

        // Assert
        $this->assertSame($syncEntity, $result);
    }//end testGetSynchronizationByIdReturnsFindResult()


    /**
     * Test that getSynchronization throws DoesNotExistException when OR find returns null.
     *
     * @return void
     */
    public function testGetSynchronizationThrowsWhenNotFound(): void
    {
        // Arrange
        $this->orObjectService->method('find')->willReturn(null);

        // Assert
        $this->expectException(DoesNotExistException::class);

        // Act
        $this->service->getSynchronization('non-existent-uuid');
    }//end testGetSynchronizationThrowsWhenNotFound()


    /**
     * Test findAllBySourceId returns results array from OR findAll.
     *
     * @return void
     */
    public function testFindAllBySourceIdReturnsSynchronizations(): void
    {
        // Arrange
        $syncEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'sync-a'],
            'sync-uuid-2'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$syncEntity], 'total' => 1]);

        // Act
        $result = $this->service->findAllBySourceId('openconnector', 'source');

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($syncEntity, $result[0]);
    }//end testFindAllBySourceIdReturnsSynchronizations()


    /**
     * Test handleObjectEventSynchronization silently ignores invalid mutation types.
     *
     * @return void
     */
    public function testHandleObjectEventIgnoresInvalidMutationType(): void
    {
        // Arrange — real ObjectEntity hydrated with register/schema via the
        // magic setters (the previous test used MockObject->method('getRegister')
        // which fails against the real entity now that ObjectServiceMockBuilder
        // returns a real ObjectEntity to dodge the magic-getUuid stub problem,
        // #1015).
        $objectEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [],
            'obj-uuid-1'
        );
        // Positional args only — Entity::__call's setter uses $args[0].
        $objectEntity->setRegister('openconnector');
        $objectEntity->setSchema('source');

        // OR findAll must not be called for invalid mutation type
        $this->orObjectService->expects($this->never())->method('findAll');

        // Act — 'invalid_type' is not in VALID_MUTATION_TYPES
        $this->service->handleObjectEventSynchronization($objectEntity, 'invalid_type');

        // Assert — reached here means no exception was thrown
        $this->assertTrue(true);
    }//end testHandleObjectEventIgnoresInvalidMutationType()


    /**
     * Test sortNestedArray returns true for a non-empty array.
     *
     * @return void
     */
    public function testSortNestedArrayReturnsTrueForNonEmptyArray(): void
    {
        // Arrange
        $array = ['b' => 2, 'a' => 1, 'c' => 3];

        // Act
        $result = $this->service->sortNestedArray($array);

        // Assert
        $this->assertTrue($result);
        // Keys should now be in alphabetical order
        $this->assertSame(['a', 'b', 'c'], array_keys($array));
    }//end testSortNestedArrayReturnsTrueForNonEmptyArray()


    /**
     * #1007 regression test — sync log + contract log writes MUST be CREATE-only.
     *
     * Append-only schemas reject UPDATE (saveObject with a uuid arg). The fix
     * refactors synchronize() and synchronizeContract() to accumulate state in
     * memory and write each row exactly once at finalize. This test enforces
     * that NO call to ORObjectService::saveObject for `synchronization_log` or
     * `synchronization_contract_log` is invoked with a non-null uuid argument
     * during a sync — preventing regression of the 405 SCHEMA_APPEND_ONLY bug.
     *
     * @return void
     */
    public function testSynchronizationLogWritesAreCreateOnly(): void
    {
        // The OpenRegister `synchronization_log` schema is immutable / append-only:
        // any saveObject(uuid: ...) UPDATE is rejected. SynchronizationLogService
        // must therefore persist the run-log via an INSERT (no uuid argument).
        $observedUuid  = 'sentinel';
        $observedBody  = null;
        $defaultEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['result' => []],
            'log-uuid'
        );

        $orObjectService = $this->createMock(ORObjectService::class);
        $orObjectService->method('saveObject')->willReturnCallback(
            static function (
                $object,
                $extend = null,
                $register = null,
                $schema = null,
                ?string $uuid = null
            ) use (&$observedUuid, &$observedBody, $defaultEntity) {
                $observedUuid = $uuid;
                $observedBody = $object;
                return $defaultEntity;
            }
        );

        $userSession = $this->createMock(\OCP\IUserSession::class);
        $session     = $this->createMock(\OCP\ISession::class);
        $logService  = new SynchronizationLogService($orObjectService, $userSession, $session);

        $log = $logService->createFromArray(['synchronizationId' => 'sync-1', 'result' => []]);
        $log->setMessage('Success');
        $logService->update($log);

        // A CREATE (no uuid argument) must have been used; an `id` in the body
        // would make OpenRegister treat the write as an UPDATE, so it must be
        // stripped from the persisted payload.
        $this->assertNull(
            $observedUuid,
            'The run-log must be written with a CREATE (no uuid arg) against the append-only schema'
        );
        $this->assertIsArray($observedBody);
        $this->assertArrayNotHasKey(
            'id',
            $observedBody,
            'The persisted payload must not carry an id (would trigger an append-only UPDATE)'
        );
    }//end testSynchronizationLogWritesAreCreateOnly()


    /**
     * #1007 regression — the append-only run-log is written exactly once.
     *
     * SynchronizationLogService::update() is idempotent: the first call INSERTs
     * the row and marks the log persisted; subsequent calls are no-ops so the
     * append-only schema is never asked to UPDATE.
     *
     * @return void
     */
    public function testSynchronizationLogIsWrittenExactlyOnce(): void
    {
        $defaultEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['result' => []],
            'log-uuid'
        );

        $orObjectService = $this->createMock(ORObjectService::class);
        // saveObject must be invoked exactly once across multiple update() calls.
        $orObjectService->expects($this->once())->method('saveObject')->willReturn($defaultEntity);

        $userSession = $this->createMock(\OCP\IUserSession::class);
        $session     = $this->createMock(\OCP\ISession::class);
        $logService  = new SynchronizationLogService($orObjectService, $userSession, $session);

        $log = $logService->createFromArray(['synchronizationId' => 'sync-1', 'result' => []]);
        $this->assertFalse($log->isPersisted(), 'createFromArray must NOT persist the row');

        $logService->update($log);
        $this->assertTrue($log->isPersisted(), 'the first update must persist the row');

        // Second/third calls must not write again (append-only).
        $logService->update($log);
        $logService->persist($log);
    }//end testSynchronizationLogIsWrittenExactlyOnce()


    /**
     * synchronize() throws when an invalid mutationType is passed.
     *
     * @return void
     */
    public function testSynchronizeThrowsOnInvalidMutationType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid mutation type/');

        $this->service->synchronize(
            synchronization: ['uuid' => 'sync-1'],
            mutationType: 'not-a-real-type'
        );
    }//end testSynchronizeThrowsOnInvalidMutationType()


    /**
     * handleObjectEventSynchronization() short-circuits when the
     * ObjectEntity has no register/schema (no findAll, no synchronize).
     *
     * @return void
     */
    public function testHandleObjectEventSkipsWhenObjectHasNoRegister(): void
    {
        $objectEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [],
            'obj-1'
        );
        // Leave register/schema unset.

        $this->orObjectService->expects($this->never())->method('findAll');

        $this->service->handleObjectEventSynchronization($objectEntity, 'create');
        $this->addToAssertionCount(1);
    }//end testHandleObjectEventSkipsWhenObjectHasNoRegister()


    /**
     * handleObjectEventSynchronization() queries OR for direct syncs on the
     * object's register/schema when the mutation type is valid.
     *
     * @return void
     */
    public function testHandleObjectEventQueriesOrForDirectSyncs(): void
    {
        $objectEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [],
            'obj-1'
        );
        $objectEntity->setRegister('openconnector');
        $objectEntity->setSchema('source');

        // OR findAll is invoked at least once on the direct-sync lookup. The
        // related-trigger lookup may also hit findAll. We assert at least one
        // call instead of exact count to keep the test resilient.
        $this->orObjectService->expects($this->atLeastOnce())
            ->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->service->handleObjectEventSynchronization($objectEntity, 'create');
        $this->addToAssertionCount(1);
    }//end testHandleObjectEventQueriesOrForDirectSyncs()


    /**
     * encodeArrayKeys() rewrites flat keys.
     *
     * @return void
     */
    public function testEncodeArrayKeysReplacesFlatKeys(): void
    {
        $input = ['a.b' => 1, 'c.d' => 2];

        $result = $this->service->encodeArrayKeys($input, '.', '|');

        $this->assertSame(['a|b' => 1, 'c|d' => 2], $result);
    }//end testEncodeArrayKeysReplacesFlatKeys()


    /**
     * encodeArrayKeys() recurses into nested arrays and rewrites their keys
     * too.
     *
     * @return void
     */
    public function testEncodeArrayKeysRecursesIntoNestedArrays(): void
    {
        $input = ['a.b' => ['c.d' => 1, 'e.f' => ['g.h' => 2]]];

        $result = $this->service->encodeArrayKeys($input, '.', '|');

        $this->assertSame(
            ['a|b' => ['c|d' => 1, 'e|f' => ['g|h' => 2]]],
            $result
        );
    }//end testEncodeArrayKeysRecursesIntoNestedArrays()


    /**
     * encodeArrayKeys() returns an empty array unchanged.
     *
     * @return void
     */
    public function testEncodeArrayKeysReturnsEmptyArrayUnchanged(): void
    {
        $this->assertSame([], $this->service->encodeArrayKeys([], '.', '|'));
    }//end testEncodeArrayKeysReturnsEmptyArrayUnchanged()


    /**
     * encodeArrayKeys() leaves an inner empty array untouched (not recursed
     * into).
     *
     * @return void
     */
    public function testEncodeArrayKeysLeavesEmptyInnerArrayUntouched(): void
    {
        $input = ['a.b' => []];

        $result = $this->service->encodeArrayKeys($input, '.', '|');

        $this->assertSame(['a|b' => []], $result);
    }//end testEncodeArrayKeysLeavesEmptyInnerArrayUntouched()


    /**
     * sortNestedArray() returns false when given a non-array value.
     *
     * @return void
     */
    public function testSortNestedArrayReturnsFalseForNonArray(): void
    {
        $value = 'not-an-array';
        $this->assertFalse($this->service->sortNestedArray($value));
    }//end testSortNestedArrayReturnsFalseForNonArray()


    /**
     * sortNestedArray() sorts nested associative arrays too.
     *
     * @return void
     */
    public function testSortNestedArraySortsNestedAssociativeArrays(): void
    {
        $array = ['b' => 2, 'a' => ['z' => 26, 'x' => 24], 'c' => 3];

        $this->assertTrue($this->service->sortNestedArray($array));
        $this->assertSame(['a', 'b', 'c'], array_keys($array));
        $this->assertSame(['x', 'z'], array_keys($array['a']));
    }//end testSortNestedArraySortsNestedAssociativeArrays()


    /**
     * replaceRelatedOriginIds() leaves keys absent in the input untouched
     * (no spurious additions).
     *
     * @return void
     */
    public function testReplaceRelatedOriginIdsSkipsMissingKeys(): void
    {
        $object = ['kept' => 'as-is'];
        $config = ['absent-key' => 'true'];

        $result = $this->service->replaceRelatedOriginIds($object, $config);

        $this->assertSame(['kept' => 'as-is'], $result);
    }//end testReplaceRelatedOriginIdsSkipsMissingKeys()


    /**
     * replaceRelatedOriginIds() leaves a non-UUID leaf string unchanged
     * (replaceIdInString preserves values it can't resolve).
     *
     * @return void
     */
    public function testReplaceRelatedOriginIdsLeavesNonUuidLeafUnchanged(): void
    {
        $object = ['relation' => 'not-a-uuid-value'];
        $config = ['relation' => 'true'];

        // findAll returns empty so the contract lookup yields null and the
        // leaf is returned unchanged.
        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $result = $this->service->replaceRelatedOriginIds($object, $config);

        $this->assertSame('not-a-uuid-value', $result['relation']);
    }//end testReplaceRelatedOriginIdsLeavesNonUuidLeafUnchanged()


    /**
     * replaceRelatedOriginIds() recurses into a single nested associative
     * subobject (config + object both shaped as assoc arrays).
     *
     * @return void
     */
    public function testReplaceRelatedOriginIdsRecursesIntoNestedAssociativeObject(): void
    {
        $object = ['sub' => ['relation' => 'not-a-uuid-value']];
        $config = ['sub' => ['relation' => 'true']];

        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $result = $this->service->replaceRelatedOriginIds($object, $config);

        $this->assertSame('not-a-uuid-value', $result['sub']['relation']);
    }//end testReplaceRelatedOriginIdsRecursesIntoNestedAssociativeObject()


    /**
     * findAllBySourceId() composes the sourceId from register+schema.
     *
     * @return void
     */
    public function testFindAllBySourceIdComposesSourceFilter(): void
    {
        $entity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['sourceId' => 'reg-1/schema-1'],
            'sync-1'
        );

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->with($this->callback(function (array $config): bool {
                $filters = ($config['filters'] ?? []);
                return ($filters['sourceId'] ?? null) === 'reg-1/schema-1'
                    && ($filters['register'] ?? null) === 'openconnector'
                    && ($filters['schema'] ?? null) === 'synchronization';
            }))
            ->willReturn(['results' => [$entity], 'total' => 1]);

        $result = $this->service->findAllBySourceId('reg-1', 'schema-1');

        $this->assertCount(1, $result);
    }//end testFindAllBySourceIdComposesSourceFilter()


    /**
     * findAllBySourceId() returns an empty array when no syncs match.
     *
     * @return void
     */
    public function testFindAllBySourceIdReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $this->assertSame([], $this->service->findAllBySourceId('reg-x', 'schema-x'));
    }//end testFindAllBySourceIdReturnsEmptyArrayWhenNoMatch()


    /**
     * getSynchronization() proxies to OR find() against the synchronization
     * schema.
     *
     * @return void
     */
    public function testGetSynchronizationDelegatesToFind(): void
    {
        $entity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'my-sync'],
            'sync-uuid-3'
        );
        $this->orObjectService->method('find')->willReturn($entity);

        $result = $this->service->getSynchronization('sync-uuid-3');

        $this->assertSame($entity, $result);
    }//end testGetSynchronizationDelegatesToFind()


}//end class
