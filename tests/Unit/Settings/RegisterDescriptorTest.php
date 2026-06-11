<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI guard for chain A (openconnector-register-schema-declaration) — REQ-A-002.
 *
 * Asserts structural integrity of lib/Settings/openconnector_register.json:
 * - All 15 schema slugs are declared in the register
 * - All 15 schemas are defined in components.schemas
 * - Log schemas carry appendOnly/immutable/archival markers
 * - Mutable schemas do NOT carry appendOnly/immutable
 * - FK relations carry $ref and x-openregister-onDelete
 * - sourceId/targetId on synchronization are plain string (no $ref)
 *
 * NOTE: The original REQ-A-002 test used Reflection on OCA\OpenConnector\Db\*
 * entity classes to verify schema completeness. Those entity classes were
 * deleted in chain C (all state now lives in OpenRegister). The Reflection
 * assertions have been removed; the structural JSON checks below remain.
 *
 * Cross-ref: openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-a-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class RegisterDescriptorTest extends TestCase
{
    /**
     * The 16 schema slugs that MUST be declared in the register.
     * Keys are the former entity FQCN (kept for diagnostic messages); values are schema slugs.
     *
     * @var array<string, string>
     */
    private const SCHEMA_SLUGS = [
        'Source'                     => 'source',
        'Consumer'                   => 'consumer',
        'Endpoint'                   => 'endpoint',
        'Event'                      => 'event',
        'EventMessage'               => 'event_message',
        'EventSubscription'          => 'event_subscription',
        'Job'                        => 'job',
        'Mapping'                    => 'mapping',
        'Rule'                       => 'rule',
        'Synchronization'            => 'synchronization',
        'SynchronizationContract'    => 'synchronization_contract',
        'CallLog'                    => 'call_log',
        'JobLog'                     => 'job_log',
        'SynchronizationLog'         => 'synchronization_log',
        'SynchronizationContractLog' => 'synchronization_contract_log',
        // RIS connector sync record — added by ibabs-notubiz-connector spec.
        'RISSyncRecord'              => 'ris_sync_record',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $descriptor;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/lib/Settings/openconnector_register.json';
        $this->assertFileExists($path, 'openconnector_register.json MUST exist at lib/Settings/');

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, 'openconnector_register.json MUST be readable');

        $parsed = json_decode($raw, true);
        $this->assertIsArray($parsed, 'openconnector_register.json MUST parse as valid JSON');
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON parse error: ' . json_last_error_msg());

        $this->descriptor = $parsed;
    }//end setUp()

    public function testRegisterDeclaresAllSchemaSlugs(): void
    {
        $expected = array_values(self::SCHEMA_SLUGS);
        $actual = $this->descriptor['components']['registers']['openconnector']['schemas'] ?? [];

        sort($expected);
        $actualSorted = $actual;
        sort($actualSorted);

        $this->assertSame(
            $expected,
            $actualSorted,
            'register.openconnector.schemas[] MUST list exactly all schema slugs'
        );
    }//end testRegisterDeclaresAllSchemaSlugs()

    public function testAllSchemasAreDefined(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach (self::SCHEMA_SLUGS as $label => $schemaSlug) {
            $this->assertArrayHasKey(
                $schemaSlug,
                $schemas,
                sprintf('Schema "%s" (formerly entity %s) MUST be declared in components.schemas', $schemaSlug, $label)
            );
        }
    }//end testAllSchemasAreDefined()

    /**
     * Each schema MUST declare a non-empty properties block.
     *
     * @dataProvider schemaSlugProvider
     */
    public function testEachSchemaDeclaresSomeProperties(string $schemaSlug): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];
        $schema = $schemas[$schemaSlug] ?? null;
        $this->assertIsArray($schema, sprintf('Schema "%s" MUST be defined', $schemaSlug));

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties, sprintf('Schema "%s" MUST declare a properties block', $schemaSlug));
        $this->assertNotEmpty(
            $properties,
            sprintf('Schema "%s" properties block MUST NOT be empty', $schemaSlug)
        );
    }//end testEachSchemaDeclaresSomeProperties()

    public function testLogSchemasAreAppendOnlyAndImmutable(): void
    {
        $logSchemas = ['call_log', 'job_log', 'synchronization_log', 'synchronization_contract_log'];
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach ($logSchemas as $slug) {
            $schema = $schemas[$slug] ?? null;
            $this->assertNotNull($schema, sprintf('Log schema "%s" MUST exist', $slug));
            $this->assertTrue(
                $schema['appendOnly'] ?? false,
                sprintf('Log schema "%s" MUST set appendOnly: true', $slug)
            );
            $this->assertTrue(
                $schema['immutable'] ?? false,
                sprintf('Log schema "%s" MUST set immutable: true', $slug)
            );
            $this->assertArrayHasKey(
                'x-openregister-archival',
                $schema,
                sprintf('Log schema "%s" MUST carry x-openregister-archival annotation (REQ-A-004)', $slug)
            );
        }
    }//end testLogSchemasAreAppendOnlyAndImmutable()

    public function testMutableSchemasAreNotAppendOnly(): void
    {
        $logSchemas = ['call_log', 'job_log', 'synchronization_log', 'synchronization_contract_log'];
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach ($schemas as $slug => $schema) {
            if (in_array($slug, $logSchemas, true) === true) {
                continue;
            }
            $this->assertFalse(
                $schema['appendOnly'] ?? false,
                sprintf('Mutable schema "%s" MUST NOT set appendOnly: true', $slug)
            );
            $this->assertFalse(
                $schema['immutable'] ?? false,
                sprintf('Mutable schema "%s" MUST NOT set immutable: true', $slug)
            );
        }
    }//end testMutableSchemasAreNotAppendOnly()

    public function testFkRelationsCarryRefAndOnDelete(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        $expectedRelations = [
            ['schema' => 'call_log',      'property' => 'source',                       'target' => 'source',                  'onDelete' => 'SET NULL'],
            ['schema' => 'call_log',      'property' => 'synchronization',              'target' => 'synchronization',         'onDelete' => 'SET NULL'],
            ['schema' => 'event_message', 'property' => 'event',                        'target' => 'event',                   'onDelete' => 'CASCADE'],
            ['schema' => 'event_message', 'property' => 'consumer',                     'target' => 'consumer',                'onDelete' => 'SET NULL'],
            ['schema' => 'event_message', 'property' => 'subscription',                 'target' => 'event_subscription',      'onDelete' => 'CASCADE'],
            ['schema' => 'synchronization_contract_log', 'property' => 'synchronization_contract', 'target' => 'synchronization_contract', 'onDelete' => 'CASCADE'],
        ];

        foreach ($expectedRelations as $rel) {
            $prop = $schemas[$rel['schema']]['properties'][$rel['property']] ?? null;
            $this->assertIsArray(
                $prop,
                sprintf('Relation %s.%s MUST be declared', $rel['schema'], $rel['property'])
            );
            $this->assertSame(
                '#/components/schemas/' . $rel['target'],
                $prop['$ref'] ?? null,
                sprintf('Relation %s.%s MUST $ref %s', $rel['schema'], $rel['property'], $rel['target'])
            );
            $this->assertSame(
                $rel['onDelete'],
                $prop['x-openregister-onDelete'] ?? null,
                sprintf('Relation %s.%s MUST set x-openregister-onDelete=%s', $rel['schema'], $rel['property'], $rel['onDelete'])
            );
        }
    }//end testFkRelationsCarryRefAndOnDelete()

    public function testSynchronizationSourceIdAndTargetIdAreStringWithoutRef(): void
    {
        $schema = $this->descriptor['components']['schemas']['synchronization'] ?? [];
        $sourceId = $schema['properties']['sourceId'] ?? null;
        $targetId = $schema['properties']['targetId'] ?? null;

        $this->assertIsArray($sourceId, 'synchronization.sourceId MUST be declared');
        $this->assertSame('string', $sourceId['type'] ?? null, 'synchronization.sourceId MUST be type:string (REQ-A-006)');
        $this->assertArrayNotHasKey('$ref', $sourceId, 'synchronization.sourceId MUST NOT carry $ref — overloaded format');

        $this->assertIsArray($targetId, 'synchronization.targetId MUST be declared');
        $this->assertSame('string', $targetId['type'] ?? null, 'synchronization.targetId MUST be type:string (REQ-A-006)');
        $this->assertArrayNotHasKey('$ref', $targetId, 'synchronization.targetId MUST NOT carry $ref — overloaded format');
    }//end testSynchronizationSourceIdAndTargetIdAreStringWithoutRef()

    /**
     * @return array<string, array<string>>
     */
    public static function schemaSlugProvider(): array
    {
        $cases = [];
        foreach (self::SCHEMA_SLUGS as $label => $schemaSlug) {
            $cases[$schemaSlug] = [$schemaSlug];
        }

        return $cases;
    }//end schemaSlugProvider()
}//end class
