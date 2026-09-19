<?php

/**
 * Integriq SyncConnectionDeclarations Repair Step.
 *
 * Reads every enabled app's `lib/Settings/connections.json` into `app_connection`
 * rows on integriq's install and upgrade (umbrella design D5). Runs after
 * InitializeRegister, so the `app_connection` schema exists.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs connection declarations into rows.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */
class SyncConnectionDeclarations implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the registry service lazily,
	 *                                      so a missing OpenRegister costs a warning, not the install.
	 * @param LoggerInterface $logger Logs a failed sync.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable name surfaced by `occ` during install and upgrade.
	 *
	 * @return string
	 *
	 * @spec exclude Repair-step display name for occ output, framework metadata with no domain behaviour.
	 */
	public function getName(): string {
		return 'Sync connection declarations into Integriq connection rows (connection-registry)';
	}//end getName()

	/**
	 * Run the sync for every enabled app. Never throws: it runs under
	 * `<install>`, where an escaping exception aborts the install.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\OCA\\OpenRegister\\Service\\ObjectService') === false) {
			$output->info('Integriq: OpenRegister is not available, skipping the connection declaration sync');
			return;
		}

		try {
			$summary = $this->container->get(ConnectionRegistryService::class)->sync();
		} catch (\Throwable $e) {
			$output->warning('Integriq: the connection declaration sync failed: ' . $e->getMessage());
			$this->logger->error(
				'Integriq: SyncConnectionDeclarations failed',
				['app' => 'integriq', 'exception' => $e]
			);
			return;
		}

		$skipped = 'none';
		if ($summary['skipped'] !== []) {
			$skipped = implode(', ', $summary['skipped']);
		}

		$output->info(
			sprintf(
				'Integriq: connection rows created %d, updated %d, deleted %d, unchanged %d; skipped files: %s',
				$summary['created'],
				$summary['updated'],
				$summary['deleted'],
				$summary['unchanged'],
				$skipped
			)
		);
	}//end run()
}//end class
