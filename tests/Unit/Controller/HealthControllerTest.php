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
use OCP\IDBConnection;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
     * Test that healthy database returns ok status.
     *
     * @return void
     */
    public function testHealthyDatabaseReturnsOk(): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');
        $qb->method('executeQuery')->willReturn($result);

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
     * @return void
     */
    public function testDatabaseFailureReturnsError(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');
        $qb->method('executeQuery')->willThrowException(new \Exception('Connection refused'));

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame('error', $data['checks']['database']);

    }//end testDatabaseFailureReturnsError()


}//end class
