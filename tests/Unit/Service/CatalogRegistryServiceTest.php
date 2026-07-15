<?php

/**
 * Unit tests for CatalogRegistryService (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CatalogRegistryService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CatalogRegistryService::collect() / resolveStatus() /
 * findSeedSourcePayload().
 */
class CatalogRegistryServiceTest extends TestCase
{
    /**
     * @var IntegrationRegistry
     */
    private IntegrationRegistry $registry;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appConfig;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry        = new IntegrationRegistry();
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->appConfig       = $this->createMock(IAppConfig::class);
    }//end setUp()

    /**
     * Build the service under test with the shared fixtures.
     *
     * @return CatalogRegistryService
     */
    private function makeService(): CatalogRegistryService
    {
        return new CatalogRegistryService(
            $this->registry,
            $this->orObjectService,
            $this->appConfig,
            new NullLogger()
        );
    }//end makeService()

    /**
     * Build a minimal IntegrationProvider double.
     *
     * @param string $id    Provider id.
     * @param string $label Provider label.
     *
     * @return IntegrationProvider
     */
    private function makeProvider(string $id, string $label): IntegrationProvider
    {
        $provider = $this->createMock(IntegrationProvider::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('getIcon')->willReturn('Database');
        $provider->method('getGroup')->willReturn(null);
        $provider->method('isEnabled')->willReturn(true);
        return $provider;
    }//end makeProvider()

    /**
     * collect() returns one adapter entry per IntegrationRegistry provider,
     * plus the 4 static descriptors, plus one source-template entry per
     * register.d seed fragment carrying a source object (REQ-003).
     *
     * @return void
     */
    public function testCollectAssemblesFromAllThreeSources(): void
    {
        $this->registry->withProviders(
            [
                $this->makeProvider('data-infra-s3', 'S3 object storage'),
                $this->makeProvider('microsoft-365', 'Microsoft 365'),
            ]
        );

        $entries = $this->makeService()->collect();
        $slugs   = array_column($entries, 'slug');

        // (a) registry-sourced adapters.
        $this->assertContains('adapter:data-infra-s3', $slugs);
        $this->assertContains('adapter:microsoft-365', $slugs);

        // (b) static descriptors.
        $this->assertContains('adapter:pdok', $slugs);
        $this->assertContains('adapter:digikoppeling', $slugs);
        $this->assertContains('adapter:berichtenbox', $slugs);
        $this->assertContains('adapter:dso', $slugs);

        // (c) seed fragments (real files under lib/Settings/register.d).
        $this->assertContains('source-template:brp-haalcentraal', $slugs);
        $this->assertContains('source-template:kvk', $slugs);
        $this->assertContains('source-template:xwiki', $slugs);
        $this->assertContains('source-template:opencorporates', $slugs);

        // No duplicates — slugs are the upsert keys.
        $this->assertSame(count($slugs), count(array_unique($slugs)));
    }//end testCollectAssemblesFromAllThreeSources()

    /**
     * A newly registered provider appears in collect() with no code change
     * (REQ-003 scenario).
     *
     * @return void
     */
    public function testNewProviderAppearsWithoutCodeChange(): void
    {
        $service = $this->makeService();

        $this->registry->withProviders([$this->makeProvider('data-infra-s3', 'S3 object storage')]);
        $before = count($service->collect());

        $this->registry->withProviders(
            [
                $this->makeProvider('data-infra-s3', 'S3 object storage'),
                $this->makeProvider('fifth-provider', 'A fifth provider'),
            ]
        );
        $after = $service->collect();

        $this->assertCount($before + 1, $after);
        $this->assertContains('adapter:fifth-provider', array_column($after, 'slug'));
    }//end testNewProviderAppearsWithoutCodeChange()

    /**
     * Seeded source templates carry the mock-seeded mechanism and their
     * category override, distinct from flag-gated adapters (Risk 2 — two
     * dormancy mechanisms modeled separately).
     *
     * @return void
     */
    public function testSeedEntriesAreMockSeededWithCategoryOverride(): void
    {
        $entries = $this->makeService()->collect();
        $bySlug  = array_column($entries, null, 'slug');

        $brp = $bySlug['source-template:brp-haalcentraal'];
        $this->assertSame('mock-seeded', $brp['mechanism']);
        $this->assertSame('Government registers', $brp['category']);
        $this->assertSame('brp-haalcentraal', $brp['sourceTemplateSlug']);
        $this->assertSame('source-template', $brp['kind']);

        $pdok = $bySlug['adapter:pdok'];
        $this->assertSame('flag-gated', $pdok['mechanism']);
        $this->assertSame('pdok.feature_flag', $pdok['flagKey']);
    }//end testSeedEntriesAreMockSeededWithCategoryOverride()

    /**
     * resolveStatus(): a flag-gated entry is dormant while its app-config
     * flag is unset and available once it is '1' (REQ-001 scenario).
     *
     * @return void
     */
    public function testResolveStatusFlagGated(): void
    {
        $entry = [
            'mechanism' => 'flag-gated',
            'flagKey'   => 'pdok.feature_flag',
        ];

        $this->appConfig->method('getValueString')
            ->willReturnOnConsecutiveCalls('0', '1');

        $service = $this->makeService();
        $this->assertSame('dormant', $service->resolveStatus($entry));
        $this->assertSame('available', $service->resolveStatus($entry));
    }//end testResolveStatusFlagGated()

    /**
     * resolveStatus(): a mock-seeded entry reads the LIVE Source object —
     * isEnabled:true means available even in mock mode (REQ-001 scenario:
     * mock is reachable, just canned).
     *
     * @return void
     */
    public function testResolveStatusMockSeededReadsLiveSource(): void
    {
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'          => 'brp-haalcentraal',
                'isEnabled'     => true,
                'configuration' => ['mock' => true],
            ],
            'source-uuid-1'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$sourceEntity], 'total' => 1]);

        $status = $this->makeService()->resolveStatus(
            [
                'mechanism'          => 'mock-seeded',
                'sourceTemplateSlug' => 'brp-haalcentraal',
            ]
        );

        $this->assertSame('available', $status);
    }//end testResolveStatusMockSeededReadsLiveSource()

    /**
     * resolveStatus(): a mock-seeded entry whose Source is missing or
     * disabled is dormant.
     *
     * @return void
     */
    public function testResolveStatusMockSeededDormantWhenSourceMissing(): void
    {
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $status = $this->makeService()->resolveStatus(
            [
                'mechanism'          => 'mock-seeded',
                'sourceTemplateSlug' => 'nonexistent-source',
            ]
        );

        $this->assertSame('dormant', $status);
    }//end testResolveStatusMockSeededDormantWhenSourceMissing()

    /**
     * findSeedSourcePayload() returns the full raw source payload for a
     * known seed slug and null for an unknown one.
     *
     * @return void
     */
    public function testFindSeedSourcePayload(): void
    {
        $service = $this->makeService();

        $payload = $service->findSeedSourcePayload('brp-haalcentraal');
        $this->assertIsArray($payload);
        $this->assertSame('brp-haalcentraal', $payload['slug']);
        $this->assertSame('BRP HaalCentraal Personen', $payload['name']);
        $this->assertArrayNotHasKey('@self', $payload);
        $this->assertArrayHasKey('configuration', $payload);

        $this->assertNull($service->findSeedSourcePayload('definitely-not-a-seed'));
    }//end testFindSeedSourcePayload()
}//end class
