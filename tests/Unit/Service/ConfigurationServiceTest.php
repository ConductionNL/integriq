<?php
/**
 * Unit tests for ConfigurationService.
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

use OCA\OpenConnector\Service\ConfigurationHandlers\EndpointHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\JobHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\MappingHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\RuleHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SourceHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\OpenConnector\Service\ConfigurationService;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the configuration export/import service (rewritten for OR cutover).
 *
 * The original ConfigurationServiceTest imported 12 deleted Db types
 * (Source, Endpoint, Mapping, Rule, Job, Synchronization + their Mappers).
 * This replacement uses ObjectServiceMockBuilder and the new handler-based
 * constructor that takes ORObjectService + RegisterMapper + SchemaMapper.
 */
class ConfigurationServiceTest extends TestCase
{

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var RegisterMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $registerMapper;

    /**
     * @var SchemaMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $schemaMapper;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->registerMapper  = $this->createMock(RegisterMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);

        $this->service = $this->buildService();

        // Default: findAll returns empty results.
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Default: registerMapper + schemaMapper return empty lists.
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([]);
    }//end setUp()


    /**
     * Build a ConfigurationService wired against the current $this->orObjectService
     * mock, with real handlers (each taking ORObjectService + the shared
     * SensitiveFieldRegistry in its constructor since secret-hygiene).
     *
     * @return ConfigurationService
     */
    private function buildService(): ConfigurationService
    {
        $registry               = new SensitiveFieldRegistry();
        $endpointHandler        = new EndpointHandler($this->orObjectService, $registry);
        $synchronizationHandler = new SynchronizationHandler($this->orObjectService, $registry);
        $mappingHandler         = new MappingHandler($this->orObjectService, $registry);
        $jobHandler             = new JobHandler($this->orObjectService, $registry);
        $sourceHandler          = new SourceHandler($this->orObjectService, $registry);
        $ruleHandler            = new RuleHandler($this->orObjectService, $registry);

        return new ConfigurationService(
            $this->orObjectService,
            $this->registerMapper,
            $this->schemaMapper,
            $endpointHandler,
            $synchronizationHandler,
            $mappingHandler,
            $jobHandler,
            $sourceHandler,
            $ruleHandler,
        );
    }//end buildService()


    /**
     * Test that the constructor instantiates ConfigurationService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(ConfigurationService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that getEntitiesByConfiguration returns an array keyed by entity type.
     *
     * When no objects match the configurationId, each key must be an empty array.
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationReturnsKeyedArray(): void
    {
        // Arrange — OR returns no matching objects
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Act
        $result = $this->service->getEntitiesByConfiguration('config-id-1');

        // Assert
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('endpoints', $result);
        $this->assertArrayHasKey('mappings', $result);
        $this->assertArrayHasKey('rules', $result);
        $this->assertArrayHasKey('jobs', $result);
        $this->assertArrayHasKey('synchronizations', $result);
    }//end testGetEntitiesByConfigurationReturnsKeyedArray()


    /**
     * Test that getEntitiesByConfiguration filters by configurationId.
     *
     * An entity whose 'configurations' array does NOT contain the requested ID
     * must be excluded from the result.
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationFiltersOutUnrelatedEntities(): void
    {
        // Arrange — one source with a different configuration ID
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['slug' => 'my-source', 'configurations' => ['other-config-id']],
            'source-uuid-1'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$sourceEntity], 'total' => 1]);

        // Act
        $result = $this->service->getEntitiesByConfiguration('config-id-2');

        // Assert — 'my-source' must not appear because it belongs to 'other-config-id'
        $this->assertArrayNotHasKey('my-source', $result['sources']);
    }//end testGetEntitiesByConfigurationFiltersOutUnrelatedEntities()


    /**
     * Test that exportConfiguration returns a JSON-serialisable array with the
     * OAS-style 'components' envelope.
     *
     * Since `retrofit-2026-05-25-configuration-export-import` the export wraps
     * the entity-type buckets under a top-level `components` key (so the export
     * matches the OAS Components Object shape consumers expect). The previous
     * test asserted the legacy flat shape; this asserts the current contract.
     *
     * @return void
     */
    public function testExportConfigurationReturnsStructuredArray(): void
    {
        // Arrange — no objects, so export is essentially empty.
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Act.
        $result = $this->service->exportConfiguration('export-config-id');

        // Assert — top-level OAS-style envelope.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('components', $result);
        $this->assertIsArray($result['components']);

        // Each entity type bucket lives inside `components` and is an array
        // (empty here because no matching objects, but the keys must exist).
        foreach (['sources', 'endpoints', 'mappings', 'rules', 'jobs', 'synchronizations'] as $bucket) {
            $this->assertArrayHasKey($bucket, $result['components'], "missing bucket: $bucket");
            $this->assertIsArray($result['components'][$bucket], "bucket not array: $bucket");
        }
    }//end testExportConfigurationReturnsStructuredArray()


    /**
     * Test that getEntitiesByConfiguration indexes matching entities by slug.
     *
     * Uses `willReturnCallback` keyed on the schema filter — `getEntitiesByConfiguration`
     * issues six separate `findAll()` calls (one per schema). PHPUnit's
     * `->method()->willReturn()` queues a fresh return per invocation, so a
     * naive `willReturn([$source])` would have the source land in whichever
     * bucket happens to be the second call (see ObjectServiceMockBuilder's
     * matcher-queueing note — that was the original test drift).
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationIndexesBySlug(): void
    {
        // Arrange — a source whose 'configurations' array contains our config ID.
        $targetConfigId = 'config-id-3';
        $sourceEntity   = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'           => 'source-a',
                'configurations' => [$targetConfigId],
                'name'           => 'Source A',
            ],
            'source-uuid-2'
        );

        // Rebuild the OR mock so we can install a callback-based findAll
        // without colliding with the empty-default queued in setUp().
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->orObjectService->method('findAll')
            ->willReturnCallback(static function (array $config) use ($sourceEntity): array {
                $schema = ($config['filters']['schema'] ?? '');
                if ($schema === 'source') {
                    return ['results' => [$sourceEntity], 'total' => 1];
                }

                return ['results' => [], 'total' => 0];
            });

        // Rewire the service with the fresh mock.
        $this->service = $this->buildService();

        // Act.
        $result = $this->service->getEntitiesByConfiguration($targetConfigId);

        // Assert — entity indexed under its slug in the sources bucket only.
        $this->assertArrayHasKey('source-a', $result['sources']);
        $this->assertSame('Source A', $result['sources']['source-a']['name']);

        // Sanity: it must NOT have been mis-bucketed elsewhere.
        foreach (['endpoints', 'mappings', 'rules', 'jobs', 'synchronizations'] as $otherBucket) {
            $this->assertArrayNotHasKey('source-a', $result[$otherBucket], "source-a leaked into $otherBucket");
        }
    }//end testGetEntitiesByConfigurationIndexesBySlug()


    /**
     * TC-8 / secret-hygiene Task 7 — cross-entity export-leak regression.
     *
     * One instance of every one of the six entity types is tagged with the
     * same configuration id and seeded with a distinct secret-shaped value
     * under a differently-named `configuration` field (`password`, `token`,
     * `client_secret`, `apikey`, `Authorization` header, `Cookie` header).
     * The JSON-serialised `exportConfiguration()` output must not contain any
     * of the six seeded plaintext values as a substring.
     *
     * @return void
     *
     * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
     */
    public function testExportConfigurationLeaksNoSecretShapedValueForAnyEntityType(): void
    {
        $configId = 'secret-hygiene-config-1';

        $plaintextSecrets = [
            'source'          => 'live-source-password-111',
            'endpoint'        => 'live-endpoint-token-222',
            'mapping'         => 'live-mapping-client-secret-333',
            'rule'            => 'live-rule-apikey-444',
            'job'             => 'Bearer live-job-authorization-555',
            'synchronization' => 'session=live-sync-cookie-666',
        ];

        $entitiesBySchema = [
            'source'          => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-source',
                    'configurations' => [$configId],
                    'configuration'  => ['password' => $plaintextSecrets['source']],
                ],
                'sec-source-uuid'
            ),
            'endpoint'        => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-endpoint',
                    'configurations' => [$configId],
                    'configuration'  => ['token' => $plaintextSecrets['endpoint']],
                ],
                'sec-endpoint-uuid'
            ),
            'mapping'         => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-mapping',
                    'configurations' => [$configId],
                    'configuration'  => ['client_secret' => $plaintextSecrets['mapping']],
                ],
                'sec-mapping-uuid'
            ),
            'rule'            => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-rule',
                    'configurations' => [$configId],
                    'configuration'  => ['action' => ['apikey' => $plaintextSecrets['rule']]],
                ],
                'sec-rule-uuid'
            ),
            'job'             => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-job',
                    'configurations' => [$configId],
                    'configuration'  => ['headers' => ['Authorization' => $plaintextSecrets['job']]],
                ],
                'sec-job-uuid'
            ),
            'synchronization' => ObjectServiceMockBuilder::objectEntity(
                $this,
                [
                    'slug'           => 'sec-sync',
                    'configurations' => [$configId],
                    'configuration'  => ['headers' => ['Cookie' => $plaintextSecrets['synchronization']]],
                ],
                'sec-sync-uuid'
            ),
        ];

        // Rebuild the OR mock with a schema-keyed findAll (see the
        // matcher-queueing note on testGetEntitiesByConfigurationIndexesBySlug).
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->orObjectService->method('findAll')
            ->willReturnCallback(static function (array $config) use ($entitiesBySchema): array {
                $schema = ($config['filters']['schema'] ?? '');
                if (isset($entitiesBySchema[$schema]) === true) {
                    return ['results' => [$entitiesBySchema[$schema]], 'total' => 1];
                }

                return ['results' => [], 'total' => 0];
            });

        $this->service = $this->buildService();

        // Act.
        $export     = $this->service->exportConfiguration($configId);
        $exportJson = json_encode($export);

        // Assert: every entity type made it INTO the export (the redaction
        // must not work by simply dropping the entities).
        foreach (['sources', 'endpoints', 'mappings', 'rules', 'jobs', 'synchronizations'] as $bucket) {
            $this->assertNotEmpty($export['components'][$bucket], "expected bucket '$bucket' to contain the seeded entity");
        }

        // Assert: none of the six seeded plaintext secrets survive anywhere
        // in the JSON-serialised export.
        foreach ($plaintextSecrets as $entityType => $plaintext) {
            $this->assertStringNotContainsString(
                $plaintext,
                $exportJson,
                "plaintext secret for entity type '$entityType' leaked into the export"
            );
        }

        // Assert: the redaction placeholder is present (masking, not omission).
        $this->assertStringContainsString('***REDACTED***', $exportJson);
    }//end testExportConfigurationLeaksNoSecretShapedValueForAnyEntityType()

}//end class
