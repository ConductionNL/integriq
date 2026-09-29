<?php

/**
 * Unit tests for CatalogRegistryService (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
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
class CatalogRegistryServiceTest extends TestCase {
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
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new IntegrationRegistry();
		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * Build the service under test with the shared fixtures.
	 *
	 * @return CatalogRegistryService
	 */
	private function makeService(?string $templateDir = null): CatalogRegistryService {
		return new CatalogRegistryService(
			$this->registry,
			$this->orObjectService,
			$this->appConfig,
			new NullLogger(),
			$templateDir
		);
	}//end makeService()

	/**
	 * The fixture template library.
	 *
	 * @return string
	 */
	private function fixtureLibrary(): string {
		return __DIR__ . '/../../fixtures/connector-templates';
	}//end fixtureLibrary()

	/**
	 * Build a minimal IntegrationProvider double.
	 *
	 * @param string $id Provider id.
	 * @param string $label Provider label.
	 *
	 * @return IntegrationProvider
	 */
	private function makeProvider(string $id, string $label): IntegrationProvider {
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
	public function testCollectAssemblesFromAllThreeSources(): void {
		$this->registry->withProviders(
			[
				$this->makeProvider('data-infra-s3', 'S3 object storage'),
				$this->makeProvider('microsoft-365', 'Microsoft 365'),
			]
		);

		$entries = $this->makeService()->collect();
		$slugs = array_column($entries, 'slug');

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
		// endoflife-date-source: seeded enabled/credential-free (unlike the
		// dormant presets above) — @spec openspec/specs/endoflife-date-source/spec.md#requirement-the-preset-is-automatically-visible-on-the-catalog-page
		$this->assertContains('source-template:endoflife-date', $slugs);
		// ideal-ouderbijdrage-source: dormant/mock payment source template —
		// @spec openspec/specs/psp-source-template/spec.md#requirement-a-seeded-mock-mode-ideal-payment-source-template-is-discoverable-in-the-catalog-req-001
		$this->assertContains('source-template:ideal-ouderbijdrage', $slugs);
		// slo-kerndoelen-import: dormant SLO curriculum source template:
		// @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
		$this->assertContains('source-template:slo-curriculum', $slugs);

		// No duplicates — slugs are the upsert keys.
		$this->assertSame(count($slugs), count(array_unique($slugs)));
	}//end testCollectAssemblesFromAllThreeSources()

	/**
	 * A newly registered provider appears in collect() with no code change
	 * (REQ-003 scenario).
	 *
	 * @return void
	 */
	public function testNewProviderAppearsWithoutCodeChange(): void {
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
	public function testSeedEntriesAreMockSeededWithCategoryOverride(): void {
		$entries = $this->makeService()->collect();
		$bySlug = array_column($entries, null, 'slug');

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
	public function testResolveStatusFlagGated(): void {
		$entry = [
			'mechanism' => 'flag-gated',
			'flagKey' => 'pdok.feature_flag',
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
	public function testResolveStatusMockSeededReadsLiveSource(): void {
		$sourceEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'brp-haalcentraal',
				'isEnabled' => true,
				'configuration' => ['mock' => true],
			],
			'source-uuid-1'
		);

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$sourceEntity], 'total' => 1]);

		$status = $this->makeService()->resolveStatus(
			[
				'mechanism' => 'mock-seeded',
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
	public function testResolveStatusMockSeededDormantWhenSourceMissing(): void {
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		$status = $this->makeService()->resolveStatus(
			[
				'mechanism' => 'mock-seeded',
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
	public function testFindSeedSourcePayload(): void {
		$service = $this->makeService();

		$payload = $service->findSeedSourcePayload('brp-haalcentraal');
		$this->assertIsArray($payload);
		$this->assertSame('brp-haalcentraal', $payload['slug']);
		$this->assertSame('BRP HaalCentraal Personen', $payload['name']);
		$this->assertArrayNotHasKey('@self', $payload);
		$this->assertArrayHasKey('configuration', $payload);

		$this->assertNull($service->findSeedSourcePayload('definitely-not-a-seed'));
	}//end testFindSeedSourcePayload()

	/**
	 * REQ-CCX-001: every template in the library is a Store card, with its
	 * tier and where it was checked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function testEveryTemplateInTheLibraryIsACard(): void {
		$entries = $this->makeService(templateDir: $this->fixtureLibrary())->collect();
		$bySlug = array_column($entries, null, 'slug');

		$this->assertArrayHasKey('template:example-zaaksysteem', $bySlug);
		$curated = $bySlug['template:example-zaaksysteem'];
		$this->assertSame('source-template', $curated['kind']);
		$this->assertSame('example-zaaksysteem', $curated['sourceTemplateSlug']);
		$this->assertSame('curated', $curated['tier']);
		$this->assertSame('https://docs.oasis-open.org/cmis/CMIS/v1.1/CMIS-v1.1.html', $curated['verifiedAgainst']);
		$this->assertSame(['CMIS 1.1'], $curated['standards']);
		$this->assertSame('Document management', $curated['category']);

		$generated = $bySlug['template:example-crm'];
		$this->assertSame('generated', $generated['tier']);
		$this->assertSame('2026-09-29', $generated['snapshotDate']);
	}//end testEveryTemplateInTheLibraryIsACard()

	/**
	 * REQ-CCX-001: Instantiate reads the template's source payload, without
	 * the template block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function testInstantiateReadsTheTemplatePayload(): void {
		$payload = $this->makeService(templateDir: $this->fixtureLibrary())->findSeedSourcePayload(slug: 'example-crm');

		$this->assertNotNull($payload);
		$this->assertSame('example-crm', $payload['slug']);
		$this->assertSame('https://api.example.com/v1', $payload['location']);
		$this->assertSame('oauth', $payload['auth']);
		$this->assertArrayNotHasKey('x-template', $payload);
	}//end testInstantiateReadsTheTemplatePayload()

	/**
	 * REQ-CCX-001: the register import reads register.d only, so no template
	 * becomes a source on install: no library slug is seeded there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function testNoLibraryTemplateIsSeededAsASource(): void {
		$library = __DIR__ . '/../../../lib/Settings/connector-templates';
		$templateSlugs = [];
		foreach (glob($library . '/*/*.json') ?: [] as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (isset($data['x-template']['slug']) === true) {
				$templateSlugs[] = $data['x-template']['slug'];
			}
		}

		$this->assertNotSame([], $templateSlugs, 'the library ships templates');

		$seeded = [];
		foreach (glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: [] as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach (($data['components']['objects'] ?? []) as $object) {
				if (($object['@self']['schema'] ?? '') === 'source') {
					$seeded[] = (string)($object['@self']['slug'] ?? '');
				}
			}
		}

		$this->assertSame([], array_values(array_intersect($templateSlugs, $seeded)));
		$this->assertStringNotContainsString('register.d', realpath($library));
	}//end testNoLibraryTemplateIsSeededAsASource()

	/**
	 * REQ-CCX-004: environment placeholders are not connectors, and a system
	 * with an adapter and a seeded source is listed once, as the adapter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
	 */
	public function testTheStoreCountsOnlyRealConnectorsOnce(): void {
		$slugs = array_column($this->makeService()->collect(), 'slug');

		$this->assertSame([], array_values(array_filter($slugs, static fn (string $slug): bool => str_contains($slug, 'environment-'))));
		$this->assertContains('adapter:smartdocuments', $slugs);
		$this->assertContains('adapter:xential', $slugs);
		$this->assertNotContains('source-template:smartdocuments', $slugs);
		$this->assertNotContains('source-template:xential', $slugs);
	}//end testTheStoreCountsOnlyRealConnectorsOnce()

	/**
	 * REQ-CCX-004: every card carries its tier, so the Store can count per
	 * tier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
	 */
	public function testEveryCardCarriesItsTier(): void {
		$this->registry->withProviders([$this->makeProvider('data-infra-s3', 'S3 object storage')]);
		$entries = array_column($this->makeService(templateDir: $this->fixtureLibrary())->collect(), 'tier', 'slug');

		$this->assertSame('adapter', $entries['adapter:data-infra-s3']);
		$this->assertSame('adapter', $entries['adapter:pdok']);
		$this->assertSame('curated', $entries['source-template:brp-haalcentraal']);
		$this->assertSame('curated', $entries['template:example-zaaksysteem']);
		$this->assertSame('generated', $entries['template:example-crm']);
		$this->assertSame([], array_diff(array_unique(array_values($entries)), ['adapter', 'curated', 'generated']));
	}//end testEveryCardCarriesItsTier()
}//end class
