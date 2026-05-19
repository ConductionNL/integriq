<?php
/**
 * Unit tests for DSOParserService.
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

use OCA\OpenConnector\Service\DSOParserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO payload parser service.
 */
class DSOParserServiceTest extends TestCase
{

    /**
     * @var DSOParserService
     */
    private DSOParserService $parser;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logger       = $this->createMock(LoggerInterface::class);
        $this->parser = new DSOParserService($logger);

    }//end setUp()


    /**
     * Test that a valid BSN passes the 11-proef.
     *
     * @return void
     */
    public function testValidBSNPassesElfProef(): void
    {
        // 999993653 is a well-known test BSN.
        $this->assertTrue($this->parser->validateBSN('999993653'));

    }//end testValidBSNPassesElfProef()


    /**
     * Test that an invalid BSN fails the 11-proef.
     *
     * @return void
     */
    public function testInvalidBSNFailsElfProef(): void
    {
        $this->assertFalse($this->parser->validateBSN('123456789'));

    }//end testInvalidBSNFailsElfProef()


    /**
     * Test that a non-numeric BSN fails validation.
     *
     * @return void
     */
    public function testNonNumericBSNFails(): void
    {
        $this->assertFalse($this->parser->validateBSN('abcdefghi'));

    }//end testNonNumericBSNFails()


    /**
     * Test that a valid ISO date passes validation.
     *
     * @return void
     */
    public function testValidISODatePasses(): void
    {
        $this->assertTrue($this->parser->validateISODate('2024-01-15'));
        $this->assertTrue($this->parser->validateISODate('2024-01-15T10:30:00'));

    }//end testValidISODatePasses()


    /**
     * Test that an invalid date format fails validation.
     *
     * @return void
     */
    public function testInvalidDateFormatFails(): void
    {
        $this->assertFalse($this->parser->validateISODate('15-01-2024'));
        $this->assertFalse($this->parser->validateISODate('not-a-date'));

    }//end testInvalidDateFormatFails()


    /**
     * Test that a valid payload passes validation.
     *
     * @return void
     */
    public function testValidPayloadPassesValidation(): void
    {
        $payload = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['bsn' => '999993653', 'naam' => 'Test'],
            'locatie'         => ['bagAdres' => ['postcode' => '1234AB']],
            'activiteiten'    => [['code' => 'bouwen-01', 'omschrijving' => 'Bouwen']],
        ];

        $errors = $this->parser->validatePayload($payload);
        $this->assertEmpty($errors);

    }//end testValidPayloadPassesValidation()


    /**
     * Test that missing required fields produce errors.
     *
     * @return void
     */
    public function testMissingRequiredFieldsProduceErrors(): void
    {
        $payload = [];

        $errors = $this->parser->validatePayload($payload);

        $this->assertNotEmpty($errors);

        $fieldNames = array_column($errors, 'field');
        $this->assertContains('verzoekId', $fieldNames);
        $this->assertContains('type', $fieldNames);
        $this->assertContains('indieningsdatum', $fieldNames);
        $this->assertContains('aanvrager', $fieldNames);
        $this->assertContains('locatie', $fieldNames);
        $this->assertContains('activiteiten', $fieldNames);

    }//end testMissingRequiredFieldsProduceErrors()


    /**
     * Test that an invalid type produces an error.
     *
     * @return void
     */
    public function testInvalidTypeProducesError(): void
    {
        $payload = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'ongeldig',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['naam' => 'Test'],
            'locatie'         => ['bagAdres' => []],
            'activiteiten'    => [['code' => 'bouwen-01']],
        ];

        $errors     = $this->parser->validatePayload($payload);
        $fieldNames = array_column($errors, 'field');
        $this->assertContains('type', $fieldNames);

    }//end testInvalidTypeProducesError()


    /**
     * Test that an invalid BSN produces an error.
     *
     * @return void
     */
    public function testInvalidBSNInPayloadProducesError(): void
    {
        $payload = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['bsn' => '123456789', 'naam' => 'Test'],
            'locatie'         => ['bagAdres' => []],
            'activiteiten'    => [['code' => 'bouwen-01']],
        ];

        $errors     = $this->parser->validatePayload($payload);
        $fieldNames = array_column($errors, 'field');
        $this->assertContains('aanvrager.bsn', $fieldNames);

    }//end testInvalidBSNInPayloadProducesError()


    /**
     * Test that parseVerzoek extracts all fields.
     *
     * @return void
     */
    public function testParseVerzoekExtractsAllFields(): void
    {
        $payload = [
            'verzoekId'       => 'dso-12345',
            'bronorganisatie' => '00000001234567890000',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['bsn' => '999993653', 'naam' => 'Jansen'],
            'locatie'         => ['bagAdres' => ['postcode' => '1234AB', 'huisnummer' => '10']],
            'activiteiten'    => [['code' => 'bouwen-01', 'omschrijving' => 'Bouwen']],
            'bouwkosten'      => '250000',
        ];

        $verzoek = $this->parser->parseVerzoek($payload);

        $this->assertSame('dso-12345', $verzoek['verzoekId']);
        $this->assertSame('aanvraag', $verzoek['type']);
        $this->assertSame('ontvangen', $verzoek['status']);
        $this->assertSame(250000.0, $verzoek['bouwkosten']);
        $this->assertSame('999993653', $verzoek['aanvrager']['bsn']);
        $this->assertCount(1, $verzoek['activiteiten']);
        $this->assertSame('bouwen-01', $verzoek['activiteiten'][0]['code']);

    }//end testParseVerzoekExtractsAllFields()


    /**
     * Test that GML point conversion works.
     *
     * @return void
     */
    public function testParseLocatieConvertsGMLPoint(): void
    {
        $payload = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => [],
            'locatie'         => [
                'gmlGeometrie' => '<gml:Point><gml:pos>52.370216 4.895168</gml:pos></gml:Point>',
            ],
            'activiteiten'    => [],
        ];

        $verzoek = $this->parser->parseVerzoek($payload);

        $this->assertNotNull($verzoek['locatie']['geometrie']);
        $this->assertSame('Point', $verzoek['locatie']['geometrie']['type']);
        $this->assertEqualsWithDelta(4.895168, $verzoek['locatie']['geometrie']['coordinates'][0], 0.0001);
        $this->assertEqualsWithDelta(52.370216, $verzoek['locatie']['geometrie']['coordinates'][1], 0.0001);

    }//end testParseLocatieConvertsGMLPoint()


}//end class
