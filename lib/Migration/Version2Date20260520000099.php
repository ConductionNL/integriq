<?php
/**
 * Chain B/C cleanup — drop legacy oc_openconnector_* tables when empty.
 *
 * Runs after {@see Version2Date20260520000001} has materialised the OR
 * register + run LegacyToRegisterMigrator. That earlier migration COPIES
 * rows from `oc_openconnector_*` legacy tables into `oc_openregister_objects`
 * but deliberately leaves the legacy tables in place as a rollback buffer
 * (per chain B/C spec — operators verify the OR-backed data before draining
 * the legacy rows).
 *
 * Post-verification, operators TRUNCATE the legacy tables (knowing OR has
 * authoritative copies). On the next `occ upgrade`, THIS migration sweeps
 * the now-empty tables out of the database.
 *
 * Safety gate: each legacy table is dropped ONLY if its row count is
 * zero. Non-empty tables are LEFT IN PLACE with a warning logged — the
 * operator is expected to investigate (someone wrote to the legacy table
 * post-cutover, or the rollback buffer hasn't been drained yet). Re-running
 * `occ upgrade` after draining drops the remaining ones.
 *
 * Idempotent: tables that have already been dropped are skipped silently.
 *
 * Cross-ref:
 *   - GH #820 (legacy table cleanup follow-up).
 *   - lib/Migration/Version2Date20260520000001.php (chain B data migration).
 *   - openspec/changes/openconnector-services-direct-or-usage/proposal.md § cleanup.
 *
 * @category Migration
 * @package  OCA\OpenConnector\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Drops legacy oc_openconnector_* tables once they are empty.
 */
class Version2Date20260520000099 extends SimpleMigrationStep
{

    /**
     * The 15 legacy openconnector tables. Each was the storage backing for
     * one openconnector entity pre chain-B/C cutover. Post-cutover, data
     * lives in oc_openregister_objects keyed by register=openconnector +
     * schema=<slug>.
     *
     * Order: drop dependent tables first (logs, contract_logs) then
     * referenced tables (sync, source) — matters only if FK constraints
     * exist on the legacy tables (they generally don't on Nextcloud apps,
     * but the order is defensive in case Doctrine added them on Postgres).
     *
     * @var array<int, string>
     */
    private const LEGACY_TABLES = [
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
     * No-op pre-schema callback.
     *
     * Nextcloud's MigrationService::createInstance() uses `new $class()` —
     * migrations cannot have constructor parameters. Dependencies resolved
     * via service container inside the methods that need them.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No-op. Row-count checks happen in changeSchema so the safety
        // gate runs against the live (committed) data.
    }//end preSchemaChange()

    /**
     * Drop each legacy table when (a) it exists and (b) it has zero rows.
     *
     * Non-empty tables are skipped with a warning — operator must drain
     * them before the next `occ upgrade` re-attempts the drop.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema wrapper.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $db     = \OC::$server->get(IDBConnection::class);
        $logger = \OC::$server->get(LoggerInterface::class);

        $dropped         = 0;
        $skippedNonEmpty = 0;
        $skippedAbsent   = 0;

        foreach (self::LEGACY_TABLES as $tableShort) {
            if ($schema->hasTable($tableShort) === false) {
                $skippedAbsent++;
                continue;
            }

            $rowCount = $this->countRows(db: $db, logger: $logger, tableShort: $tableShort);
            if ($rowCount > 0) {
                $output->warning(
                    sprintf(
                        'chain-B/C cleanup: legacy table `oc_%s` has %d row(s) — SKIPPED.'
                        .' Verify the chain-C cutover migrated all rows into OR storage,'
                        .' then TRUNCATE the table manually and re-run `occ upgrade`.',
                        $tableShort,
                        $rowCount
                    )
                );
                $logger->warning(
                    sprintf(
                        'chain-B/C cleanup: skipping non-empty legacy table %s (%d rows)',
                        $tableShort,
                        $rowCount
                    )
                );
                $skippedNonEmpty++;
                continue;
            }

            $schema->dropTable($tableShort);
            $output->info(sprintf('chain-B/C cleanup: dropped empty legacy table `oc_%s`', $tableShort));
            $dropped++;
        }//end foreach

        $output->info(
            sprintf(
                'chain-B/C cleanup summary: %d dropped, %d skipped-non-empty, %d already-absent (of %d legacy tables)',
                $dropped,
                $skippedNonEmpty,
                $skippedAbsent,
                count(self::LEGACY_TABLES)
            )
        );

        return $schema;

    }//end changeSchema()

    /**
     * Post-schema change callback.
     *
     * The `openconnector.storage_migrated` IAppConfig flag stays set to
     * 'true'. It still serves as a marker that the cutover ran (read by
     * SynchronizationContractProvider::isEnabled() among others).
     * Deleting it would be backwards-incompatible.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Intentional no-op — see method docblock.
    }//end postSchemaChange()

    /**
     * Count rows in a legacy table.
     *
     * @param IDBConnection   $db         Database connection.
     * @param LoggerInterface $logger     Logger used to record query failures.
     * @param string          $tableShort The unprefixed legacy table name.
     *
     * @return int Row count, or -1 on query error (treated as "non-empty / unsafe to drop" by the safety gate).
     */
    private function countRows(IDBConnection $db, LoggerInterface $logger, string $tableShort): int
    {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'c'))->from($tableShort);
            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();
            return (int) ($row['c'] ?? -1);
        } catch (\Throwable $e) {
            $logger->warning(
                sprintf(
                    'chain-B/C cleanup: failed to count rows in %s — treating as non-empty for safety. %s',
                    $tableShort,
                    $e->getMessage()
                )
            );
            return -1;
        }

    }//end countRows()
}//end class
