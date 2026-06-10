<?php
/**
 * Regression tests locking the Phase-0 OpenRegister-cutover behaviour of the
 * five migrated mappers.
 *
 * After the cutover the legacy `openconnector_*` tables were dropped and each
 * mapper became a thin adapter over `\OCA\OpenRegister\Service\ObjectService`
 * (register `openconnector`). These tests pin the OR verbs the adapters call,
 * the register/schema (`@self`) scoping of every read, and the append-only /
 * write-once contract of the contract-log mapper. No live database is used:
 * the OpenRegister ObjectService is mocked throughout.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Db;

use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\SynchronizationContract;
use OCA\OpenConnector\Db\SynchronizationContractLog;
use OCA\OpenConnector\Db\SynchronizationContractLogMapper;
use OCA\OpenConnector\Db\SynchronizationContractMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister-cutover regression coverage for the migrated mappers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
final class OrCutoverMappersRegressionTest extends TestCase
{

    /**
     * Build an OpenRegister ObjectEntity carrying the given body + uuid.
     *
     * @param array  $body The object body.
     * @param string $uuid The object uuid.
     *
     * @return ObjectEntity The hydrated stub entity.
     */
    private function entity(array $body, string $uuid='00000000-0000-0000-0000-000000000001'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($body);

        return $entity;

    }//end entity()


    /**
     * find() on SourceMapper reads via ObjectService::find scoped to the
     * `openconnector`/`source` register+schema (the @self scope).
     *
     * @return void
     */
    public function testSourceMapperFindReadsViaObjectServiceWithSelfScope(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('find')
            ->with('src-1', 'openconnector', 'source')
            ->willReturn($this->entity(['name' => 'a-source'], 'src-1'));

        $mapper = new SourceMapper($objectService);
        $source = $mapper->find('src-1');

        $this->assertSame('a-source', $source->jsonSerialize()['name']);

    }//end testSourceMapperFindReadsViaObjectServiceWithSelfScope()


    /**
     * findAll() on SourceMapper issues an ObjectService::findAll whose filters
     * pin register+schema and unwraps the `results` envelope.
     *
     * @return void
     */
    public function testSourceMapperFindAllScopesRegisterAndSchema(): void
    {
        $captured      = null;
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('findAll')
            ->willReturnCallback(
                function (array $config) use (&$captured): array {
                    $captured = $config;
                    return ['results' => [$this->entity(['name' => 's1'], 'u1')]];
                }
            );

        $mapper = new SourceMapper($objectService);
        $result = $mapper->findAll();

        $this->assertCount(1, $result);
        $this->assertSame('openconnector', $captured['filters']['register']);
        $this->assertSame('source', $captured['filters']['schema']);

    }//end testSourceMapperFindAllScopesRegisterAndSchema()


    /**
     * createFromArray() on SourceMapper writes via ObjectService::saveObject,
     * scoped to register+schema, and drops the stray int `id`.
     *
     * @return void
     */
    public function testSourceMapperCreateWritesViaSaveObjectAndDropsId(): void
    {
        $captured      = null;
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema) use (&$captured): ObjectEntity {
                    $captured = ['object' => $object, 'register' => $register, 'schema' => $schema];
                    return $this->entity($object, 'new-uuid');
                }
            );

        $mapper = new SourceMapper($objectService);
        $mapper->createFromArray(['id' => 7, 'name' => 'fresh']);

        $this->assertArrayNotHasKey('id', $captured['object']);
        $this->assertSame('openconnector', $captured['register']);
        $this->assertSame('source', $captured['schema']);
        $this->assertArrayHasKey('uuid', $captured['object']);

    }//end testSourceMapperCreateWritesViaSaveObjectAndDropsId()


    /**
     * find() on MappingMapper reads via ObjectService::find with @self scope.
     *
     * @return void
     */
    public function testMappingMapperFindReadsViaObjectService(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('find')
            ->with('map-1', 'openconnector', 'mapping')
            ->willReturn($this->entity(['name' => 'a-map'], 'map-1'));

        $mapper  = new MappingMapper($objectService);
        $mapping = $mapper->find('map-1');

        $this->assertSame('a-map', $mapping->jsonSerialize()['name']);

    }//end testMappingMapperFindReadsViaObjectService()


    /**
     * createFromArray() on MappingMapper writes via ObjectService::saveObject
     * scoped to the mapping register+schema.
     *
     * @return void
     */
    public function testMappingMapperCreateWritesViaSaveObject(): void
    {
        $captured      = null;
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema) use (&$captured): ObjectEntity {
                    $captured = ['object' => $object, 'register' => $register, 'schema' => $schema];
                    return $this->entity($object, 'map-new');
                }
            );

        $mapper = new MappingMapper($objectService);
        $mapper->createFromArray(['id' => 3, 'name' => 'm']);

        $this->assertArrayNotHasKey('id', $captured['object']);
        $this->assertSame('openconnector', $captured['register']);
        $this->assertSame('mapping', $captured['schema']);

    }//end testMappingMapperCreateWritesViaSaveObject()


    /**
     * find() on RuleMapper reads via ObjectService::find with @self scope.
     *
     * @return void
     */
    public function testRuleMapperFindReadsViaObjectService(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('find')
            ->with('rule-1', 'openconnector', 'rule')
            ->willReturn($this->entity(['name' => 'a-rule'], 'rule-1'));

        $mapper = new RuleMapper($objectService);
        $rule   = $mapper->find('rule-1');

        $this->assertSame('a-rule', $rule->jsonSerialize()['name']);

    }//end testRuleMapperFindReadsViaObjectService()


    /**
     * createFromArray() on RuleMapper writes via ObjectService::saveObject
     * scoped to the rule register+schema.
     *
     * @return void
     */
    public function testRuleMapperCreateWritesViaSaveObject(): void
    {
        $captured      = null;
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema) use (&$captured): ObjectEntity {
                    $captured = ['register' => $register, 'schema' => $schema];
                    return $this->entity($object, 'rule-new');
                }
            );

        $mapper = new RuleMapper($objectService);
        $mapper->createFromArray(['name' => 'r']);

        $this->assertSame('openconnector', $captured['register']);
        $this->assertSame('rule', $captured['schema']);

    }//end testRuleMapperCreateWritesViaSaveObject()


    /**
     * find() on SynchronizationContractMapper reads via ObjectService::find
     * with @self scope (register `openconnector`, schema `synchronization_contract`).
     *
     * @return void
     */
    public function testContractMapperFindReadsViaObjectService(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('find')
            ->with('c-1', 'openconnector', 'synchronization_contract')
            ->willReturn($this->entity(['originId' => 'o-1'], 'c-1'));

        $mapper   = new SynchronizationContractMapper($objectService);
        $contract = $mapper->find('c-1');

        $this->assertSame('o-1', $contract->jsonSerialize()['originId']);

    }//end testContractMapperFindReadsViaObjectService()


    /**
     * insert() on SynchronizationContractMapper persists via saveObject scoped
     * to register+schema and drops the stray int `id`.
     *
     * @return void
     */
    public function testContractMapperInsertWritesViaSaveObjectAndDropsId(): void
    {
        $captured      = null;
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema, $uuid) use (&$captured): ObjectEntity {
                    $captured = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
                    return $this->entity($object, (string) $uuid);
                }
            );

        $mapper   = new SynchronizationContractMapper($objectService);
        $contract = new SynchronizationContract();
        $contract->setId(99);
        $contract->setOriginId('origin-x');

        $mapper->insert($contract);

        $this->assertArrayNotHasKey('id', $captured['object']);
        $this->assertSame('openconnector', $captured['register']);
        $this->assertSame('synchronization_contract', $captured['schema']);
        $this->assertNotEmpty($captured['uuid']);

    }//end testContractMapperInsertWritesViaSaveObjectAndDropsId()


    /**
     * Build a contract-log mapper with a logged-in user + session id.
     *
     * @param OrObjectService&MockObject $objectService The OR service mock.
     *
     * @return SynchronizationContractLogMapper The constructed mapper.
     */
    private function makeContractLogMapper(OrObjectService $objectService): SynchronizationContractLogMapper
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $session = $this->createMock(ISession::class);
        $session->method('getId')->willReturn('sess-1');

        return new SynchronizationContractLogMapper($objectService, $userSession, $session);

    }//end makeContractLogMapper()


    /**
     * createFromArray() on the contract-log mapper builds an in-memory handle
     * WITHOUT writing (the append-only schema demands a single final write).
     *
     * @return void
     */
    public function testContractLogCreateFromArrayDoesNotWrite(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->never())->method('saveObject');

        $mapper = $this->makeContractLogMapper($objectService);
        $log    = $mapper->createFromArray(['message' => 'building']);

        $this->assertInstanceOf(SynchronizationContractLog::class, $log);
        // The handle carries a pre-generated uuid the engine can reference.
        $this->assertNotEmpty($log->jsonSerialize()['uuid']);

    }//end testContractLogCreateFromArrayDoesNotWrite()


    /**
     * update() on the contract-log mapper performs the single append-only INSERT
     * (saveObject WITHOUT a uuid parameter, i.e. an OR CREATE).
     *
     * @return void
     */
    public function testContractLogUpdateInsertsWriteOnceAsCreate(): void
    {
        $captured      = [];
        $objectService = $this->createMock(OrObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema, $uuid=null) use (&$captured): ObjectEntity {
                    $captured = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid];
                    return $this->entity($object, 'log-stored');
                }
            );

        $mapper = $this->makeContractLogMapper($objectService);
        $log    = $mapper->createFromArray(['message' => 'done']);
        $mapper->update($log);

        $this->assertSame('openconnector', $captured['register']);
        $this->assertSame('synchronization_contract_log', $captured['schema']);
        // Append-only: CREATE means no uuid parameter is passed to saveObject.
        $this->assertNull($captured['uuid']);

    }//end testContractLogUpdateInsertsWriteOnceAsCreate()


    /**
     * A second update() for the same (already-persisted) contract log is a
     * no-op: the append-only schema forbids a second write.
     *
     * @return void
     */
    public function testContractLogUpdateIsWriteOnceNoOpOnSecondCall(): void
    {
        $objectService = $this->createMock(OrObjectService::class);
        // Exactly one write across two update() calls for the same log.
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                fn ($object): ObjectEntity => $this->entity($object, 'log-stored')
            );

        $mapper = $this->makeContractLogMapper($objectService);
        $log    = $mapper->createFromArray(['message' => 'done']);

        $mapper->update($log);
        $mapper->update($log);

    }//end testContractLogUpdateIsWriteOnceNoOpOnSecondCall()

}//end class
