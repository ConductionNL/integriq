<?php
/**
 * Unit tests for StUFFieldMapper.
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

use OCA\OpenConnector\Service\StUFFieldMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the StUF field mapper service.
 */
class StUFFieldMapperTest extends TestCase
{

    /**
     * @var StUFFieldMapper
     */
    private StUFFieldMapper $mapper;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logger       = $this->createMock(LoggerInterface::class);
        $this->mapper = new StUFFieldMapper($logger);

    }//end setUp()


    /**
     * Test mapping a person to StUF-BG format.
     *
     * @return void
     */
    public function testMapPersonToStUF(): void
    {
        $person = [
            'burgerservicenummer' => '999993653',
            'geslachtsnaam'       => 'Moulin',
            'voornamen'           => 'Suzanne',
            'geboortedatum'       => '1990-05-15',
        ];

        $result = $this->mapper->mapPersonToStUF($person);

        $this->assertSame('999993653', $result['inp.bsn']);
        $this->assertSame('Moulin', $result['geslachtsnaam']);
        $this->assertSame('Suzanne', $result['voornamen']);
        $this->assertSame('19900515', $result['geboortedatum']);

    }//end testMapPersonToStUF()


    /**
     * Test mapping StUF-BG data back to OpenRegister format.
     *
     * @return void
     */
    public function testMapStUFToPerson(): void
    {
        $stufData = [
            'inp.bsn'        => '999993653',
            'geslachtsnaam'  => 'Moulin',
            'voornamen'      => 'Suzanne',
            'geboortedatum'  => '19900515',
        ];

        $result = $this->mapper->mapStUFToPerson($stufData);

        $this->assertSame('999993653', $result['burgerservicenummer']);
        $this->assertSame('Moulin', $result['geslachtsnaam']);
        $this->assertSame('1990-05-15', $result['geboortedatum']);

    }//end testMapStUFToPerson()


    /**
     * Test ISO date to StUF date conversion.
     *
     * @return void
     */
    public function testIsoDateToStUF(): void
    {
        $this->assertSame('19900515', $this->mapper->isoDateToStUF('1990-05-15'));
        $this->assertSame('20240101', $this->mapper->isoDateToStUF('2024-01-01'));

    }//end testIsoDateToStUF()


    /**
     * Test StUF date to ISO date conversion.
     *
     * @return void
     */
    public function testStufDateToISO(): void
    {
        $this->assertSame('1990-05-15', $this->mapper->stufDateToISO('19900515'));
        $this->assertSame('2024-01-01', $this->mapper->stufDateToISO('20240101'));

    }//end testStufDateToISO()


    /**
     * Test address mapping to StUF format.
     *
     * @return void
     */
    public function testMapAddressToStUF(): void
    {
        $address = [
            'straatnaam'  => 'Hoofdstraat',
            'huisnummer'  => '10',
            'postcode'    => '1234AB',
            'woonplaats'  => 'Utrecht',
        ];

        $result = $this->mapper->mapAddressToStUF($address);

        $this->assertSame('Hoofdstraat', $result['gor.straatnaam']);
        $this->assertSame('10', $result['aoa.huisnummer']);
        $this->assertSame('1234AB', $result['aoa.postcode']);
        $this->assertSame('Utrecht', $result['wpl.woonplaatsNaam']);

    }//end testMapAddressToStUF()


    /**
     * Test nested verblijfsadres mapping.
     *
     * @return void
     */
    public function testMapPersonWithVerblijfsadres(): void
    {
        $person = [
            'burgerservicenummer' => '999993653',
            'geslachtsnaam'       => 'Moulin',
            'verblijfsadres'      => [
                'straatnaam'  => 'Hoofdstraat',
                'huisnummer'  => '10',
                'postcode'    => '1234AB',
                'woonplaats'  => 'Utrecht',
            ],
        ];

        $result = $this->mapper->mapPersonToStUF($person);

        $this->assertArrayHasKey('verblijfsadres', $result);
        $this->assertSame('Hoofdstraat', $result['verblijfsadres']['gor.straatnaam']);

    }//end testMapPersonWithVerblijfsadres()


    /**
     * Test custom field mapping.
     *
     * @return void
     */
    public function testCustomFieldMapping(): void
    {
        $person = [
            'achternaam' => 'Jansen',
        ];

        $customMapping = [
            'achternaam' => 'geslachtsnaam',
        ];

        $result = $this->mapper->mapPersonToStUF($person, $customMapping);

        $this->assertSame('Jansen', $result['geslachtsnaam']);

    }//end testCustomFieldMapping()


    /**
     * Test invalid ISO date returns unchanged.
     *
     * @return void
     */
    public function testInvalidIsoDateReturnsUnchanged(): void
    {
        $this->assertSame('not-a-date', $this->mapper->isoDateToStUF('not-a-date'));

    }//end testInvalidIsoDateReturnsUnchanged()


    /**
     * Test invalid StUF date returns unchanged.
     *
     * @return void
     */
    public function testInvalidStUFDateReturnsUnchanged(): void
    {
        $this->assertSame('notadate', $this->mapper->stufDateToISO('notadate'));

    }//end testInvalidStUFDateReturnsUnchanged()


}//end class
