<?php

/**
 * Unit tests for ConfigurationImportPreviewService (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\ConfigurationImportPreviewService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the non-mutating preview classification (creates/updates/collisions/
 * unresolvedReferences/credentialsNeedingReentry).
 */
class ConfigurationImportPreviewServiceTest extends TestCase
{
    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
    }//end setUp()

    /**
     * Wire findAll() so each schema returns the given slug=>uuid rows.
     *
     * @param array<string,array<string,string>> $rowsBySchema schema => [slug => uuid].
     *
     * @return void
     */
    private function seedTargetEnvironment(array $rowsBySchema): void
    {
        $this->orObjectService->method('findAll')->willReturnCallback(
            function (array $config=[]) use ($rowsBySchema): array {
                $schema  = ($config['filters']['schema'] ?? '');
                $rows    = ($rowsBySchema[$schema] ?? []);
                $results = [];
                foreach ($rows as $slug => $uuid) {
                    $results[] = ObjectServiceMockBuilder::objectEntity($this, ['slug' => $slug], $uuid);
                }

                return ['results' => $results, 'total' => count($results)];
            }
        );
    }//end seedTargetEnvironment()

    /**
     * Missing top-level `components` throws, mirroring importConfiguration().
     *
     * @return void
     */
    public function testMissingComponentsThrows(): void
    {
        $service = new ConfigurationImportPreviewService($this->orObjectService);

        $this->expectException(\InvalidArgumentException::class);
        $service->preview(['not-components' => []]);
    }//end testMissingComponentsThrows()

    /**
     * REQ-007 scenario: one existing-slug Source classifies as update, one
     * new-slug Source as create — and saveObject() is NEVER called.
     *
     * @return void
     */
    public function testClassifiesCreatesAndUpdatesWithoutWriting(): void
    {
        $this->seedTargetEnvironment(['source' => ['existing-source' => 'uuid-existing']]);
        $this->orObjectService->expects($this->never())->method('saveObject');

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'sources' => [
                        'existing-source' => ['slug' => 'existing-source', 'name' => 'Existing'],
                        'new-source'      => ['slug' => 'new-source', 'name' => 'New'],
                    ],
                ],
            ]
        );

        $this->assertCount(1, $result['updates']);
        $this->assertSame('existing-source', $result['updates'][0]['slug']);
        $this->assertSame('uuid-existing', $result['updates'][0]['id']);

        $this->assertCount(1, $result['creates']);
        $this->assertSame('new-source', $result['creates'][0]['slug']);

        $this->assertSame([], $result['collisions']);
    }//end testClassifiesCreatesAndUpdatesWithoutWriting()

    /**
     * A slug that matches an object of a DIFFERENT schema is a collision.
     *
     * @return void
     */
    public function testCrossSchemaSlugCollision(): void
    {
        $this->seedTargetEnvironment(['endpoint' => ['ambiguous-slug' => 'uuid-endpoint']]);

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'sources' => [
                        'ambiguous-slug' => ['slug' => 'ambiguous-slug'],
                    ],
                ],
            ]
        );

        $this->assertCount(1, $result['collisions']);
        $this->assertSame('source', $result['collisions'][0]['type']);
        $this->assertSame('ambiguous-slug', $result['collisions'][0]['slug']);
        $this->assertStringContainsString('endpoint', $result['collisions'][0]['reason']);
    }//end testCrossSchemaSlugCollision()

    /**
     * REQ-007 scenario: a Rule whose nested configuration references an
     * unresolvable Source slug lands in unresolvedReferences (the REQ-004
     * "left verbatim" case).
     *
     * @return void
     */
    public function testUnresolvedNestedRuleReferenceIsSurfaced(): void
    {
        $this->seedTargetEnvironment([]);

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'rules' => [
                        'r1' => [
                            'slug'          => 'r1',
                            'configuration' => ['sourceId' => 'unknown-source-slug'],
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount(1, $result['unresolvedReferences']);
        $ref = $result['unresolvedReferences'][0];
        $this->assertSame('rule', $ref['type']);
        $this->assertSame('r1', $ref['slug']);
        $this->assertSame('configuration.sourceId', $ref['field']);
        $this->assertSame('unknown-source-slug', $ref['value']);
    }//end testUnresolvedNestedRuleReferenceIsSurfaced()

    /**
     * A nested reference that DOES resolve produces no unresolved entry.
     *
     * @return void
     */
    public function testResolvableNestedReferenceIsNotFlagged(): void
    {
        $this->seedTargetEnvironment(['source' => ['known-source' => 'uuid-known']]);

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'rules' => [
                        'r1' => [
                            'slug'          => 'r1',
                            'configuration' => ['sourceId' => 'known-source'],
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame([], $result['unresolvedReferences']);
    }//end testResolvableNestedReferenceIsNotFlagged()

    /**
     * Endpoint top-level references (inputMapping / outputMapping / rules[])
     * are checked against their own maps.
     *
     * @return void
     */
    public function testEndpointTopLevelReferences(): void
    {
        $this->seedTargetEnvironment(['mapping' => ['known-mapping' => 'uuid-mapping']]);

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'endpoints' => [
                        'e1' => [
                            'slug'          => 'e1',
                            'inputMapping'  => 'known-mapping',
                            'outputMapping' => 'missing-mapping',
                            'rules'         => ['missing-rule'],
                        ],
                    ],
                ],
            ]
        );

        $fields = array_column($result['unresolvedReferences'], 'field');
        $this->assertContains('outputMapping', $fields);
        $this->assertContains('rules[]', $fields);
        $this->assertNotContains('inputMapping', $fields);
    }//end testEndpointTopLevelReferences()

    /**
     * REQ-009: every imported Source with stripped credential fields is
     * listed under credentialsNeedingReentry, naming the missing fields.
     *
     * @return void
     */
    public function testCredentialsNeedingReentryFlagsStrippedSources(): void
    {
        $this->seedTargetEnvironment([]);

        $service = new ConfigurationImportPreviewService($this->orObjectService);
        $result  = $service->preview(
            [
                'components' => [
                    'sources' => [
                        'stripped-source' => [
                            'slug' => 'stripped-source',
                            'name' => 'Post-export source (credentials redacted)',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount(1, $result['credentialsNeedingReentry']);
        $entry = $result['credentialsNeedingReentry'][0];
        $this->assertSame('stripped-source', $entry['slug']);
        $this->assertContains('apikey', $entry['fields']);
        $this->assertContains('secret', $entry['fields']);
        $this->assertContains('username', $entry['fields']);
        $this->assertContains('password', $entry['fields']);
    }//end testCredentialsNeedingReentryFlagsStrippedSources()
}//end class
