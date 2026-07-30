<?php
/**
 * Unit tests for the endoflife-date-source register fragments — asserting
 * against the REAL `lib/Settings/register.d/*.json` files, not a
 * hand-rebuilt payload, so a future edit that silently drops a required
 * field (e.g. `resultsPosition: "_root"`) fails this test loudly instead of
 * only failing at 3am when the daily cron runs (tasks.md Task 2 / spec.md's
 * "resultsPosition REQUIRED" clause).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-endoflifedate-source-preset-ships-enabled-credentialfree
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-eolproduct-and-eolcycle-schemas-are-declared-in-the-existing-openconnector-register
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-a-curated-starter-set-of-tracked-products-is-seeded-declaratively
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use OCA\OpenConnector\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies both endoflife-date-source register.d fragments: schema
 * declaration + source + eolProduct seeds (endoflife-date-source.json,
 * TC-1..TC-5) and the per-product mapping/synchronization/job triples
 * (endoflife-date-source-cycles.json, TC-6..TC-8).
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization
 */
class EndoflifeDateRegisterFragmentTest extends TestCase
{

    /**
     * Path to the schemas/source/eolProduct fragment under test.
     *
     * @var string
     */
    private const SCHEMAS_FRAGMENT_PATH = __DIR__.'/../../../lib/Settings/register.d/endoflife-date-source.json';

    /**
     * Path to the mapping/synchronization/job fragment under test.
     *
     * @var string
     */
    private const CYCLES_FRAGMENT_PATH = __DIR__.'/../../../lib/Settings/register.d/endoflife-date-source-cycles.json';

    /**
     * The 8 curated product slugs, per design.md Decision 3.
     *
     * @var string[]
     */
    private const CURATED_SLUGS = ['php', 'nodejs', 'python', 'postgresql', 'mysql', 'nextcloud', 'wordpress', 'laravel'];


    /**
     * Decode a fragment file once per call.
     *
     * @param string $path Fragment path.
     *
     * @return array<mixed>
     */
    private function decodeFragment(string $path): array
    {
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $fragment = json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path.' MUST be valid JSON: '.json_last_error_msg());

        return $fragment;

    }//end decodeFragment()


    /**
     * Invoke the private static InitializeRegister::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()


    /**
     * Index the merged `components.objects` list by `@self.schema` then
     * `@self.slug`, mirroring how OpenRegister's ImportHandler ultimately
     * addresses each seed object.
     *
     * @param array<mixed> $objects The merged `components.objects` list.
     *
     * @return array<string, array<string, array<mixed>>> schema => slug => object.
     */
    private function indexBySchemaAndSlug(array $objects): array
    {
        $index = [];
        foreach ($objects as $object) {
            $schema = ($object['@self']['schema'] ?? null);
            $slug   = ($object['@self']['slug'] ?? null);
            if ($schema === null || $slug === null) {
                continue;
            }

            $index[$schema][$slug] = $object;
        }

        return $index;

    }//end indexBySchemaAndSlug()


    /**
     * TC-3 / TC-4: `eolProduct` and `eolCycle` are both declared under
     * register `openconnector`'s schema list, and `eolCycle.properties`
     * covers the brief's required field list.
     *
     * @return void
     */
    public function testSchemasFragmentDeclaresEolProductAndEolCycleWithRequiredFields(): void
    {
        $fragment = $this->decodeFragment(self::SCHEMAS_FRAGMENT_PATH);

        $this->assertSame(
            ['eolProduct', 'eolCycle'],
            $fragment['components']['registers']['openconnector']['schemas'] ?? null
        );

        $schemas = ($fragment['components']['schemas'] ?? []);
        $this->assertArrayHasKey('eolProduct', $schemas);
        $this->assertArrayHasKey('eolCycle', $schemas);

        $productProps = ($schemas['eolProduct']['properties'] ?? []);
        foreach (['slug', 'name', 'category', 'homepage', 'endoflifeUrl'] as $field) {
            $this->assertArrayHasKey($field, $productProps, "eolProduct must declare '$field'");
        }

        $this->assertSame(['slug', 'name'], $schemas['eolProduct']['required'] ?? null);

        $cycleProps = ($schemas['eolCycle']['properties'] ?? []);
        foreach (
            [
                'product',
                'cycle',
                'releaseDate',
                'eol',
                'support',
                'latest',
                'latestReleaseDate',
                'lts',
                'discontinued',
            ] as $field
        ) {
            $this->assertArrayHasKey($field, $cycleProps, "eolCycle must declare '$field' (spec.md required field list)");
        }

        $this->assertSame(['product', 'cycle'], $schemas['eolCycle']['required'] ?? null);

    }//end testSchemasFragmentDeclaresEolProductAndEolCycleWithRequiredFields()


    /**
     * Merging the fragment onto a representative base descriptor attaches
     * eolProduct/eolCycle without redeclaring a disjoint pre-existing
     * schema slug (ADR-037 union-by-key), and does not create a second
     * register (openconnector-register-schema REQ-A-001).
     *
     * @return void
     */
    public function testMergingSchemasFragmentAttachesWithoutRedeclaringExistingSlugsOrANewRegister(): void
    {
        $fragment = $this->decodeFragment(self::SCHEMAS_FRAGMENT_PATH);

        $base = [
            'components' => [
                'registers' => [
                    'openconnector' => [
                        'slug'    => 'openconnector',
                        'schemas' => ['source', 'synchronization', 'mapping', 'job'],
                    ],
                ],
                'schemas'   => [
                    'source' => ['type' => 'object'],
                ],
            ],
        ];

        $merged = $this->merge($base, $fragment);

        $this->assertSame(
            ['source', 'synchronization', 'mapping', 'job', 'eolProduct', 'eolCycle'],
            $merged['components']['registers']['openconnector']['schemas'],
            'existing schema slugs must be preserved with eolProduct/eolCycle appended, not redeclared'
        );

        // Only one register is ever declared/merged onto.
        $this->assertSame(['openconnector'], array_keys($merged['components']['registers']));
        $this->assertSame(['type' => 'object'], $merged['components']['schemas']['source'], 'a disjoint pre-existing schema must not be disturbed');

    }//end testMergingSchemasFragmentAttachesWithoutRedeclaringExistingSlugsOrANewRegister()


    /**
     * TC-1: the seeded `endoflife-date` source materialises enabled,
     * credential-free, with the correct location.
     *
     * @return void
     */
    public function testEndoflifeDateSourceIsEnabledAndCredentialFree(): void
    {
        $fragment = $this->decodeFragment(self::SCHEMAS_FRAGMENT_PATH);
        $index    = $this->indexBySchemaAndSlug($fragment['components']['objects'] ?? []);

        $this->assertArrayHasKey('endoflife-date', $index['source'] ?? [], 'the endoflife-date source object must be seeded');

        $source = $index['source']['endoflife-date'];
        $this->assertSame('https://endoflife.date/api', $source['location'] ?? null);
        $this->assertSame('none', $source['auth'] ?? null);
        $this->assertTrue($source['isEnabled'] ?? null, 'unlike a credentialed preset (kvk/brp), this source must ship enabled');

    }//end testEndoflifeDateSourceIsEnabledAndCredentialFree()


    /**
     * TC-5: all 8 curated `eolProduct` objects are seeded, each with the
     * design.md Seed Data table's field values.
     *
     * @return void
     */
    public function testAllEightCuratedEolProductObjectsAreSeeded(): void
    {
        $fragment = $this->decodeFragment(self::SCHEMAS_FRAGMENT_PATH);
        $index    = $this->indexBySchemaAndSlug($fragment['components']['objects'] ?? []);

        $products = ($index['eolProduct'] ?? []);
        $this->assertSame(
            self::CURATED_SLUGS,
            array_keys($products),
            'exactly the 8 curated products, in design.md order, must be seeded'
        );

        foreach ($products as $slug => $product) {
            $this->assertSame($slug, $product['slug'] ?? null);
            $this->assertNotEmpty($product['name'] ?? '', "eolProduct '$slug' must have a name");
            $this->assertNotEmpty($product['category'] ?? '', "eolProduct '$slug' must have a category");
            $this->assertStringStartsWith('https://', $product['homepage'] ?? '', "eolProduct '$slug' must have a homepage URL");
            $this->assertSame(
                "https://endoflife.date/{$slug}",
                $product['endoflifeUrl'] ?? null,
                "eolProduct '$slug'.endoflifeUrl must be the product's endoflife.date page"
            );
        }

    }//end testAllEightCuratedEolProductObjectsAreSeeded()


    /**
     * TC-6 / TC-7 / TC-8: every curated product's triple exists with
     * distinct, correctly-wired mapping/synchronization/job objects — most
     * importantly `resultsPosition: "_root"`, which is REQUIRED and easy to
     * silently drop (spec.md Decision 5 / design.md).
     *
     * @return void
     */
    public function testEveryCuratedProductHasAWiredMappingSynchronizationJobTriple(): void
    {
        $fragment = $this->decodeFragment(self::CYCLES_FRAGMENT_PATH);
        $index    = $this->indexBySchemaAndSlug($fragment['components']['objects'] ?? []);

        $this->assertCount(8, ($index['mapping'] ?? []), '8 mapping objects, one per curated product');
        $this->assertCount(8, ($index['synchronization'] ?? []), '8 synchronization objects, one per curated product');
        $this->assertCount(8, ($index['job'] ?? []), '8 job objects, one per curated product');

        $synchronizationIds = [];

        foreach (self::CURATED_SLUGS as $slug) {
            $mappingSlug = "endoflife-date-{$slug}-cycles-mapping";
            $syncSlug    = "endoflife-date-{$slug}-cycles";
            $jobSlug     = "endoflife-date-{$slug}-cycles-sync";

            $this->assertArrayHasKey($mappingSlug, ($index['mapping'] ?? []), "mapping missing for '$slug'");
            $this->assertArrayHasKey($syncSlug, ($index['synchronization'] ?? []), "synchronization missing for '$slug'");
            $this->assertArrayHasKey($jobSlug, ($index['job'] ?? []), "job missing for '$slug'");

            $mapping = $index['mapping'][$mappingSlug];
            $sync    = $index['synchronization'][$syncSlug];
            $job     = $index['job'][$jobSlug];

            // Mapping: literal product slug + required casts.
            $this->assertSame($slug, $mapping['mapping']['product'] ?? null, "mapping '$mappingSlug'.mapping.product must be the literal slug");
            $this->assertSame('cycle', $mapping['mapping']['cycle'] ?? null);
            $this->assertSame('string', $mapping['cast']['eol'] ?? null, "mapping '$mappingSlug' must cast eol to string");
            $this->assertSame('string', $mapping['cast']['support'] ?? null, "mapping '$mappingSlug' must cast support to string");
            $this->assertSame('string', $mapping['cast']['discontinued'] ?? null, "mapping '$mappingSlug' must cast discontinued to string");
            $this->assertFalse($mapping['passThrough'] ?? null);

            // Synchronization: the REQUIRED resultsPosition guard (TC-7),
            // plus the rest of the design.md Seed Data table's shape.
            $this->assertSame('endoflife-date', $sync['sourceId'] ?? null);
            $this->assertSame('api', $sync['sourceType'] ?? null);
            $this->assertSame("/{$slug}.json", $sync['sourceConfig']['endpoint'] ?? null);
            $this->assertSame(
                '_root',
                $sync['sourceConfig']['resultsPosition'] ?? null,
                "synchronization '$syncSlug'.sourceConfig.resultsPosition MUST be exactly \"_root\" — its absence fails every run with \"Cannot determine the position of objects in the return body\""
            );
            $this->assertSame('cycle', $sync['sourceConfig']['idPosition'] ?? null);
            $this->assertEqualsWithDelta(0.5, $sync['sourceConfig']['deletionRatioThreshold'] ?? null, 0.0001, "synchronization '$syncSlug' must raise deletionRatioThreshold to 0.5 (design.md Decision 7)");
            $this->assertSame('register/schema', $sync['targetType'] ?? null);
            $this->assertSame('openconnector/eolCycle', $sync['targetId'] ?? null);
            $this->assertSame($mappingSlug, $sync['sourceTargetMapping'] ?? null);

            // Job: generic dispatch, daily cadence, correct slug-addressed
            // synchronizationId (Task 3 finding — slug resolution confirmed
            // in tasks.md).
            $this->assertSame('OCA\\OpenConnector\\Action\\SynchronizationAction', $job['jobClass'] ?? null);
            $this->assertSame($syncSlug, $job['arguments']['synchronizationId'] ?? null);
            $this->assertSame(86400, $job['interval'] ?? null);
            $this->assertTrue($job['isEnabled'] ?? null);

            $synchronizationIds[] = $syncSlug;
        }//end foreach

        // Identity isolation (discovery.md Finding 4): every product's
        // synchronization slug is distinct — no two curated products could
        // ever share a SynchronizationContract's (synchronizationId,
        // originId) key.
        $this->assertCount(8, array_unique($synchronizationIds), 'every curated product must have its own, distinct synchronizationId');

    }//end testEveryCuratedProductHasAWiredMappingSynchronizationJobTriple()


    /**
     * Merging BOTH fragments in sequence (as SettingsService/InitializeRegister
     * would fold in every register.d/*.json file) concatenates their
     * `components.objects` lists rather than one clobbering the other —
     * the source + 8 eolProduct seeds from the schemas fragment and the 24
     * mapping/synchronization/job seeds from the cycles fragment must all
     * survive together.
     *
     * @return void
     */
    public function testBothFragmentsMergeTogetherWithoutClobberingEachOthersObjects(): void
    {
        $schemasFragment = $this->decodeFragment(self::SCHEMAS_FRAGMENT_PATH);
        $cyclesFragment   = $this->decodeFragment(self::CYCLES_FRAGMENT_PATH);

        $merged = $this->merge(['components' => ['objects' => []]], $schemasFragment);
        $merged = $this->merge($merged, $cyclesFragment);

        // 1 source + 8 eolProduct + 8 mapping + 8 synchronization + 8 job.
        $this->assertCount(33, $merged['components']['objects']);

        $index = $this->indexBySchemaAndSlug($merged['components']['objects']);
        $this->assertArrayHasKey('endoflife-date', $index['source'] ?? []);
        $this->assertCount(8, $index['eolProduct'] ?? []);
        $this->assertCount(8, $index['mapping'] ?? []);
        $this->assertCount(8, $index['synchronization'] ?? []);
        $this->assertCount(8, $index['job'] ?? []);

    }//end testBothFragmentsMergeTogetherWithoutClobberingEachOthersObjects()


    /**
     * The descriptor file does not declare eolProduct/eolCycle directly —
     * they arrive exclusively via the register.d fragment (mirrors the
     * established HitlApprovalRegisterFragmentTest / EudiRegisterFragmentTest
     * precedent).
     *
     * @return void
     */
    public function testDescriptorFileDoesNotDeclareEolProductOrEolCycleDirectly(): void
    {
        $descriptorPath = __DIR__.'/../../../lib/Settings/openconnector_register.json';
        $descriptor     = json_decode((string) file_get_contents($descriptorPath), true);

        $this->assertArrayNotHasKey('eolProduct', $descriptor['components']['schemas'] ?? []);
        $this->assertArrayNotHasKey('eolCycle', $descriptor['components']['schemas'] ?? []);

    }//end testDescriptorFileDoesNotDeclareEolProductOrEolCycleDirectly()
}//end class
