<?php

/**
 * Unit tests for the MaterializeCatalogItems repair step (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MaterializeCatalogItems;
use OCA\Integriq\Service\CatalogRegistryServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
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
	 * @param CatalogRegistryServiceInterface $registryService The registry service double.
	 * @param OrObjectService $orObjectService The OR object service double.
	 *
	 * @return MaterializeCatalogItems
	 */
	private function makeStep(CatalogRegistryServiceInterface $registryService, OrObjectService $orObjectService): MaterializeCatalogItems {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registryService, $orObjectService) {
				if ($id === CatalogRegistryServiceInterface::class) {
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
	 * @return CatalogRegistryServiceInterface
	 */
	private function makeRegistryService(array $entries): CatalogRegistryServiceInterface {
		$service = $this->createMock(CatalogRegistryServiceInterface::class);
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
					$this->assertSame('integriq', $register);
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

	/**
	 * REQ-CCX-004: a card whose entry the registry no longer lists (an
	 * environment placeholder, a duplicate) is removed, so an upgraded
	 * install counts what a fresh one counts. The tier and where a template
	 * was checked reach the stored card, and the register accepts it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
	 */
	public function testAStaleCardIsRemovedAndTheTierIsStored(): void {
		$entries = [
			[
				'slug' => 'template:salesforce', 'name' => 'Salesforce', 'kind' => 'source-template', 'mechanism' => 'mock-seeded',
				'sourceTemplateSlug' => 'salesforce', 'standards' => ['OpenAPI 3'], 'tier' => 'generated',
				'verifiedAgainst' => 'https://api.apis.guru/v2/specs/salesforce.local/1.0/openapi.json', 'snapshotDate' => '2026-09-29',
			],
		];
		$stale = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'source-template:environment-local-source'], 'stale-uuid');

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [$stale], 'total' => 1]);
		$stored = null;
		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object) use (&$stored) {
				$stored = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'new');
			}
		);
		$orObjectService->expects($this->once())->method('deleteObject')
			->with($this->callback(static fn ($uuid): bool => $uuid === 'stale-uuid'));

		$this->makeStep($this->makeRegistryService($entries), $orObjectService)->run($this->makeOutput());

		$this->assertSame('generated', $stored['tier']);
		$this->assertSame('2026-09-29', $stored['snapshotDate']);
		$this->assertSame('https://api.apis.guru/v2/specs/salesforce.local/1.0/openapi.json', $stored['verifiedAgainst']);
		$this->assertSame([], RegisterSchemaValidator::errors('catalog_item', $stored));
	}//end testAStaleCardIsRemovedAndTheTierIsStored()
	/**
	 * An output double that records every info and warning line.
	 *
	 * @param array<int,string> $lines Filled with "info: ..." / "warning: ..." lines.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(array &$lines): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$lines): void {
				$lines[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$lines): void {
				$lines[] = 'warning: ' . $message;
			}
		);
		return $output;
	}//end recordingOutput()

	/**
	 * Services the container cannot resolve stop the step with a warning,
	 * before anything is read or written.
	 *
	 * @return void
	 */
	public function testUnresolvableServicesStopTheStep(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no such service'));
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Integriq: MaterializeCatalogItems service resolution failed', ['exception' => 'no such service']);
		$lines = [];

		(new MaterializeCatalogItems($container, $logger))->run($this->recordingOutput($lines));

		$this->assertSame(['warning: Integriq: could not resolve catalog services: no such service'], $lines);
	}//end testUnresolvableServicesStopTheStep()

	/**
	 * A registry that cannot collect stops the step before any write.
	 *
	 * @return void
	 */
	public function testAFailedCollectStopsTheStep(): void {
		$registry = $this->createMock(CatalogRegistryServiceInterface::class);
		$registry->method('collect')->willThrowException(new \RuntimeException('registry broken'));
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->expects($this->never())->method('findAll');
		$orObjectService->expects($this->never())->method('saveObject');
		$lines = [];

		$this->makeStep($registry, $orObjectService)->run($this->recordingOutput($lines));

		$this->assertSame(['warning: Integriq: CatalogRegistryService::collect() failed: registry broken'], $lines);
	}//end testAFailedCollectStopsTheStep()

	/**
	 * An entry without a slug is skipped, a refused upsert is reported and
	 * not counted, and the others are still written; the count says so.
	 *
	 * @return void
	 */
	public function testSkippedAndRefusedEntriesAreNotCounted(): void {
		$entries = [
			['name' => 'No slug'],
			['slug' => 'adapter:refused', 'name' => 'Refused'],
			['slug' => 'adapter:pdok', 'name' => 'PDOK'],
		];
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$saved = [];
		$orObjectService->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function ($object) use (&$saved) {
				$saved[] = $object['slug'];
				if ($object['slug'] === 'adapter:refused') {
					throw new \RuntimeException('schema refused it');
				}

				return ObjectServiceMockBuilder::objectEntity($this, $object, 'new');
			}
		);
		$lines = [];

		$this->makeStep($this->makeRegistryService($entries), $orObjectService)->run($this->recordingOutput($lines));

		$this->assertSame(['adapter:refused', 'adapter:pdok'], $saved);
		$this->assertSame(
			[
				'warning: Integriq: failed to upsert catalog_item "adapter:refused": schema refused it',
				'info: Integriq: materialized 1 of 3 catalog_item entries.',
			],
			$lines
		);
	}//end testSkippedAndRefusedEntriesAreNotCounted()

	/**
	 * A stale card that cannot be deleted is logged and does not fail the step;
	 * a stored result that is not an object entity is ignored by the index.
	 *
	 * @return void
	 */
	public function testAStaleCardThatCannotBeDeletedIsLogged(): void {
		$stale = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'adapter:gone'], 'stale-uuid');
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn([$stale, ['slug' => 'adapter:pdok', 'id' => 'not-an-entity']]);
		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) {
				$this->assertNull($uuid, 'a plain array row is not indexed, so pdok is created');
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'new');
			}
		);
		$orObjectService->expects($this->once())->method('deleteObject')->willThrowException(new \RuntimeException('locked'));
		$container = $this->createMock(ContainerInterface::class);
		$registry = $this->makeRegistryService([['slug' => 'adapter:pdok', 'name' => 'PDOK']]);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $id === CatalogRegistryServiceInterface::class ? $registry : $orObjectService
		);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Integriq: could not remove stale catalog_item', ['slug' => 'adapter:gone', 'exception' => 'locked']);
		$lines = [];

		(new MaterializeCatalogItems($container, $logger))->run($this->recordingOutput($lines));

		$this->assertSame(['info: Integriq: materialized 1 of 1 catalog_item entries.'], $lines);
	}//end testAStaleCardThatCannotBeDeletedIsLogged()
}//end class
