<?php

/**
 * Unit tests for DSOAdapterService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\DSOAdapterService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the main DSO adapter service.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 */
class DSOAdapterServiceTest extends TestCase
{

    /**
     * @var DSOAdapterService
     */
    private DSOAdapterService $adapter;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logger        = $this->createMock(LoggerInterface::class);
        $clientService = $this->createMock(IClientService::class);
        $appConfig     = $this->createMock(IAppConfig::class);

        $this->adapter = new DSOAdapterService(
            logger: $logger,
            clientService: $clientService,
            appConfig: $appConfig
        );

    }//end setUp()


    /**
     * Test that melding type routes to handleMelding.
     *
     * @return void
     */
    public function testProcessVerzoekRoutesMeldingToHandleMelding(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-melding-001',
            'type'      => 'melding',
        ];

        $result = $this->adapter->processVerzoek(verzoek: $verzoek);

        $this->assertArrayHasKey('zaaktypeIdentificatie', $result);
        $this->assertSame('melding', $result['zaaktypeIdentificatie']);

    }//end testProcessVerzoekRoutesMeldingToHandleMelding()


    /**
     * Test that informatieverzoek type routes correctly.
     *
     * @return void
     */
    public function testProcessVerzoekRoutesInformatieverzoek(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-info-001',
            'type'      => 'informatieverzoek',
        ];

        $result = $this->adapter->processVerzoek(verzoek: $verzoek);

        $this->assertSame('informatieverzoek', $result['zaaktypeIdentificatie']);

    }//end testProcessVerzoekRoutesInformatieverzoek()


    /**
     * Test that vooroverleg type routes correctly.
     *
     * @return void
     */
    public function testProcessVerzoekRoutesVooroverleg(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-voor-001',
            'type'      => 'vooroverleg',
        ];

        $result = $this->adapter->processVerzoek(verzoek: $verzoek);

        $this->assertSame('vooroverleg', $result['zaaktypeIdentificatie']);

    }//end testProcessVerzoekRoutesVooroverleg()


    /**
     * Test that aanvraag type routes to default handler.
     *
     * @return void
     */
    public function testProcessVerzoekRoutesAanvraag(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-aanvraag-001',
            'type'      => 'aanvraag',
        ];

        $result = $this->adapter->processVerzoek(verzoek: $verzoek);

        $this->assertSame('aanvraag', $result['zaaktypeIdentificatie']);

    }//end testProcessVerzoekRoutesAanvraag()


    /**
     * Test activiteiten mapping with a known code.
     *
     * @return void
     */
    public function testMapActiviteitenToZaaktypenMapsKnownCode(): void
    {
        $activiteiten = [
            ['code' => 'bouwen-01', 'omschrijving' => 'Bouwen'],
        ];

        $mappingTable = [
            'bouwen-01' => [
                'zaaktypeIdentificatie' => 'ZAAKTYPE-BOUWEN-2024',
                'samenloopStrategie'    => 'deelzaken',
            ],
        ];

        $result = $this->adapter->mapActiviteitenToZaaktypen(
            activiteiten: $activiteiten,
            mappingTable: $mappingTable
        );

        $this->assertCount(1, $result['mapped']);
        $this->assertCount(0, $result['unmapped']);
        $this->assertSame('ZAAKTYPE-BOUWEN-2024', $result['mapped'][0]['zaaktypeIdentificatie']);

    }//end testMapActiviteitenToZaaktypenMapsKnownCode()


    /**
     * Test activiteiten mapping with an unknown code.
     *
     * @return void
     */
    public function testMapActiviteitenToZaaktypenReturnsUnmappedForUnknownCode(): void
    {
        $activiteiten = [
            ['code' => 'onbekend-activiteit-2025'],
        ];

        $result = $this->adapter->mapActiviteitenToZaaktypen(
            activiteiten: $activiteiten,
            mappingTable: []
        );

        $this->assertCount(0, $result['mapped']);
        $this->assertCount(1, $result['unmapped']);
        $this->assertFalse($result['unmapped'][0]['mapped']);

    }//end testMapActiviteitenToZaaktypenReturnsUnmappedForUnknownCode()


    /**
     * Test default mappings contain 25+ entries.
     *
     * @return void
     */
    public function testGetDefaultMappingsReturns25PlusMappings(): void
    {
        $mappings = $this->adapter->getDefaultMappings();

        $this->assertGreaterThanOrEqual(25, count($mappings));

        // Each entry must have required fields.
        foreach ($mappings as $mapping) {
            $this->assertArrayHasKey('dsoActiviteitCode', $mapping);
            $this->assertArrayHasKey('zaaktypeIdentificatie', $mapping);
            $this->assertArrayHasKey('samenloopStrategie', $mapping);
            $this->assertArrayHasKey('isActief', $mapping);
        }

    }//end testGetDefaultMappingsReturns25PlusMappings()


    /**
     * Test samenloop strategy returns deelzaken by default.
     *
     * @return void
     */
    public function testDetermineSamenloopStrategyDefaultsToDeelzaken(): void
    {
        $result = $this->adapter->determineSamenloopStrategy(mappedActiviteiten: []);

        $this->assertSame('deelzaken', $result);

    }//end testDetermineSamenloopStrategyDefaultsToDeelzaken()


    /**
     * Test samenloop strategy returns gecombineerd when all activiteiten use that strategy.
     *
     * @return void
     */
    public function testDetermineSamenloopStrategyReturnsGecombineerdWhenAllMatch(): void
    {
        $activiteiten = [
            ['samenloopStrategie' => 'gecombineerd'],
            ['samenloopStrategie' => 'gecombineerd'],
        ];

        $result = $this->adapter->determineSamenloopStrategy(
            mappedActiviteiten: $activiteiten
        );

        $this->assertSame('gecombineerd', $result);

    }//end testDetermineSamenloopStrategyReturnsGecombineerdWhenAllMatch()


    /**
     * Test samenloop strategy returns deelzaken when any activiteit is deelzaken.
     *
     * @return void
     */
    public function testDetermineSamenloopStrategyReturnsDeelzakenWhenMixed(): void
    {
        $activiteiten = [
            ['samenloopStrategie' => 'gecombineerd'],
            ['samenloopStrategie' => 'deelzaken'],
        ];

        $result = $this->adapter->determineSamenloopStrategy(
            mappedActiviteiten: $activiteiten
        );

        $this->assertSame('deelzaken', $result);

    }//end testDetermineSamenloopStrategyReturnsDeelzakenWhenMixed()


    /**
     * Test handleUnmappedActiviteit creates triage zaak.
     *
     * @return void
     */
    public function testHandleUnmappedActiviteitCreatesTriage(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-triage-001',
            'type'      => 'aanvraag',
        ];

        $result = $this->adapter->handleUnmappedActiviteit(
            verzoek: $verzoek,
            activiteitCode: 'onbekend-activiteit-2025'
        );

        $this->assertArrayHasKey('zaakId', $result);
        $this->assertSame('triage', $result['status']);

    }//end testHandleUnmappedActiviteitCreatesTriage()


    /**
     * Test createZaak includes all verzoek fields.
     *
     * @return void
     */
    public function testCreateZaakMapsAllVerzoekFields(): void
    {
        $verzoek = [
            'verzoekId'       => 'dso-zaak-001',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['naam' => 'Jansen'],
            'locatie'         => ['bagAdres' => ['postcode' => '1234AB']],
            'activiteiten'    => [['code' => 'bouwen-01']],
            'bronorganisatie' => '00000001234567890000',
        ];

        $zaak = $this->adapter->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'ZAAKTYPE-BOUWEN-2024',
            strategy: 'single'
        );

        $this->assertArrayHasKey('id', $zaak);
        $this->assertSame('ZAAKTYPE-BOUWEN-2024', $zaak['zaaktypeIdentificatie']);
        $this->assertSame('ontvangen', $zaak['status']);
        $this->assertSame('dso-zaak-001', $zaak['verzoekId']);
        $this->assertSame('Jansen', $zaak['aanvrager']['naam']);
        $this->assertSame('single', $zaak['strategy']);

    }//end testCreateZaakMapsAllVerzoekFields()


    /**
     * Test createHoofdzaakWithDeelzaken creates correct structure.
     *
     * @return void
     */
    public function testCreateHoofdzaakWithDeelzakenCreatesDeelzaken(): void
    {
        $verzoek = [
            'verzoekId' => 'dso-samenloop-001',
            'type'      => 'aanvraag',
        ];

        $mappedActiviteiten = [
            [
                'code'                  => 'bouwen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-BOUWEN-2024',
                'samenloopStrategie'    => 'deelzaken',
            ],
            [
                'code'                  => 'kappen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-KAPPEN-2024',
                'samenloopStrategie'    => 'deelzaken',
            ],
        ];

        $result = $this->adapter->createHoofdzaakWithDeelzaken(
            verzoek: $verzoek,
            mappedActiviteiten: $mappedActiviteiten
        );

        $this->assertArrayHasKey('hoofdzaakId', $result);
        $this->assertArrayHasKey('deelzaakIds', $result);
        $this->assertCount(2, $result['deelzaakIds']);

    }//end testCreateHoofdzaakWithDeelzakenCreatesDeelzaken()


}//end class
