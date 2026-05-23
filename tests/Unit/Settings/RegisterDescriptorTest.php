<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI guard for chain A (openconnector-register-schema-declaration) — REQ-A-002.
 *
 * Asserts that every protected property on each of the 15 openconnector entity
 * classes appears as a property on the matching schema in
 * lib/Settings/openconnector_register.json. A missing or typo'd property
 * fails the test with a diagnostic naming the entity, field, and schema slug.
 *
 * Cross-ref: openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-a-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

class RegisterDescriptorTest extends TestCase
{
    /**
     * Map of entity FQCN → schema slug in openconnector_register.json.
     */
    private const ENTITY_TO_SCHEMA = [
        'OCA\\OpenConnector\\Db\\Source'                      => 'source',
        'OCA\\OpenConnector\\Db\\Consumer'                    => 'consumer',
        'OCA\\OpenConnector\\Db\\Endpoint'                    => 'endpoint',
        'OCA\\OpenConnector\\Db\\Event'                       => 'event',
        'OCA\\OpenConnector\\Db\\EventMessage'                => 'event_message',
        'OCA\\OpenConnector\\Db\\EventSubscription'           => 'event_subscription',
        'OCA\\OpenConnector\\Db\\Job'                         => 'job',
        'OCA\\OpenConnector\\Db\\Mapping'                     => 'mapping',
        'OCA\\OpenConnector\\Db\\Rule'                        => 'rule',
        'OCA\\OpenConnector\\Db\\Synchronization'             => 'synchronization',
        'OCA\\OpenConnector\\Db\\SynchronizationContract'     => 'synchronization_contract',
        'OCA\\OpenConnector\\Db\\CallLog'                     => 'call_log',
        'OCA\\OpenConnector\\Db\\JobLog'                      => 'job_log',
        'OCA\\OpenConnector\\Db\\SynchronizationLog'          => 'synchronization_log',
        'OCA\\OpenConnector\\Db\\SynchronizationContractLog'  => 'synchronization_contract_log',
    ];

    /**
     * Fields that exist on the entity but are intentionally NOT in the schema
     * (OR-managed metadata that OR auto-applies — `id` is the legacy integer PK
     * superseded by `uuid` for OR-stored objects).
     */
    private const ENTITY_FIELDS_EXCLUDED_FROM_SCHEMA = [
        'id',
    ];

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
    }

    public function testRegisterDeclaresAllFifteenSchemaSlugs(): void
    {
        $expected = array_values(self::ENTITY_TO_SCHEMA);
        $actual = $this->descriptor['components']['registers']['openconnector']['schemas'] ?? [];

        sort($expected);
        $actualSorted = $actual;
        sort($actualSorted);

        $this->assertSame(
            $expected,
            $actualSorted,
            'register.openconnector.schemas[] MUST list exactly the 15 schema slugs'
        );
    }

    public function testAllFifteenSchemasAreDefined(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach (self::ENTITY_TO_SCHEMA as $entityFqcn => $schemaSlug) {
            $this->assertArrayHasKey(
                $schemaSlug,
                $schemas,
                sprintf('Schema "%s" (for entity %s) MUST be declared in components.schemas', $schemaSlug, $entityFqcn)
            );
        }
    }

    /**
     * @dataProvider entityProvider
     */
    public function testEveryProtectedEntityPropertyAppearsInSchema(string $entityFqcn, string $schemaSlug): void
    {
        $this->assertTrue(
            class_exists($entityFqcn),
            sprintf('Entity class %s MUST exist', $entityFqcn)
        );

        $schemas = $this->descriptor['components']['schemas'] ?? [];
        $schema = $schemas[$schemaSlug] ?? null;
        $this->assertIsArray($schema, sprintf('Schema "%s" MUST be defined', $schemaSlug));

        $schemaProperties = $schema['properties'] ?? [];
        $this->assertIsArray($schemaProperties, sprintf('Schema "%s" MUST declare a properties block', $schemaSlug));

        $reflection = new ReflectionClass($entityFqcn);
        $entityFields = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $prop) {
            $name = $prop->getName();
            if (in_array($name, self::ENTITY_FIELDS_EXCLUDED_FROM_SCHEMA, true)) {
                continue;
            }
            $entityFields[] = $name;
        }

        $missing = array_diff($entityFields, array_keys($schemaProperties));

        $this->assertEmpty(
            $missing,
            sprintf(
                'Entity %s has %d protected field(s) missing from schema "%s": %s',
                $entityFqcn,
                count($missing),
                $schemaSlug,
                implode(', ', $missing)
            )
        );
    }

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
    }

    public function testMutableSchemasAreNotAppendOnly(): void
    {
        $logSchemas = ['call_log', 'job_log', 'synchronization_log', 'synchronization_contract_log'];
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        foreach ($schemas as $slug => $schema) {
            if (in_array($slug, $logSchemas, true)) {
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
    }

    public function testFkRelationsCarryRefAndOnDelete(): void
    {
        $schemas = $this->descriptor['components']['schemas'] ?? [];

        $expectedRelations = [
            ['schema' => 'call_log',      'property' => 'source',                    'target' => 'source',                    'onDelete' => 'SET NULL'],
            ['schema' => 'call_log',      'property' => 'synchronization',          'target' => 'synchronization',          'onDelete' => 'SET NULL'],
            ['schema' => 'event_message', 'property' => 'event',                    'target' => 'event',                    'onDelete' => 'CASCADE'],
            ['schema' => 'event_message', 'property' => 'consumer',                 'target' => 'consumer',                 'onDelete' => 'SET NULL'],
            ['schema' => 'event_message', 'property' => 'subscription',             'target' => 'event_subscription',        'onDelete' => 'CASCADE'],
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
    }

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
    }

    public function entityProvider(): array
    {
        $cases = [];
        foreach (self::ENTITY_TO_SCHEMA as $entityFqcn => $schemaSlug) {
            $cases[$schemaSlug] = [$entityFqcn, $schemaSlug];
        }
        return $cases;
    }
}
