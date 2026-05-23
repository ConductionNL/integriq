<?php
/**
 * Unit tests for MetricsController.
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

use OCA\OpenConnector\Controller\MetricsController;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Prometheus metrics endpoint controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MetricsControllerTest extends TestCase
{

    /**
     * @var MetricsController
     */
    private MetricsController $controller;

    /**
     * @var IConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

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
        $this->config = $this->createMock(IConfig::class);
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new MetricsController(
            'openconnector',
            $request,
            $this->config,
            $this->db,
            $this->logger
        );

    }//end setUp()


    /**
     * Test that index returns a TextPlainResponse.
     *
     * @return void
     */
    public function testIndexReturnsTextPlainResponse(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);

    }//end testIndexReturnsTextPlainResponse()


    /**
     * Test that the response contains the info metric.
     *
     * @return void
     */
    public function testIndexContainsInfoMetric(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('2.1.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('openconnector_info{version="2.1.0"', $body);
        $this->assertStringContainsString('php_version="'.PHP_VERSION.'"', $body);
        $this->assertStringContainsString('nextcloud_version="30.0.0"', $body);

    }//end testIndexContainsInfoMetric()


    /**
     * Test that the response contains the up metric.
     *
     * @return void
     */
    public function testIndexContainsUpMetric(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('openconnector_up 1', $body);

    }//end testIndexContainsUpMetric()


    /**
     * Test that the response contains source metrics.
     *
     * @return void
     */
    public function testIndexContainsSourceMetrics(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('# HELP openconnector_sources_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_sources_total gauge', $body);

    }//end testIndexContainsSourceMetrics()


    /**
     * Test that the response contains endpoint metrics.
     *
     * @return void
     */
    public function testIndexContainsEndpointMetrics(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('# HELP openconnector_endpoints_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_endpoints_total gauge', $body);
        $this->assertStringContainsString('openconnector_endpoints_total 0', $body);

    }//end testIndexContainsEndpointMetrics()


    /**
     * Test that the response contains job metrics.
     *
     * @return void
     */
    public function testIndexContainsJobMetrics(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('# HELP openconnector_jobs_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_jobs_total gauge', $body);
        $this->assertStringContainsString('# HELP openconnector_job_runs_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_job_runs_total counter', $body);

    }//end testIndexContainsJobMetrics()


    /**
     * Test that the response contains mapping and rule metrics.
     *
     * @return void
     */
    public function testIndexContainsMappingRuleMetrics(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('# HELP openconnector_mappings_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_mappings_total gauge', $body);
        $this->assertStringContainsString('# HELP openconnector_rules_total', $body);
        $this->assertStringContainsString('# TYPE openconnector_rules_total gauge', $body);

    }//end testIndexContainsMappingRuleMetrics()


    /**
     * Test that database errors produce zero-value fallbacks.
     *
     * @return void
     */
    public function testDatabaseErrorProducesZeroFallback(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('1.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('30.0.0');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('createFunction')->willReturn('COUNT(*) AS cnt');
        $qb->method('executeQuery')->willThrowException(new \Exception('DB error'));

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $response = $this->controller->index();
        $body     = $response->render();

        // Should still return 200 with zero-value fallbacks.
        $this->assertInstanceOf(TextPlainResponse::class, $response);
        $this->assertStringContainsString('openconnector_sources_total{type="rest"} 0', $body);
        $this->assertStringContainsString('openconnector_calls_total{status="200"} 0', $body);

    }//end testDatabaseErrorProducesZeroFallback()


    /**
     * Test that the default version is 0.0.0 when not configured.
     *
     * @return void
     */
    public function testDefaultVersionIsZero(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('0.0.0');
        $this->config->method('getSystemValueString')
            ->willReturn('0.0.0');

        $this->mockDbForAllCollectors();

        $response = $this->controller->index();
        $body     = $response->render();

        $this->assertStringContainsString('version="0.0.0"', $body);

    }//end testDefaultVersionIsZero()


    /**
     * Mock the database connection for all collector methods.
     *
     * Returns empty results for all grouped queries and 0 for count queries.
     *
     * @return void
     */
    private function mockDbForAllCollectors(): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetchOne')->willReturn('0');
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('createFunction')->willReturn('COUNT(*) AS cnt');
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

    }//end mockDbForAllCollectors()


}//end class
