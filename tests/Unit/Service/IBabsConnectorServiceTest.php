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
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
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

        // IBabsConnectorService constructor signature (2 args): CallService,
        // LoggerInterface. The previous version mocked the legacy SourceMapper
        // and passed it as arg 2 — a pre-existing test bug from before OR
        // cutover, surfaced once #1015 unblocked the suite from crashing at
        // the moment SourceMapper was looked up (OR removed that class).
        $this->callService = $this->createMock(CallService::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $this->service = new IBabsConnectorService(
            $this->callService,
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
        // Real ObjectEntity hydrated with an empty configuration object.
        // Legacy `OCA\OpenConnector\Db\Source` was removed during the OR
        // cutover; the impl now consumes OR's ObjectEntity and reads
        // `configuration` out of the object body (#1015 follow-up to the
        // engine).
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => []],
            'ibabs-source-1'
        );

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
        // Real ObjectEntity hydrated with a configuration that carries an
        // organisatieId. Legacy `Source` removed during the OR cutover.
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['organisatieId' => 'test-123']],
            'ibabs-source-2'
        );

        $result = $this->service->pushVoorstel($source, ['onderwerp' => 'Test voorstel']);

        $this->assertFalse($result['success']);
        $this->assertNull($result['vergaderstukId']);

    }//end testPushVoorstelReturnsPlaceholder()


}//end class
