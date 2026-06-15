<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI guard for chain A (openconnector-register-schema-declaration) — REQ-A-002.
 *
 * Asserts structural integrity of lib/Settings/openconnector_register.json.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-a-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Validates the openconnector_register.json descriptor structure.
 *
 * Checks:
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
 */
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
     * Parsed descriptor array loaded once per test.
     *
     * @var array<string, mixed>
     */
    private array $descriptor;

    /**
     * Loads and validates the openconnector_register.json before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = dirname(path: __DIR__, levels: 3).'/lib/Settings/openconnector_register.json';
        $this->assertFileExists(
            filename: $path,
            message:  'openconnector_register.json MUST exist at lib/Settings/'
        );

        $raw = file_get_contents(filename: $path);
        $this->assertNotFalse(
            condition: $raw,
            message:   'openconnector_register.json MUST be readable'
        );

        $parsed = json_decode(json: $raw, associative: true);
        $this->assertIsArray(
            actual:  $parsed,
            message: 'openconnector_register.json MUST parse as valid JSON'
        );
        $this->assertSame(
            expected: JSON_ERROR_NONE,
            actual:   json_last_error(),
            message:  'JSON parse error: '.json_last_error_msg()
        );

        $this->descriptor = $parsed;

    }//end setUp()

    /**
     * Asserts the register declares all schema slugs (15 base + 1 RIS connector = 16).
     *
     * @return void
     */
    public function testRegisterDeclaresAllSchemaSlugs(): void
    {
        $expected = array_values(self::SCHEMA_SLUGS);
        $actual   = $this->descriptor['components']['registers']['openconnector']['schemas'] ?? [];

        sort(array: $expected);
        sort(array: $actual);

        $this->assertSame(
            expected: $expected,
            actual:   $actual,
            message:  'register.openconnector.schemas[] MUST list exactly the declared schema slugs'
        );

    }//end testRegisterDeclaresAllSchemaSlugs()

    /**
     * Asserts all schemas are defined in components.schemas.
     *
     * @return void
     */
    public function testAllSchemasAreDefined(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach (self::SCHEMA_SLUGS as $label => $schemaSlug) {
            $this->assertArrayHasKey(
                key:   $schemaSlug,
                array: $schemas,
                message: sprintf(
                    'Schema "%s" (formerly entity %s) MUST be declared in components.schemas',
                    $schemaSlug,
                    $label
                )
            );
        }

    }//end testAllSchemasAreDefined()

    /**
     * Asserts each schema declares a non-empty properties block.
     *
     * @param string $schemaSlug The schema slug to test.
     *
     * @return void
     *
     * @dataProvider schemaSlugProvider
     */
    public function testEachSchemaDeclaresSomeProperties(string $schemaSlug): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];
        $schema  = $schemas[$schemaSlug] ?? null;
        $this->assertIsArray(
            actual:  $schema,
            message: sprintf('Schema "%s" MUST be defined', $schemaSlug)
        );

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray(
            actual:  $properties,
            message: sprintf('Schema "%s" MUST declare a properties block', $schemaSlug)
        );
        $this->assertNotEmpty(
            actual:  $properties,
            message: sprintf('Schema "%s" properties block MUST NOT be empty', $schemaSlug)
        );

    }//end testEachSchemaDeclaresSomeProperties()

    /**
     * Asserts 4 log schemas have appendOnly=true, immutable=true, and archival annotation.
     *
     * @return void
     */
    public function testLogSchemasAreAppendOnlyAndImmutable(): void
    {
        $logSchemas = ['call_log', 'job_log', 'synchronization_log', 'synchronization_contract_log'];
        $schemas    = $this->descriptor['components']['schemas'] ?? [];

        foreach ($logSchemas as $slug) {
            $schema = $schemas[$slug] ?? null;
            $this->assertNotNull(
                actual:  $schema,
                message: sprintf('Log schema "%s" MUST exist', $slug)
            );
            $this->assertTrue(
                condition: $schema['appendOnly'] ?? false,
                message:   sprintf('Log schema "%s" MUST set appendOnly: true', $slug)
            );
            $this->assertTrue(
                condition: $schema['immutable'] ?? false,
                message:   sprintf('Log schema "%s" MUST set immutable: true', $slug)
            );
            $this->assertArrayHasKey(
                key:     'x-openregister-archival',
                array:   $schema,
                message: sprintf(
                    'Log schema "%s" MUST carry x-openregister-archival annotation (REQ-A-004)',
                    $slug
                )
            );
        }//end foreach

    }//end testLogSchemasAreAppendOnlyAndImmutable()

    /**
     * Asserts all 11 mutable schemas do NOT have appendOnly or immutable set.
     *
     * @return void
     */
    public function testMutableSchemasAreNotAppendOnly(): void
    {
        $logSchemas = ['call_log', 'job_log', 'synchronization_log', 'synchronization_contract_log'];
        $schemas    = $this->descriptor['components']['schemas'] ?? [];

        foreach ($schemas as $slug => $schema) {
            if (in_array(needle: $slug, haystack: $logSchemas, strict: true) === true) {
                continue;
            }

            $this->assertFalse(
                condition: $schema['appendOnly'] ?? false,
                message:   sprintf('Mutable schema "%s" MUST NOT set appendOnly: true', $slug)
            );
            $this->assertFalse(
                condition: $schema['immutable'] ?? false,
                message:   sprintf('Mutable schema "%s" MUST NOT set immutable: true', $slug)
            );
        }

    }//end testMutableSchemasAreNotAppendOnly()

    /**
     * Asserts all 6 integer-FK relations carry $ref and x-openregister-onDelete.
     *
     * @return void
     *
     * @spec openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-a-005
     */
    public function testFkRelationsCarryRefAndOnDelete(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        $expectedRelations = [
            [
                'schema'   => 'call_log',
                'property' => 'source',
                'target'   => 'source',
                'onDelete' => 'SET NULL',
            ],
            [
                'schema'   => 'call_log',
                'property' => 'synchronization',
                'target'   => 'synchronization',
                'onDelete' => 'SET NULL',
            ],
            [
                'schema'   => 'event_message',
                'property' => 'event',
                'target'   => 'event',
                'onDelete' => 'CASCADE',
            ],
            [
                'schema'   => 'event_message',
                'property' => 'consumer',
                'target'   => 'consumer',
                'onDelete' => 'SET NULL',
            ],
            [
                'schema'   => 'event_message',
                'property' => 'subscription',
                'target'   => 'event_subscription',
                'onDelete' => 'CASCADE',
            ],
            [
                'schema'   => 'synchronization_contract_log',
                'property' => 'synchronization_contract',
                'target'   => 'synchronization_contract',
                'onDelete' => 'CASCADE',
            ],
        ];

        foreach ($expectedRelations as $rel) {
            $prop = $schemas[$rel['schema']]['properties'][$rel['property']] ?? null;
            $this->assertIsArray(
                actual:  $prop,
                message: sprintf('Relation %s.%s MUST be declared', $rel['schema'], $rel['property'])
            );
            $this->assertSame(
                expected: '#/components/schemas/'.$rel['target'],
                actual:   $prop['$ref'] ?? null,
                message:  sprintf(
                    'Relation %s.%s MUST $ref %s',
                    $rel['schema'],
                    $rel['property'],
                    $rel['target']
                )
            );
            $this->assertSame(
                expected: $rel['onDelete'],
                actual:   $prop['x-openregister-onDelete'] ?? null,
                message:  sprintf(
                    'Relation %s.%s MUST set x-openregister-onDelete=%s',
                    $rel['schema'],
                    $rel['property'],
                    $rel['onDelete']
                )
            );
        }//end foreach

    }//end testFkRelationsCarryRefAndOnDelete()

    /**
     * Asserts synchronization.sourceId and targetId are string-typed with no $ref.
     *
     * @return void
     *
     * @spec openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-a-006
     */
    public function testSynchronizationSourceIdAndTargetIdAreStringWithoutRef(): void
    {
        $schema   = $this->descriptor['components']['schemas']['synchronization'] ?? [];
        $sourceId = $schema['properties']['sourceId'] ?? null;
        $targetId = $schema['properties']['targetId'] ?? null;

        $this->assertIsArray(
            actual:  $sourceId,
            message: 'synchronization.sourceId MUST be declared'
        );
        $this->assertSame(
            expected: 'string',
            actual:   $sourceId['type'] ?? null,
            message:  'synchronization.sourceId MUST be type:string (REQ-A-006)'
        );
        $this->assertArrayNotHasKey(
            key:     '$ref',
            array:   $sourceId,
            message: 'synchronization.sourceId MUST NOT carry $ref — overloaded format'
        );

        $this->assertIsArray(
            actual:  $targetId,
            message: 'synchronization.targetId MUST be declared'
        );
        $this->assertSame(
            expected: 'string',
            actual:   $targetId['type'] ?? null,
            message:  'synchronization.targetId MUST be type:string (REQ-A-006)'
        );
        $this->assertArrayNotHasKey(
            key:     '$ref',
            array:   $targetId,
            message: 'synchronization.targetId MUST NOT carry $ref — overloaded format'
        );

    }//end testSynchronizationSourceIdAndTargetIdAreStringWithoutRef()

    /**
     * Provides all 15 schema slug strings as data-provider entries.
     *
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
