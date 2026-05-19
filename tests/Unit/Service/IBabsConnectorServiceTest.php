<?php
/**
 * Unit tests for IBabsConnectorService.
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

use OCA\OpenConnector\Service\IBabsConnectorService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the iBabs connector service.
 */
class IBabsConnectorServiceTest extends TestCase
{

    /**
     * @var IBabsConnectorService
     */
    private IBabsConnectorService $service;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->callService = $this->createMock(CallService::class);
        $sourceMapper      = $this->createMock(SourceMapper::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $this->service = new IBabsConnectorService(
            $this->callService,
            $sourceMapper,
            $logger
        );

    }//end setUp()


    /**
     * Test that besluit status mapping works correctly.
     *
     * @return void
     */
    public function testMapBesluitStatusAangenomen(): void
    {
        $result = $this->service->mapBesluitStatus('aangenomen');
        $this->assertSame('Besluit: aangenomen', $result);

    }//end testMapBesluitStatusAangenomen()


    /**
     * Test that besluit status mapping handles verworpen.
     *
     * @return void
     */
    public function testMapBesluitStatusVerworpen(): void
    {
        $result = $this->service->mapBesluitStatus('verworpen');
        $this->assertSame('Besluit: verworpen', $result);

    }//end testMapBesluitStatusVerworpen()


    /**
     * Test that besluit status mapping handles aangehouden.
     *
     * @return void
     */
    public function testMapBesluitStatusAangehouden(): void
    {
        $result = $this->service->mapBesluitStatus('aangehouden');
        $this->assertSame('Besluit: aangehouden', $result);

    }//end testMapBesluitStatusAangehouden()


    /**
     * Test that unknown besluit status returns onbekend.
     *
     * @return void
     */
    public function testMapBesluitStatusUnknown(): void
    {
        $result = $this->service->mapBesluitStatus('unknown-status');
        $this->assertSame('Besluit: onbekend', $result);

    }//end testMapBesluitStatusUnknown()


    /**
     * Test that test connection fails without organisatieId.
     *
     * @return void
     */
    public function testTestConnectionFailsWithoutOrganisatieId(): void
    {
        $source = $this->createMock(Source::class);
        $source->method('getConfiguration')->willReturn('{}');

        $result = $this->service->testConnection($source);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Organisation ID', $result['message']);

    }//end testTestConnectionFailsWithoutOrganisatieId()


    /**
     * Test that push voorstel returns not-implemented placeholder.
     *
     * @return void
     */
    public function testPushVoorstelReturnsPlaceholder(): void
    {
        $source = $this->createMock(Source::class);
        $source->method('getConfiguration')->willReturn('{"organisatieId": "test-123"}');

        $result = $this->service->pushVoorstel($source, ['onderwerp' => 'Test voorstel']);

        $this->assertFalse($result['success']);
        $this->assertNull($result['vergaderstukId']);

    }//end testPushVoorstelReturnsPlaceholder()


}//end class
