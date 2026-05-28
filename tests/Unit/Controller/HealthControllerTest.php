<?php
/**
 * Unit tests for HealthController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\HealthController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the health check endpoint controller.
 */
class HealthControllerTest extends TestCase
{

    /**
     * @var HealthController
     */
    private HealthController $controller;

    /**
     * @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $db;

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

        $request      = $this->createMock(IRequest::class);
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new HealthController(
            'openconnector',
            $request,
            $this->db,
            $this->logger
        );

    }//end setUp()


    /**
     * Build a fluent IQueryBuilder mock that stubs the full query chain used by
     * HealthController::index(): select / from / innerJoin / where / andWhere /
     * createFunction / createNamedParameter / expr() / executeQuery.
     *
     * @param  IResult|\PHPUnit\Framework\MockObject\MockObject|null $result
     *         The IResult to return from executeQuery(). Pass null to make it throw.
     * @param  \Throwable|null                                       $exception
     *         When non-null, executeQuery() throws this instead.
     * @return IQueryBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildQbMock($result = null, ?\Throwable $exception = null)
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('createFunction')->willReturn('COUNT(*) AS cnt');
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);

        if ($exception !== null) {
            $qb->method('executeQuery')->willThrowException($exception);
        } elseif ($result !== null) {
            $qb->method('executeQuery')->willReturn($result);
        }

        return $qb;
    }//end buildQbMock()


    /**
     * Test that healthy database returns ok status.
     *
     * @return void
     */
    public function testHealthyDatabaseReturnsOk(): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->buildQbMock($result);

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('ok', $data['checks']['database']);
        $this->assertSame('ok', $data['checks']['sources_table']);

    }//end testHealthyDatabaseReturnsOk()


    /**
     * Test that database failure returns error status.
     *
     * HealthController::index() issues two separate queries (database check +
     * sources_table check). When only the first query fails the status is 'error'.
     * We return a throwing QB for the first call and a succeeding QB for the
     * second call so we can assert 'error' rather than the 'degraded' override
     * that happens when BOTH queries throw.
     *
     * @return void
     */
    public function testDatabaseFailureReturnsError(): void
    {
        $result  = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);

        $qbFail    = $this->buildQbMock(null, new \Exception('Connection refused'));
        $qbSuccess = $this->buildQbMock($result);

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($qbFail, $qbSuccess);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame('error', $data['checks']['database']);

    }//end testDatabaseFailureReturnsError()


}//end class
