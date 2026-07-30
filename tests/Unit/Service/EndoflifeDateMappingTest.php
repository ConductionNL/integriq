<?php
/**
 * Unit tests for the endoflife-date-source preset's per-product mapping recipe.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Exercises `MappingService::executeMapping()` against the exact mapping
 * recipe shape seeded per curated product in
 * lib/Settings/register.d/endoflife-date-source-cycles.json — proving the
 * design.md Decision 4/6 field-copy, literal-slug, and cast-to-string
 * behaviour against both a hand-built fixture (per tasks.md Task 4's
 * acceptance criteria) and a REAL response captured live from
 * https://endoflife.date/api (tests/fixtures/endoflifedate/fixture-*.json).
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization
 */
class EndoflifeDateMappingTest extends TestCase
{


    /**
     * @var MappingService
     */
    private MappingService $service;

    /**
     * @var OrObjectService&MockObject
     */
    private OrObjectService $orObjectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = $this->createMock(OrObjectService::class);

        $loader                         = new ArrayLoader([]);
        $callService                    = $this->createMock(CallService::class);
        $fileService                    = $this->createMock(FileService::class);
        $objectService                  = $this->createMock(ObjectService::class);
        $synchronizationContractService = $this->createMock(SynchronizationContractService::class);

        $this->service = new MappingService(
            $loader,
            $callService,
            $fileService,
            $objectService,
            $this->orObjectService,
            $synchronizationContractService,
        );

    }//end setUp()


    /**
     * Build the exact `mapping` array seeded for one curated product's
     * cycles mapping (mirrors
     * lib/Settings/register.d/endoflife-date-source-cycles.json's
     * per-product mapping shape, generated for the given slug).
     *
     * @param string $productSlug The curated product's slug (the mapping's literal `product` value).
     *
     * @return array The mapping recipe array, in the shape `executeMapping()` accepts directly.
     */
    private function buildProductMapping(string $productSlug): array
    {
        return [
            'name'    => "endoflife.date {$productSlug} cycles mapping",
            'mapping' => [
                'product'           => $productSlug,
                'cycle'             => 'cycle',
                'releaseDate'       => 'releaseDate',
                'eol'               => 'eol',
                'support'           => 'support',
                'latest'            => 'latest',
                'latestReleaseDate' => 'latestReleaseDate',
                'lts'               => 'lts',
                'discontinued'      => "{{ discontinued|default('') }}",
            ],
            'cast'        => [
                'eol'          => 'string',
                'support'      => 'string',
                'discontinued' => 'string',
            ],
            'passThrough' => false,
        ];

    }//end buildProductMapping()


    /**
     * GIVEN the seeded python cycles mapping and a realistic fixture cycle
     * payload WHEN executeMapping() runs THEN product/cycle/releaseDate/
     * latest/latestReleaseDate/eol/support are all correct.
     *
     * tasks.md Task 4, acceptance criterion 1.
     *
     * @return void
     */
    public function testPythonMappingCopiesFieldsAndSetsLiteralProduct(): void
    {
        $mapping = $this->buildProductMapping('python');
        $input   = [
            'cycle'             => '3.14',
            'releaseDate'       => '2025-10-07',
            'eol'               => '2030-10-31',
            'support'           => '2027-10-01',
            'latest'            => '3.14.6',
            'latestReleaseDate' => '2026-06-10',
            'lts'               => false,
        ];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('python', $result['product']);
        $this->assertSame('3.14', $result['cycle']);
        $this->assertSame('2025-10-07', $result['releaseDate']);
        $this->assertSame('3.14.6', $result['latest']);
        $this->assertSame('2026-06-10', $result['latestReleaseDate']);
        $this->assertSame('2030-10-31', $result['eol']);
        $this->assertSame('2027-10-01', $result['support']);
        $this->assertFalse($result['lts'], 'lts is left uncast per design.md Decision 6');

    }//end testPythonMappingCopiesFieldsAndSetsLiteralProduct()


    /**
     * GIVEN a fixture cycle payload where `eol` is JSON false (no scheduled
     * EOL) WHEN mapped THEN the output's `eol` is an empty string, not a PHP
     * boolean.
     *
     * tasks.md Task 4, acceptance criterion 2.
     *
     * @return void
     */
    public function testEolFalseCastsToEmptyString(): void
    {
        $mapping = $this->buildProductMapping('php');
        $input   = [
            'cycle'             => '8.5',
            'releaseDate'       => '2025-11-20',
            'eol'               => false,
            'support'           => '2027-12-31',
            'latest'            => '8.5.8',
            'latestReleaseDate' => '2026-07-02',
            'lts'               => false,
        ];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('', $result['eol'], 'JSON false must cast to an empty string, not a PHP boolean');
        $this->assertIsString($result['eol']);

    }//end testEolFalseCastsToEmptyString()


    /**
     * GIVEN a second curated product's mapping (nodejs) WHEN mapped THEN
     * `product` equals that product's own literal slug, not "python".
     *
     * tasks.md Task 4, acceptance criterion 3.
     *
     * @return void
     */
    public function testNodejsMappingUsesItsOwnLiteralProductSlug(): void
    {
        $mapping = $this->buildProductMapping('nodejs');
        $input   = [
            'cycle'             => '25',
            'releaseDate'       => '2025-05-06',
            'eol'               => '2026-06-01',
            'support'           => '2025-10-21',
            'latest'            => '25.0.0',
            'latestReleaseDate' => '2025-05-06',
            'lts'               => false,
        ];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('nodejs', $result['product']);
        $this->assertNotSame('python', $result['product']);

    }//end testNodejsMappingUsesItsOwnLiteralProductSlug()


    /**
     * GIVEN a cycle payload with no `discontinued` key at all (the real,
     * common shape — see the live-captured fixtures below) WHEN mapped
     * THEN `discontinued` is an empty string, NOT the literal word
     * "discontinued" — proving the Twig `{{ discontinued|default('') }}`
     * guard actually prevents the bare-dot-path-copy fallback-to-literal
     * trap described in the mapping's own seed comment.
     *
     * @return void
     */
    public function testMissingDiscontinuedFieldMapsToEmptyStringNotLiteral(): void
    {
        $mapping = $this->buildProductMapping('php');
        $input   = [
            'cycle'             => '8.5',
            'releaseDate'       => '2025-11-20',
            'eol'               => '2029-12-31',
            'support'           => '2027-12-31',
            'latest'            => '8.5.8',
            'latestReleaseDate' => '2026-07-02',
            'lts'               => false,
        ];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('', $result['discontinued']);
        $this->assertNotSame('discontinued', $result['discontinued']);

    }//end testMissingDiscontinuedFieldMapsToEmptyStringNotLiteral()


    /**
     * GIVEN a REAL cycle object captured live from
     * https://endoflife.date/api/php.json (tests/fixtures/endoflifedate/
     * fixture-php.json) WHEN each entry is mapped through the seeded php
     * mapping recipe THEN every entry maps without error and `product`,
     * `cycle`, and `eol` all end up correctly typed.
     *
     * Phase 2 "run the mapping unit test against a REAL captured fixture"
     * requirement.
     *
     * @return void
     */
    public function testRealCapturedPhpFixtureMapsCleanly(): void
    {
        $fixturePath = __DIR__.'/../../fixtures/endoflifedate/fixture-php.json';
        $this->assertFileExists($fixturePath, 'Live-captured php.json fixture must be committed alongside this test.');

        $cycles = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($cycles);
        $this->assertNotEmpty($cycles, 'Fixture must contain at least one real cycle.');

        $mapping = $this->buildProductMapping('php');

        foreach ($cycles as $cycle) {
            $result = $this->service->executeMapping($mapping, $cycle);

            $this->assertSame('php', $result['product']);
            $this->assertSame($cycle['cycle'], $result['cycle']);
            $this->assertIsString($result['eol'], 'eol must always cast to string, even when upstream sends JSON false/true.');
            $this->assertIsString($result['support']);
            $this->assertIsString($result['discontinued']);
        }

    }//end testRealCapturedPhpFixtureMapsCleanly()


    /**
     * GIVEN a REAL cycle list captured live from
     * https://endoflife.date/api/nodejs.json WHEN mapped through the
     * seeded nodejs mapping recipe THEN every entry maps cleanly, including
     * the mixed lts (boolean-or-date) and eol (boolean-or-date, including
     * the historical `eol: true` shape on fully-retired Node.js 1-3)
     * upstream shapes — proving lts is correctly left uncast and eol/
     * support/discontinued never crash the cast step regardless of which
     * of upstream's shapes is present.
     *
     * @return void
     */
    public function testRealCapturedNodejsFixtureHandlesMixedLtsAndEolShapes(): void
    {
        $fixturePath = __DIR__.'/../../fixtures/endoflifedate/fixture-nodejs.json';
        $this->assertFileExists($fixturePath, 'Live-captured nodejs.json fixture must be committed alongside this test.');

        $cycles = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($cycles);
        $this->assertNotEmpty($cycles, 'Fixture must contain at least one real cycle.');

        $mapping = $this->buildProductMapping('nodejs');

        $sawBooleanLts = false;
        $sawStringLts  = false;

        foreach ($cycles as $cycle) {
            $result = $this->service->executeMapping($mapping, $cycle);

            $this->assertSame('nodejs', $result['product']);
            $this->assertSame($cycle['cycle'], $result['cycle']);
            $this->assertIsString($result['eol']);

            if (is_bool($result['lts']) === true) {
                $sawBooleanLts = true;
            } else if (is_string($result['lts']) === true) {
                $sawStringLts = true;
            }
        }

        $this->assertTrue($sawBooleanLts, 'The live nodejs fixture is expected to contain at least one boolean lts value.');
        $this->assertTrue($sawStringLts, 'The live nodejs fixture is expected to contain at least one ISO-date lts value (an LTS cycle).');

    }//end testRealCapturedNodejsFixtureHandlesMixedLtsAndEolShapes()
}//end class
