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
use OCP\IAppConfig;
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
     * How many legacy tables still held rows when `changeSchema()` ran.
     *
     * Carried between `changeSchema()` and `postSchemaChange()` because
     * Nextcloud calls both on the SAME instance, and the row counts can only
     * be taken in `changeSchema()` (against live, committed data) while the
     * app-config write must happen after the schema change has been applied.
     *
     * Starts at -1 meaning "not measured yet", which is deliberately distinct
     * from 0 ("measured, nothing left"): if `changeSchema()` never ran we must
     * NOT conclude the cutover is complete.
     *
     * @var integer
     */
    private int $legacyTablesWithRows = -1;

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
     *
     * @spec openspec/specs/synchronization-engine/spec.md
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
            // Anything other than a definitive zero must NOT be dropped.
            // countRows() returns -1 when the COUNT query itself failed, and its
            // contract says that is "non-empty / unsafe to drop". A `> 0` test
            // does not honour that: -1 is not greater than 0, so a FAILED count
            // fell straight through to dropTable() and destroyed a table whose
            // contents were unknown — the exact opposite of the documented
            // safety behaviour, and of the warning the same method logs.
            if ($rowCount !== 0) {
                // -1 means the COUNT query failed, so the table's contents are
                // UNKNOWN. Say that, rather than reporting "-1 row(s)", which
                // reads like a count and hides that nothing was measured.
                $detail = sprintf('has %d row(s)', $rowCount);
                if ($rowCount < 0) {
                    $detail = 'could not be counted (the COUNT query failed), so its contents are UNKNOWN';
                }//end if

                $output->warning(
                    sprintf(
                        'chain-B/C cleanup: legacy table `oc_%s` %s — SKIPPED.'
                        .' Verify the chain-C cutover migrated all rows into OR storage,'
                        .' then TRUNCATE the table manually and re-run `occ upgrade`.',
                        $tableShort,
                        $detail
                    )
                );
                $logger->warning(
                    sprintf(
                        'chain-B/C cleanup: skipping legacy table %s — %s',
                        $tableShort,
                        $detail
                    )
                );
                $skippedNonEmpty++;
                continue;
            }//end if

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

        // Hand the safety-gate result to postSchemaChange(), which decides
        // whether the cutover can be declared complete.
        $this->legacyTablesWithRows = $skippedNonEmpty;

        return $schema;

    }//end changeSchema()

    /**
     * Post-schema change callback — assert `openconnector.storage_migrated`.
     *
     * This method used to be a no-op, on the premise that the flag "stays set
     * to 'true'" from {@see Version2Date20260520000001}. That premise holds
     * only for an instance that was UPGRADED across the cutover with legacy
     * rows to copy. On a FRESH install it is false, and the consequence is a
     * silent, permanent feature outage:
     *
     *   * Migration ...0001 bails out early (`return`, flag untouched) when
     *     OpenRegister is not yet loadable — routine during `occ app:enable`
     *     on a clean instance, where openconnector's migrations run before
     *     openregister has been set up.
     *   * Even when it does complete, a fresh instance has no legacy rows, so
     *     the "all 15 entities copied" branch it needs to reach describes an
     *     event that never happens.
     *
     * So `storage_migrated` stays at its 'false' default forever, and
     * {@see \OCA\OpenConnector\Service\Integration\SynchronizationContractProvider::isEnabled()}
     * returns false forever: "Synced from" provenance never appears on ANY
     * fresh install. It fails closed and silently — the leaf simply is not
     * there, which is indistinguishable from having no contracts to show.
     * The e2e suite could not catch it either: `synced-from-leaf.spec.ts` and
     * `migration-round-trip.spec.ts` both `test.skip()` when the flag is
     * false, so six specs reported as skipped-and-green on every CI run.
     *
     * The flag's real meaning is "openconnector's data lives in OpenRegister,
     * not in the legacy tables". THIS migration is the point at which that
     * becomes true by construction: it is the chain-B/C cleanup, and it only
     * reaches here after its safety gate has confirmed no legacy table still
     * holds rows. Zero remaining rows means the cutover is complete — whether
     * because ...0001 copied them, or because there were never any to copy.
     *
     * Conservative on purpose: if ANY legacy table still has rows (an undrained
     * rollback buffer, or a post-cutover write) the cutover is NOT complete and
     * the flag is left alone, exactly as before. Same if `changeSchema()` never
     * ran to measure it.
     *
     * Idempotent: re-running writes the same value.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     *
     * @spec openspec/specs/synchronization-engine/spec.md
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        if ($this->legacyTablesWithRows !== 0) {
            $reason = sprintf('%d legacy table(s) still hold rows', $this->legacyTablesWithRows);
            if ($this->legacyTablesWithRows < 0) {
                $reason = 'legacy row counts were never measured';
            }

            $output->warning(
                sprintf('chain-B/C cleanup: %s — leaving `storage_migrated` untouched.', $reason)
            );
            return;
        }

        $appConfig = \OC::$server->get(IAppConfig::class);
        if ($appConfig->getValueString('openconnector', 'storage_migrated', 'false') === 'true') {
            $output->info('chain-B/C cleanup: `storage_migrated` already true — nothing to do.');
            return;
        }

        $appConfig->setValueString('openconnector', 'storage_migrated', 'true');
        $output->info(
            'chain-B/C cleanup: no legacy table holds rows — set `storage_migrated=true`.'
            .' Sync-contract provenance ("Synced from") is now enabled.'
        );

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
