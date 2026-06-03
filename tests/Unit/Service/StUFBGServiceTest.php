<?php

/**
 * Unit tests for StUFBGService.
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

use OCA\OpenConnector\Service\StUFBGService;
use OCA\OpenConnector\Service\StUFFieldMapper;
use OCA\OpenConnector\Service\StUFXMLBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the StUF-BG inbound/outbound service.
 *
 * Verifies npsLv01/npsLa01 and adrLv01/adrLa01 request handling.
 */
class StUFBGServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var StUFBGService
     */
    private StUFBGService $service;

    /**
     * Mock OR object service.
     *
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * Mock field mapper.
     *
     * @var StUFFieldMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fieldMapper;

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
        $this->fieldMapper     = $this->createMock(originalClassName: StUFFieldMapper::class);
        $this->xmlBuilder      = $this->createMock(originalClassName: StUFXMLBuilder::class);

        $this->service = new StUFBGService(
            logger: $logger,
            orObjectService: $this->orObjectService,
            fieldMapper: $this->fieldMapper,
            xmlBuilder: $this->xmlBuilder
        );

        $this->stuurgegevens = [
            'zenderOrganisatie'    => '001122334',
            'zenderApplicatie'     => 'OpenConnector',
            'ontvangerOrganisatie' => '998877665',
        ];

    }//end setUp()

    /**
     * Test that malformed XML returns a Fo01 fault response.
     *
     * @return void
     */
    public function testHandleNpsLv01ReturnsFo01ForMalformedXml(): void
    {
        $this->xmlBuilder->expects($this->once())
            ->method('buildFo01')
            ->with(
                code: 'StUF046',
                omschrijving: $this->stringContains(string: 'Malformed XML'),
                stuurgegevens: $this->stuurgegevens
            )
            ->willReturn('<SOAP-ENV:Envelope>fault</SOAP-ENV:Envelope>');

        $result = $this->service->handleNpsLv01(
            soapXml: 'not-valid-xml',
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<SOAP-ENV:Envelope>fault</SOAP-ENV:Envelope>', actual: $result);

    }//end testHandleNpsLv01ReturnsFo01ForMalformedXml()

    /**
     * Test that a valid npsLv01 with BSN queries OpenRegister and returns npsLa01.
     *
     * @return void
     */
    public function testHandleNpsLv01WithBsnQueriesOpenRegister(): void
    {
        $soapXml = $this->buildNpsLv01Xml(bsn: '999993653');

        $personData = [
            'burgerservicenummer' => '999993653',
            'geslachtsnaam'       => 'Moulin',
            'voornamen'           => 'Suzanne',
        ];

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [$personData], 'total' => 1]);

        $this->fieldMapper->expects($this->once())
            ->method('mapPersonToStUF')
            ->with(person: $personData)
            ->willReturn(['inp.bsn' => '999993653', 'geslachtsnaam' => 'Moulin']);

        $this->xmlBuilder->expects($this->once())
            ->method('buildNpsLa01')
            ->willReturn('<npsLa01/>');

        $result = $this->service->handleNpsLv01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<npsLa01/>', actual: $result);

    }//end testHandleNpsLv01WithBsnQueriesOpenRegister()

    /**
     * Test that a npsLv01 with no search criteria returns a Fo01 fault.
     *
     * @return void
     */
    public function testHandleNpsLv01WithNoCriteriaReturnsFo01(): void
    {
        $soapXml = $this->buildNpsLv01XmlEmpty();

        $this->xmlBuilder->expects($this->once())
            ->method('buildFo01')
            ->willReturn('<fault/>');

        $result = $this->service->handleNpsLv01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<fault/>', actual: $result);

    }//end testHandleNpsLv01WithNoCriteriaReturnsFo01()

    /**
     * Test that a BSN not found in OpenRegister returns empty npsLa01.
     *
     * @return void
     */
    public function testHandleNpsLv01WithBsnNotFoundReturnsEmptyResponse(): void
    {
        $soapXml = $this->buildNpsLv01Xml(bsn: '000000000');

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->xmlBuilder->expects($this->once())
            ->method('buildNpsLa01')
            ->with(
                persons: [],
                stuurgegevens: $this->anything(),
                scope: $this->anything()
            )
            ->willReturn('<npsLa01Empty/>');

        $result = $this->service->handleNpsLv01(
            soapXml: $soapXml,
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<npsLa01Empty/>', actual: $result);

    }//end testHandleNpsLv01WithBsnNotFoundReturnsEmptyResponse()

    /**
     * Test adrLv01 handler returns Fo01 for malformed XML.
     *
     * @return void
     */
    public function testHandleAdrLv01ReturnsFo01ForMalformedXml(): void
    {
        $this->xmlBuilder->expects($this->once())
            ->method('buildFo01')
            ->willReturn('<fault/>');

        $result = $this->service->handleAdrLv01(
            soapXml: 'not-xml',
            stuurgegevens: $this->stuurgegevens
        );

        $this->assertSame(expected: '<fault/>', actual: $result);

    }//end testHandleAdrLv01ReturnsFo01ForMalformedXml()

    /**
     * Test parseNpsLa01Response returns empty array for invalid XML.
     *
     * @return void
     */
    public function testParseNpsLa01ResponseWithInvalidXmlReturnsEmptyArray(): void
    {
        $result = $this->service->parseNpsLa01Response(soapXml: 'not-xml');
        $this->assertSame(expected: [], actual: $result);

    }//end testParseNpsLa01ResponseWithInvalidXmlReturnsEmptyArray()

    /**
     * Build a minimal npsLv01 SOAP request with the given BSN.
     *
     * @param string $bsn The BSN to query.
     *
     * @return string The SOAP XML string.
     */
    private function buildNpsLv01Xml(string $bsn): string
    {
        return '<SOAP-ENV:Envelope'
            .' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310">'
            .'<SOAP-ENV:Body>'
            .'<BG:npsLv01>'
            .'<StUF:stuurgegevens>'
            .'<StUF:referentienummer>REF-001</StUF:referentienummer>'
            .'</StUF:stuurgegevens>'
            .'<BG:gelijk>'
            .'<BG:inp.bsn>'.$bsn.'</BG:inp.bsn>'
            .'</BG:gelijk>'
            .'</BG:npsLv01>'
            .'</SOAP-ENV:Body>'
            .'</SOAP-ENV:Envelope>';

    }//end buildNpsLv01Xml()

    /**
     * Build a minimal npsLv01 SOAP request with no criteria.
     *
     * @return string The SOAP XML string.
     */
    private function buildNpsLv01XmlEmpty(): string
    {
        return '<SOAP-ENV:Envelope'
            .' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310">'
            .'<SOAP-ENV:Body>'
            .'<BG:npsLv01>'
            .'<BG:gelijk/>'
            .'</BG:npsLv01>'
            .'</SOAP-ENV:Body>'
            .'</SOAP-ENV:Envelope>';

    }//end buildNpsLv01XmlEmpty()
}//end class
