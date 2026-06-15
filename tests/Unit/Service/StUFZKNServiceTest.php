<?php

/**
 * Unit tests for StUFZKNService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\StUFXMLBuilder;
use OCA\OpenConnector\Service\StUFZKNService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the StUF-ZKN zaak management service.
 *
 * Verifies zakLk01 (create/update) and zakLv01/zakLa01 request handling.
 */
class StUFZKNServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var StUFZKNService
     */
    private StUFZKNService $service;

    /**
     * Mock OR object service.
     *
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * Mock XML builder.
     *
     * @var StUFXMLBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private $xmlBuilder;

    /**
     * Default stuurgegevens config.
     *
     * @var array
     */
    private array $stuurgegevens;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logger = $this->createMock(originalClassName: LoggerInterface::class);
        $this->orObjectService = $this->createMock(originalClassName: ORObjectService::class);
        $this->xmlBuilder      = $this->createMock(originalClassName: StUFXMLBuilder::class);

        $this->service = new StUFZKNService(
            logger: $logger,
            orObjectService: $this->orObjectService,
            xmlBuilder: $this->xmlBuilder
        );

        $this->stuurgegevens = [
            'zenderOrganisatie'    => '001122334',
            'zenderApplicatie'     => 'OpenConnector',
            'ontvangerOrganisatie' => '998877665',
        ];

    }//end setUp()

    /**
     * Test that malformed XML in zakLk01 returns a Fo03 fault.
     *
     * @return void
     */
    public function testHandleZakLk01ReturnsFo03ForMalformedXml(): void
    {
        $this->xmlBuilder->expects($this->once())
            ->method('buildFo03')
            ->with(
                code: 'StUF046',
                omschrijving: $this->stringContains(string: 'Malformed XML'),
                stuurgegevens: $this->stuurgegevens
            )
            ->willReturn('<fault/>');

        $result = $this->service->handleZakLk01(
            soapXml: 'not-valid-xml',
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<fault/>', actual: $result);

    }//end testHandleZakLk01ReturnsFo03ForMalformedXml()

    /**
     * Test that zakLk01 without zaaktype returns Fo03.
     *
     * @return void
     */
    public function testHandleZakLk01WithoutZaaktypeReturnsFo03(): void
    {
        $soapXml = $this->buildZakLk01Xml(
            zaakidentificatie: 'ZAAK-001',
            omschrijving: 'Test',
            zaaktype: ''
        );

        $this->xmlBuilder->expects($this->once())
            ->method('buildFo03')
            ->with(
                code: 'StUF050',
                omschrijving: $this->stringContains(string: 'zaaktype'),
                stuurgegevens: $this->anything()
            )
            ->willReturn('<fo03fault/>');

        $result = $this->service->handleZakLk01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<fo03fault/>', actual: $result);

    }//end testHandleZakLk01WithoutZaaktypeReturnsFo03()

    /**
     * Test that a valid zakLk01 creates a zaak and returns Bv03.
     *
     * @return void
     */
    public function testHandleZakLk01CreatesZaakAndReturnsBv03(): void
    {
        $soapXml = $this->buildZakLk01Xml(
            zaakidentificatie: 'ZAAK-2024-001',
            omschrijving: 'Omgevingsvergunning',
            zaaktype: 'omgevingsvergunning'
        );

        $this->orObjectService->expects($this->atLeastOnce())
            ->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $savedEntity = ObjectServiceMockBuilder::objectEntity(
            test: $this,
            body: ['zaakidentificatie' => 'ZAAK-2024-001']
        );
        $this->orObjectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($savedEntity);

        $this->xmlBuilder->expects($this->once())
            ->method('buildBv03')
            ->with(
                zaakIdentificatie: 'ZAAK-2024-001',
                stuurgegevens: $this->anything()
            )
            ->willReturn('<Bv03/>');

        $result = $this->service->handleZakLk01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<Bv03/>', actual: $result);

    }//end testHandleZakLk01CreatesZaakAndReturnsBv03()

    /**
     * Test that a zakLv01 query returns zakLa01 response.
     *
     * @return void
     */
    public function testHandleZakLv01ReturnsZakLa01(): void
    {
        $soapXml = $this->buildZakLv01Xml(zaakidentificatie: 'ZAAK-2024-001');

        $zaak = [
            'zaakidentificatie' => 'ZAAK-2024-001',
            'omschrijving'      => 'Test zaak.',
        ];

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [$zaak], 'total' => 1]);

        $this->xmlBuilder->expects($this->once())
            ->method('buildZakLa01')
            ->willReturn('<zakLa01/>');

        $result = $this->service->handleZakLv01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<zakLa01/>', actual: $result);

    }//end testHandleZakLv01ReturnsZakLa01()

    /**
     * Test that malformed XML in zakLv01 returns a Fo03 fault.
     *
     * @return void
     */
    public function testHandleZakLv01ReturnsFo03ForMalformedXml(): void
    {
        $this->xmlBuilder->expects($this->once())
            ->method('buildFo03')
            ->willReturn('<fault/>');

        $result = $this->service->handleZakLv01(
            soapXml: 'invalid-xml',
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<fault/>', actual: $result);

    }//end testHandleZakLv01ReturnsFo03ForMalformedXml()

    /**
     * Build a minimal zakLk01 SOAP message.
     *
     * @param string $zaakidentificatie The zaak identifier.
     * @param string $omschrijving      The zaak description.
     * @param string $zaaktype          The zaak type.
     *
     * @return string The SOAP XML string.
     */
    private function buildZakLk01Xml(string $zaakidentificatie, string $omschrijving, string $zaaktype): string
    {
        if ($zaaktype !== '') {
            $zaaktypeXml = '<ZKN:zaaktype>'.$zaaktype.'</ZKN:zaaktype>';
        } else {
            $zaaktypeXml = '';
        }

        return '<SOAP-ENV:Envelope'
            .' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310">'
            .'<SOAP-ENV:Body>'
            .'<ZKN:zakLk01>'
            .'<ZKN:object>'
            .'<ZKN:identificatie>'.$zaakidentificatie.'</ZKN:identificatie>'
            .'<ZKN:omschrijving>'.$omschrijving.'</ZKN:omschrijving>'
            .$zaaktypeXml
            .'</ZKN:object>'
            .'</ZKN:zakLk01>'
            .'</SOAP-ENV:Body>'
            .'</SOAP-ENV:Envelope>';

    }//end buildZakLk01Xml()

    /**
     * Build a minimal zakLv01 SOAP query.
     *
     * @param string $zaakidentificatie The zaak identifier to query.
     *
     * @return string The SOAP XML string.
     */
    private function buildZakLv01Xml(string $zaakidentificatie): string
    {
        return '<SOAP-ENV:Envelope'
            .' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310">'
            .'<SOAP-ENV:Body>'
            .'<ZKN:zakLv01>'
            .'<ZKN:gelijk>'
            .'<ZKN:identificatie>'.$zaakidentificatie.'</ZKN:identificatie>'
            .'</ZKN:gelijk>'
            .'</ZKN:zakLv01>'
            .'</SOAP-ENV:Body>'
            .'</SOAP-ENV:Envelope>';

    }//end buildZakLv01Xml()
}//end class
