<?php

/**
 * Unit tests for the MaterializeCatalogItems repair step (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\MaterializeCatalogItems;
use OCA\OpenConnector\Service\CatalogRegistryService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the slug-keyed upsert behaviour (create on first run, in-place
 * update on re-run) of the catalog materialisation repair step.
 */
class MaterializeCatalogItemsTest extends TestCase {
	/**
	 * Build a quiet IOutput double.
	 *
	 * @return IOutput
	 */
	private function makeOutput(): IOutput {
		return $this->createMock(IOutput::class);
	}//end makeOutput()

	/**
	 * Build the repair step with a container resolving the given services.
	 *
	 * @param CatalogRegistryService $registryService The registry service double.
	 * @param OrObjectService $orObjectService The OR object service double.
	 *
	 * @return MaterializeCatalogItems
	 */
	private function makeStep(CatalogRegistryService $registryService, OrObjectService $orObjectService): MaterializeCatalogItems {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registryService, $orObjectService) {
				if ($id === CatalogRegistryService::class) {
					return $registryService;
				}

				if ($id === OrObjectService::class) {
					return $orObjectService;
				}

				throw new \RuntimeException('unexpected service: ' . $id);
			}
		);

		return new MaterializeCatalogItems($container, new NullLogger());
	}//end makeStep()

	/**
	 * Build a registry-service double returning fixed entries.
	 *
	 * @param array<int,array<string,mixed>> $entries collect() return value.
	 *
	 * @return CatalogRegistryService
	 */
	private function makeRegistryService(array $entries): CatalogRegistryService {
		$service = $this->createMock(CatalogRegistryService::class);
		$service->method('collect')->willReturn($entries);
		$service->method('resolveStatus')->willReturn('dormant');
		return $service;
	}//end makeRegistryService()

	/**
	 * First run: no existing catalog_item objects → one create (uuid null)
	 * per collected entry.
	 *
	 * @return void
	 */
	public function testFirstRunCreatesOneObjectPerEntry(): void {
		$entries = [
			['slug' => 'adapter:pdok', 'name' => 'PDOK', 'kind' => 'adapter', 'mechanism' => 'flag-gated', 'flagKey' => 'pdok.feature_flag'],
			['slug' => 'source-template:xwiki', 'name' => 'xWiki', 'kind' => 'source-template', 'mechanism' => 'mock-seeded', 'sourceTemplateSlug' => 'xwiki'],
		];

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$savedUuids = [];
		$orObjectService->expects($this->exactly(2))
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register = null, $schema = null, $uuid = null) use (&$savedUuids) {
					$this->assertSame('openconnector', $register);
					$this->assertSame('catalog_item', $schema);
					$this->assertNull($uuid, 'first run must CREATE (no uuid)');
					$this->assertSame('dormant', $object['status']);
					$savedUuids[] = $object['slug'];
					return ObjectServiceMockBuilder::objectEntity($this, $object, 'new-' . $object['slug']);
				}
			);

		$this->makeStep($this->makeRegistryService($entries), $orObjectService)->run($this->makeOutput());

		$this->assertSame(['adapter:pdok', 'source-template:xwiki'], $savedUuids);
	}//end testFirstRunCreatesOneObjectPerEntry()

	/**
	 * Re-run: an existing catalog_item with the same slug is updated in
	 * place (its uuid is passed to saveObject) — no duplicate creation
	 * (REQ-003 idempotency scenario).
	 *
	 * @return void
	 */
	public function testRerunUpsertsExistingObjectBySlug(): void {
		$entries = [
			['slug' => 'adapter:pdok', 'name' => 'PDOK', 'kind' => 'adapter', 'mechanism' => 'flag-gated', 'flagKey' => 'pdok.feature_flag'],
		];
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			['slug' => 'adapter:pdok', 'name' => 'PDOK (stale)'],
			'existing-uuid'
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);
		$orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register = null, $schema = null, $uuid = null) {
					$this->assertSame('existing-uuid', $uuid, 're-run must UPDATE the existing object in place');
					return ObjectServiceMockBuilder::objectEntity($this, $object, 'existing-uuid');
				}
			);

		$this->makeStep($this->makeRegistryService($entries), $orObjectService)->run($this->makeOutput());
	}//end testRerunUpsertsExistingObjectBySlug()
}//end class
