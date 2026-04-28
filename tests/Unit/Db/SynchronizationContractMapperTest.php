<?php

namespace OCA\OpenConnector\Tests\Unit\Db;

use OCA\OpenConnector\Db\SynchronizationContractMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that findAllBySynchronization no longer JOINs against
 * openregister_objects and filters only by synchronization_id.
 */
class SynchronizationContractMapperTest extends TestCase
{
    private SynchronizationContractMapper $mapper;
    /** @var IDBConnection&MockObject */
    private IDBConnection $db;
    /** @var IQueryBuilder&MockObject */
    private IQueryBuilder $qb;
    /** @var IExpressionBuilder&MockObject */
    private IExpressionBuilder $expr;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturnArgument(0);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);

        $this->mapper = new SynchronizationContractMapper($this->db);
    }

    public function testFindAllBySynchronizationDoesNotJoinAgainstOpenregisterObjects(): void
    {
        $this->qb->expects($this->never())->method('innerJoin');
        $this->qb->expects($this->never())->method('leftJoin');

        // findEntities will throw because we don't mock executeQuery; the catch block
        // returns []. We just want to assert the query construction surface.
        $result = $this->mapper->findAllBySynchronization('sync-1');

        $this->assertSame([], $result);
    }

    public function testFindAllBySynchronizationSelectsAllColumnsWithoutAlias(): void
    {
        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $this->qb->expects($this->once())
            ->method('from')
            ->with('openconnector_synchronization_contracts')
            ->willReturnSelf();

        $this->mapper->findAllBySynchronization('sync-1');
    }

    public function testFindAllBySynchronizationFiltersOnlyBySynchronizationId(): void
    {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('synchronization_id', 'sync-42');

        $this->mapper->findAllBySynchronization('sync-42');
    }

    public function testRenamedFromFindAllBySynchronizationAndSchema(): void
    {
        // The old method name must be gone so callers fail at parse time, not silently.
        $this->assertFalse(
            method_exists(SynchronizationContractMapper::class, 'findAllBySynchronizationAndSchema'),
            'Old method findAllBySynchronizationAndSchema should be removed.'
        );

        $this->assertTrue(
            method_exists(SynchronizationContractMapper::class, 'findAllBySynchronization'),
            'New method findAllBySynchronization should exist.'
        );
    }
}
