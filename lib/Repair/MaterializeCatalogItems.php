<?php

/**
 * OpenConnector MaterializeCatalogItems Repair Step
 *
 * Upserts one `catalog_item` OpenRegister object per entry returned by
 * {@see \OCA\OpenConnector\Service\CatalogRegistryService::collect()},
 * keyed by a stable `kind:slug` identifier so re-runs update in place
 * rather than duplicating (REQ-003 idempotency).
 *
 * Runs after `InitializeRegister` (both `<post-migration>` in
 * appinfo/info.xml) so the `catalog_item` schema already exists and, for
 * mock-seeded entries, the underlying seeded Source objects are already
 * materialised — resolveStatus() reads them live.
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\Service\CatalogRegistryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Materialises catalog_item objects from CatalogRegistryService::collect().
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 */
class MaterializeCatalogItems implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server DI container — resolves
	 *                                      CatalogRegistryService lazily so
	 *                                      the class_exists guard can
	 *                                      short-circuit when OpenRegister
	 *                                      isn't loaded yet.
	 * @param LoggerInterface $logger For non-fatal errors that
	 *                                shouldn't trip the IRepairStep
	 *                                contract.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable name surfaced by `occ` during install / upgrade.
	 *
	 * @return string
	 *
	 * @spec exclude Repair-step display name for occ output — framework metadata, no domain behavior.
	 */
	public function getName(): string {
		return 'Materialize OpenConnector catalog_item objects (connector-catalog-ui)';
	}//end getName()

	/**
	 * Upsert one catalog_item object per CatalogRegistryService::collect() entry.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\OCA\\OpenRegister\\Service\\Integration\\IntegrationRegistry') === false) {
			$output->info('OpenConnector: OpenRegister IntegrationRegistry not available, skipping catalog materialization');
			return;
		}

		try {
			$registryService = $this->container->get(CatalogRegistryService::class);
			$orObjectService = $this->container->get(OrObjectService::class);
		} catch (\Throwable $e) {
			$output->warning('OpenConnector: could not resolve catalog services: ' . $e->getMessage());
			$this->logger->error(
				'OpenConnector: MaterializeCatalogItems service resolution failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$entries = $registryService->collect();
		} catch (\Throwable $e) {
			$output->warning('OpenConnector: CatalogRegistryService::collect() failed: ' . $e->getMessage());
			$this->logger->error(
				'OpenConnector: catalog collect() failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		// OpenRegister RBAC denies object writes to an Anonymous principal — which
		// is exactly the principal `occ maintenance:repair` / `occ upgrade` (and a
		// fresh `occ app:enable`) run as, since no user session exists at repair
		// time. Left unwrapped, every catalog_item upsert is rejected as
		// "User 'Anonymous' does not have permission to 'create' objects in schema
		// 'Catalog Item'" (the schema is created default-secure with RBAC on), so
		// the catalog materialises 0 of N on EVERY install/upgrade.
		//
		// Run the whole materialisation as a scoped system operation — the same
		// escape hatch OpenRegister's own ConfigurationService::importFromApp uses
		// for its seed writes — so the read-back (for idempotent update-in-place)
		// and the upserts are authorised as the CLI system principal. Guarded by
		// class_exists so the app degrades gracefully if a future OR drops it
		// (the IntegrationRegistry guard above already requires a compatible OR).
		$materialise = function () use ($entries, $registryService, $orObjectService, $output): int {
			$existingBySlug = $this->indexExistingBySlug(orObjectService: $orObjectService);

			$upserted = 0;
			foreach ($entries as $entry) {
				$slug = (string)($entry['slug'] ?? '');
				if ($slug === '') {
					continue;
				}

				$status = $registryService->resolveStatus(entry: $entry);

				$payload = [
					'slug' => $slug,
					'name' => (string)($entry['name'] ?? $slug),
					'description' => (string)($entry['description'] ?? ''),
					'category' => (string)($entry['category'] ?? ''),
					'kind' => (string)($entry['kind'] ?? 'adapter'),
					'mechanism' => (string)($entry['mechanism'] ?? 'always-available'),
					'flagKey' => (string)($entry['flagKey'] ?? ''),
					'sourceTemplateSlug' => (string)($entry['sourceTemplateSlug'] ?? ''),
					'status' => $status,
					'standards' => (array)($entry['standards'] ?? []),
					'icon' => (string)($entry['icon'] ?? ''),
				];

				try {
					$orObjectService->saveObject(
						object: $payload,
						register: 'openconnector',
						schema: 'catalog_item',
						uuid: ($existingBySlug[$slug] ?? null)
					);
					$upserted++;
				} catch (\Throwable $e) {
					$output->warning('OpenConnector: failed to upsert catalog_item "' . $slug . '": ' . $e->getMessage());
					$this->logger->error(
						'OpenConnector: catalog_item upsert failed',
						['slug' => $slug, 'exception' => $e->getMessage()]
					);
				}
			}//end foreach

			return $upserted;
		};

		if (class_exists('\\OCA\\OpenRegister\\Service\\SystemOperationContext') === true) {
			$upserted = \OCA\OpenRegister\Service\SystemOperationContext::run($materialise);
		} else {
			$upserted = $materialise();
		}

		$output->info('OpenConnector: materialized ' . $upserted . ' of ' . count($entries) . ' catalog_item entries.');

	}//end run()

	/**
	 * Build a slug => uuid index of every existing catalog_item object, so
	 * upserts update in place instead of duplicating (idempotency).
	 *
	 * @param OrObjectService $orObjectService The OR object service.
	 *
	 * @return array<string,string>
	 */
	private function indexExistingBySlug(OrObjectService $orObjectService): array {
		$result = $orObjectService->findAll(
			config: ['filters' => ['register' => 'openconnector', 'schema' => 'catalog_item']]
		);
		$items = ($result['results'] ?? $result);

		$index = [];
		foreach ($items as $item) {
			if ($item instanceof ObjectEntity === false) {
				continue;
			}

			$data = $item->getObject();
			$slug = (string)($data['slug'] ?? '');
			if ($slug !== '') {
				$index[$slug] = $item->getUuid();
			}
		}

		return $index;
	}//end indexExistingBySlug()
}//end class
