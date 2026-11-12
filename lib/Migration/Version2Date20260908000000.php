<?php

/**
 * Consolidated migration: import the register, drain the legacy tables, drop them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Replaces the twenty incremental migrations this app used to ship. Eighteen of
 * them built and altered the fifteen `oc_openconnector_*` tables; those tables
 * are legacy storage under the ADR-012 strangler-fig and this migration removes
 * them, so recreating them on a fresh install only to drop them again would be
 * pure ceremony. A fresh install now creates no table at all: every entity this
 * app owns lives in OpenRegister.
 *
 * On an existing install the order is import, drain, drop. The drop is skipped
 * unless the drain reports every entity copied, because dropping a table whose
 * rows did not reach OpenRegister would destroy them.
 *
 * A first install runs neither postSchemaChange nor the post-migration repair
 * steps (Installer::installAppLastSteps() passes $schemaOnly = true), which is
 * why InitializeRegister is also declared under <install> in info.xml. There is
 * nothing to drain or drop on a first install, so that is the correct outcome.
 *
 * Cross-ref: openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md
 * REQ-001/005, local ADR-012 (strangler-fig pattern), GH #820.
 *
 * @category Migration
 * @package  OCA\Integriq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Closure;
use InvalidArgumentException;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCP\DB\ISchemaWrapper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports the register, copies legacy rows into OpenRegister, then drops the legacy tables.
 */
class Version2Date20260908000000 extends SimpleMigrationStep {
	/**
	 * The legacy tables, unprefixed.
	 *
	 * Kept on the pre-rename `openconnector_` name on purpose: this is the name
	 * the rows were written under, and it is the name LegacyToRegisterMigrator
	 * matches when it drains them. They are removed rather than renamed.
	 *
	 * @var string[]
	 */
	private const LEGACY_TABLES = [
		'openconnector_call_logs',
		'openconnector_consumers',
		'openconnector_endpoints',
		'openconnector_event_messages',
		'openconnector_event_subscriptions',
		'openconnector_events',
		'openconnector_job_logs',
		'openconnector_jobs',
		'openconnector_mappings',
		'openconnector_rules',
		'openconnector_sources',
		'openconnector_synchronization_contract_logs',
		'openconnector_synchronization_contracts',
		'openconnector_synchronization_logs',
		'openconnector_synchronizations',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig  Reads and writes the storage_migrated flag.
	 * @param IDBConnection      $connection Database connection used for the drop.
	 * @param IAppManager        $appManager Resolves and loads the openregister app.
	 * @param LoggerInterface    $logger     Logger for the failure paths.
	 * @param ContainerInterface $container  Resolves the OpenRegister services lazily.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IDBConnection $connection,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * No schema diff. This app owns no tables of its own.
	 *
	 * @param IOutput                   $output        Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null Always null for this migration.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}//end changeSchema()

	/**
	 * Import the register, drain the legacy tables, then drop them.
	 *
	 * @param IOutput                   $output        Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();

		$present = [];
		foreach (self::LEGACY_TABLES as $table) {
			if ($schema->hasTable($table) === true) {
				$present[] = $table;
			}
		}

		if ($present === []) {
			$output->info('chain-B/C: no legacy openconnector_* table present — nothing to drain or drop.');
			$this->appConfig->setValueString('integriq', 'storage_migrated', 'true');
			return;
		}

		if ($this->appConfig->getValueString('integriq', 'storage_migrated', '') !== 'true') {
			if ($this->drain(output: $output) === false) {
				$output->warning(
					'chain-B/C: legacy tables KEPT — the drain did not report every entity copied.'
					. ' Use occ integriq:migrate-storage to retry, then re-run occ upgrade to drop them.'
				);
				return;
			}
		}

		$this->dropLegacyTables(output: $output, tables: $present);
	}//end postSchemaChange()

	/**
	 * Import the register descriptor and copy every legacy row into OpenRegister.
	 *
	 * @param IOutput $output Migration output interface.
	 *
	 * @return bool True when every entity copied and the flag was set.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function drain(IOutput $output): bool {
		// `occ app:enable integriq` runs migrations with integriq's PSR-4 paths
		// loaded but NOT openregister's, so the class_exists probe below would
		// return false even when OR is enabled. Requiring OR's composer
		// autoload directly registers its PSR-4 paths unconditionally and
		// idempotently, independent of NC's per-command app-loading order.
		try {
			$orAutoload = $this->appManager->getAppPath('openregister').'/vendor/autoload.php';
			if (file_exists($orAutoload) === true) {
				include_once $orAutoload;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('chain-B: getAppPath(openregister) failed: '.$e->getMessage(), ['exception' => $e]);
		}

		try {
			$this->appManager->loadApp('openregister');
		} catch (\Throwable $e) {
			$this->logger->info('chain-B: loadApp(openregister) skipped: '.$e->getMessage());
		}

		if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false) {
			$output->warning(
				'chain-B: openregister app not enabled or not loaded; skipping descriptor import + migration.'
				.' Re-run `occ upgrade` after enabling openregister.'
			);
			return false;
		}

		try {
			$configurationService = $this->container->get('OCA\\OpenRegister\\Service\\ConfigurationService');
			$migrator = $this->container->get(LegacyToRegisterMigrator::class);
		} catch (\Throwable $e) {
			$output->warning('chain-B: failed to resolve services ('.$e->getMessage().'); skipping.');
			return false;
		}

		$descriptorPath = __DIR__.'/../Settings/integriq_register.json';
		$descriptor = json_decode((string) file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
		$configurationService->importFromApp(
			appId: 'integriq',
			data: $descriptor,
			version: $this->appConfig->getValueString('integriq', 'installed_version', '1.0.0')
		);
		$output->info('chain-B: register descriptor imported (idempotent — existing schemas reused).');

		$result = $migrator->migrateAll(dryRun: false, entitySlug: null, batchSize: 10000);

		$allOk = true;
		foreach ($result as $perEntity) {
			$skipped = (int) ($perEntity['skipped'] ?? 0);
			$output->info(
				sprintf(
					'  %s: legacy=%d migrated=%d skipped=%d fkRewrites=%d (%dms)',
					$perEntity['slug'] ?? '?',
					(int) ($perEntity['legacyCount'] ?? 0),
					(int) ($perEntity['migratedCount'] ?? 0),
					$skipped,
					(int) ($perEntity['fkRewrites'] ?? 0),
					(int) ($perEntity['elapsedMs'] ?? 0)
				)
			);
			if ($skipped > 0 || empty($perEntity['error']) === false) {
				$allOk = false;
			}
		}

		if ($allOk === false) {
			return false;
		}

		$this->appConfig->setValueString('integriq', 'storage_migrated', 'true');
		$output->info('chain-B: storage_migrated=true — all 15 entities copied successfully.');
		return true;
	}//end drain()

	/**
	 * Drop the legacy tables whose rows are now in OpenRegister.
	 *
	 * @param IOutput  $output Migration output interface.
	 * @param string[] $tables Unprefixed legacy tables that are present.
	 *
	 * @return void
	 */
	private function dropLegacyTables(IOutput $output, array $tables): void {
		$dropped = 0;

		foreach ($tables as $table) {
			// Identifiers cannot be bound as parameters. Every name comes from
			// the private constant above; this guard keeps that guarantee local.
			if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
				throw new InvalidArgumentException('Refusing to drop a table with a non-identifier name.');
			}

			try {
				$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS *PREFIX*%s', $table));
				$dropped++;
			} catch (\Throwable $e) {
				$output->warning(sprintf('chain-B/C cleanup: could not drop `%s`: %s', $table, $e->getMessage()));
				$this->logger->warning('chain-B/C cleanup: drop failed for '.$table, ['exception' => $e]);
			}
		}

		$output->info(sprintf('chain-B/C cleanup: dropped %d of %d legacy tables.', $dropped, count($tables)));
	}//end dropLegacyTables()
}//end class
