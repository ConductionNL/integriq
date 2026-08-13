<?php

/**
 * Declares the legacy → OpenRegister cutover complete, on a path that runs.
 *
 * `openconnector.storage_migrated` gates
 * {@see \OCA\OpenConnector\Service\Integration\SynchronizationContractProvider::isEnabled()}
 * — while it is false, "Synced from" provenance is absent from every object in
 * the instance. Two places already try to set it, and NEITHER can on a fresh
 * install:
 *
 *   * {@see \OCA\OpenConnector\Migration\Version2Date20260520000001} sets it
 *     from `postSchemaChange()`.
 *   * {@see \OCA\OpenConnector\Migration\Version2Date20260520000099} sets it
 *     from `postSchemaChange()` too — added specifically to fix the fresh-install
 *     case, and placed in the one hook that fresh installs skip.
 *
 * The mechanism, in Nextcloud core: `Installer::installAppLastSteps()` calls
 * `$ms->migrate('latest', $previousVersion === '')`, so a first install passes
 * `$schemaOnly = true`. `MigrationService::migrate()` then routes to
 * `migrateSchemaOnly()`, which calls ONLY `changeSchema()` on each migration and
 * then marks every version as executed — `preSchemaChange` and `postSchemaChange`
 * never run, and because the versions are recorded, they never will. The same
 * method guards `repair-steps.post-migration` behind `$previousVersion !== ''`,
 * so those are skipped on a first install as well.
 *
 * `repair-steps.install` is the only hook that runs unconditionally on a first
 * install, which is why this work lives in a repair step wired under BOTH
 * `<install>` and `<post-migration>`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\Migration\LegacyToRegisterMigrator;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets `storage_migrated` once no legacy table holds rows, copying any that do.
 */
class MigrateLegacyStorage implements IRepairStep {

	/**
	 * The legacy tables, unprefixed, dependents before the rows they point at.
	 *
	 * Mirrors `Version2Date20260520000099::LEGACY_TABLES`. The two are pinned
	 * identical by
	 * {@see \OCA\OpenConnector\Tests\Unit\Repair\MigrateLegacyStorageTest}, so
	 * a table added to one and not the other fails a test rather than silently
	 * dropping out of the cutover check.
	 *
	 * @var string[]
	 */
	public const LEGACY_TABLES = [
		// Dependent / log tables first.
		'openconnector_synchronization_contract_logs',
		'openconnector_synchronization_logs',
		'openconnector_job_logs',
		'openconnector_call_logs',
		'openconnector_event_messages',
		// Mid-tier referencing tables.
		'openconnector_synchronization_contracts',
		'openconnector_synchronizations',
		'openconnector_event_subscriptions',
		'openconnector_events',
		// Leaf tables (no inter-openconnector FKs).
		'openconnector_endpoints',
		'openconnector_sources',
		'openconnector_jobs',
		'openconnector_mappings',
		'openconnector_rules',
		'openconnector_consumers',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves LegacyToRegisterMigrator lazily,
	 *                                      so an instance whose legacy tables are
	 *                                      already gone never builds it.
	 * @param IAppConfig $appConfig Reads and writes `storage_migrated`.
	 * @param IDBConnection $db Used to count rows in the legacy tables.
	 * @param LoggerInterface $logger Non-fatal diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Human-readable name surfaced by `occ` during install / upgrade.
	 *
	 * @return string The step name.
	 */
	public function getName(): string {
		return 'Complete the OpenConnector legacy → OpenRegister storage cutover';
	}//end getName()

	/**
	 * Copy any remaining legacy rows, then declare the cutover complete.
	 *
	 * @param IOutput $output Progress channel piped to occ stdout.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, 'storage_migrated', 'false') === 'true') {
			$output->info('OpenConnector: storage_migrated is already true — nothing to do.');
			return;
		}

		$remaining = $this->tablesHoldingRows(output: $output);

		// -1 is "at least one count FAILED", not "one table". The contents of
		// an uncountable table are unknown, and declaring the cutover complete
		// over unknown data would strand those rows outside OpenRegister with
		// nothing left pointing at them.
		if ($remaining === -1) {
			$output->warning(
				'OpenConnector: could not count every legacy table, so the cutover cannot be declared'
				. ' complete. Leaving storage_migrated untouched; re-run `occ maintenance:repair` once'
				. ' the database is reachable.'
			);
			return;
		}

		if ($remaining === 0) {
			$this->appConfig->setValueString(Application::APP_ID, 'storage_migrated', 'true');
			$output->info(
				'OpenConnector: no legacy table holds rows — storage_migrated set to true.'
				. ' Sync-contract provenance ("Synced from") is enabled.'
			);
			return;
		}

		$output->info(
			sprintf('OpenConnector: %d legacy table(s) still hold rows — running the row migration.', $remaining)
		);

		$this->migrateRemainingRows(output: $output);

	}//end run()

	/**
	 * How many legacy tables still exist AND hold at least one row.
	 *
	 * A table that no longer exists is not a gap: `Version2Date20260520000099`
	 * drops each one once it is empty, and on a fresh install the whole set is
	 * dropped in the same schema pass that created it, so a first install
	 * legitimately finds none of them.
	 *
	 * @param IOutput $output Progress channel.
	 *
	 * @return integer The number of non-empty tables, or -1 when any count failed.
	 */
	private function tablesHoldingRows(IOutput $output): int {
		$nonEmpty = 0;

		foreach (self::LEGACY_TABLES as $table) {
			try {
				if ($this->db->tableExists($table) === false) {
					continue;
				}

				// `fetchOne()`, not `fetchAssociative()`: the latter only
				// reached `OCP\DB\IResult` in Nextcloud 33, and this app
				// advertises `min-version="32"`. On 32 it raises an Error that
				// the catch below would turn into "cannot count", which reads
				// as a database problem rather than a missing method.
				$qb = $this->db->getQueryBuilder();
				$count = $qb->select($qb->func()->count('*', 'c'))->from($table)->executeQuery()->fetchOne();

				// A COUNT always has one row, so `false` means the read
				// produced nothing. `(int) false` is 0, which would be read
				// as an empty table and let the cutover be declared complete
				// over contents nobody measured.
				if ($count === false) {
					$output->warning(sprintf('OpenConnector: legacy table `%s` returned no count.', $table));
					return -1;
				}
			} catch (\Throwable $e) {
				$output->warning(
					sprintf('OpenConnector: could not count legacy table `%s`: %s', $table, $e->getMessage())
				);
				$this->logger->warning(
					sprintf('OpenConnector: legacy row count failed for %s', $table),
					['exception' => $e->getMessage()]
				);
				return -1;
			}//end try

			if (((int)$count) > 0) {
				$nonEmpty++;
			}
		}//end foreach

		return $nonEmpty;
	}//end tablesHoldingRows()

	/**
	 * Hand the remaining rows to the migrator, which flips the flag itself.
	 *
	 * Deliberately does NOT set the flag on its own afterwards: `migrateAll()`
	 * only sets it on a full, non-dry, skip-free run, and second-guessing that
	 * here would declare a cutover complete over rows it reported as skipped.
	 *
	 * @param IOutput $output Progress channel.
	 *
	 * @return void
	 */
	private function migrateRemainingRows(IOutput $output): void {
		try {
			$migrator = $this->container->get(LegacyToRegisterMigrator::class);
		} catch (\Throwable $e) {
			$output->warning(
				'OpenConnector: could not resolve the legacy migrator (' . $e->getMessage() . ').'
				. ' Run `occ openconnector:migrate-storage` once OpenRegister is enabled.'
			);
			$this->logger->error(
				'OpenConnector: LegacyToRegisterMigrator resolution failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$results = $migrator->migrateAll(dryRun: false, entitySlug: null, batchSize: 10000);
		} catch (\Throwable $e) {
			$output->warning('OpenConnector: the legacy row migration failed: ' . $e->getMessage());
			$this->logger->error(
				'OpenConnector: migrateAll() failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		foreach ($results as $perEntity) {
			$output->info(
				sprintf(
					'  %s: legacy=%d migrated=%d skipped=%d',
					($perEntity['slug'] ?? '?'),
					(int)($perEntity['legacyCount'] ?? 0),
					(int)($perEntity['migratedCount'] ?? 0),
					(int)($perEntity['skipped'] ?? 0)
				)
			);
		}

		if ($this->appConfig->getValueString(Application::APP_ID, 'storage_migrated', 'false') === 'true') {
			$output->info('OpenConnector: storage_migrated set to true — the cutover is complete.');
			return;
		}

		$output->warning(
			'OpenConnector: storage_migrated NOT set — at least one entity reported skips or errors.'
			. ' Use `occ openconnector:migrate-storage` to retry per-entity.'
		);

	}//end migrateRemainingRows()
}//end class
