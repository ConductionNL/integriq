<?php
/**
 * OpenConnector Settings Service
 *
 * This file contains the service class for handling settings in the OpenConnector application.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCP\IDBConnection;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving database statistics and
 * system information for the OpenConnector application.
 */
class SettingsService
{

    /**
     * This property holds the name of the application, which is used for identification and configuration purposes.
     *
     * @var string $appName The name of the app.
     */
    private string $appName;


    /**
     * SettingsService constructor.
     *
     * @param IDBConnection    $db     Database connection for optimized queries.
     * @param IAppConfig       $config App configuration interface for settings storage.
     * @param LoggerInterface  $logger Logger interface for error handling.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger
    ) {
        // Set the application name for identification and configuration purposes.
        $this->appName = 'openconnector';

    }//end __construct()


    /**
     * Get comprehensive statistics for the settings dashboard.
     *
     * This method provides warning counts for items that need attention,
     * as well as total counts for all OpenConnector tables using optimized SQL queries.
     *
     * @return array Array containing warning counts and total counts for all tables
     * @throws \RuntimeException If statistics retrieval fails
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'warnings'    => [
                    'callLogsWithoutExpiry'           => 0,
                    'eventMessagesWithoutExpiry'      => 0,
                    'jobLogsWithoutExpiry'            => 0,
                    'syncContractLogsWithoutExpiry'   => 0,
                    'syncLogsWithoutExpiry'           => 0,
                    'expiredCallLogs'                 => 0,
                    'expiredEventMessages'            => 0,
                    'expiredJobLogs'                  => 0,
                    'expiredSyncContractLogs'         => 0,
                    'expiredSyncLogs'                 => 0,
                ],
                'totals'      => [
                    'totalCallLogs'                   => 0,
                    'totalConsumers'                  => 0,
                    'totalEndpoints'                  => 0,
                    'totalEventMessages'              => 0,
                    'totalEventSubscriptions'         => 0,
                    'totalEvents'                     => 0,
                    'totalJobLogs'                    => 0,
                    'totalJobs'                       => 0,
                    'totalMappings'                   => 0,
                    'totalRules'                      => 0,
                    'totalSources'                    => 0,
                    'totalSynchronizationContractLogs' => 0,
                    'totalSynchronizationContracts'   => 0,
                    'totalSynchronizationLogs'        => 0,
                    'totalSynchronizations'           => 0,
                ],
                'sizes'       => [
                    'totalCallLogsSize'               => 0,
                    'totalEventMessagesSize'          => 0,
                    'totalJobLogsSize'                => 0,
                    'totalSyncContractLogsSize'       => 0,
                    'totalSyncLogsSize'               => 0,
                    'expiredCallLogsSize'             => 0,
                    'expiredEventMessagesSize'        => 0,
                    'expiredJobLogsSize'              => 0,
                    'expiredSyncContractLogsSize'     => 0,
                    'expiredSyncLogsSize'             => 0,
                ],
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            // **OPTIMIZED QUERIES**: Use direct SQL COUNT queries for maximum performance

            // All tables - simple counts (OpenConnector tables don't have size/expires columns like OpenRegister).
            // Table names are left unquoted so the count works across MySQL/MariaDB, PostgreSQL and SQLite
            // (backtick quoting is MySQL-only and errors on PostgreSQL).
            $allTables = [
                'callLogs' => '*PREFIX*openconnector_call_logs',
                'consumers' => '*PREFIX*openconnector_consumers',
                'endpoints' => '*PREFIX*openconnector_endpoints',
                'eventMessages' => '*PREFIX*openconnector_event_messages',
                'eventSubscriptions' => '*PREFIX*openconnector_event_subscriptions',
                'events' => '*PREFIX*openconnector_events',
                'jobLogs' => '*PREFIX*openconnector_job_logs',
                'jobs' => '*PREFIX*openconnector_jobs',
                'mappings' => '*PREFIX*openconnector_mappings',
                'rules' => '*PREFIX*openconnector_rules',
                'sources' => '*PREFIX*openconnector_sources',
                'synchronizationContractLogs' => '*PREFIX*openconnector_synchronization_contract_logs',
                'synchronizationContracts' => '*PREFIX*openconnector_synchronization_contracts',
                'synchronizationLogs' => '*PREFIX*openconnector_synchronization_logs',
                'synchronizations' => '*PREFIX*openconnector_synchronizations',
            ];

            foreach ($allTables as $key => $tableName) {
                try {
                    $countQuery = "SELECT COUNT(*) as total FROM {$tableName}";
                    $result = $this->db->executeQuery($countQuery);
                    $count = $result->fetchColumn();
                    $result->closeCursor();

                    $stats['totals']['total' . ucfirst($key)] = (int) ($count ?? 0);
                } catch (\Exception $e) {
                    // Table might not exist, set to 0 and continue
                    $stats['totals']['total' . ucfirst($key)] = 0;
                    $this->logger->debug('Table does not exist or query failed', [
                        'table' => $tableName,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve statistics', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Failed to retrieve statistics: '.$e->getMessage());
        }//end try

    }//end getStats()


    /**
     * Retrieve the current retention settings.
     *
     * @return array The current retention settings configuration.
     * @throws \RuntimeException If settings retrieval fails.
     */
    public function getSettings(): array
    {
        try {
            $data = [];

            // Version information
            $data['version'] = [
                'appName'    => 'Open Connector',
                'appVersion' => '0.2.0',
            ];

            // Retention Settings with defaults
            $retentionConfig = $this->config->getValueString($this->appName, 'retention', '');
            if (empty($retentionConfig)) {
                $data['retention'] = [
                    'successLogRetention'          => 3600000,     // 1 Hour default
                    'callLogRetention'             => 2592000000,  // 1 month default
                    'eventMessageRetention'        => 604800000,   // 1 week default
                    'jobLogRetention'              => 2592000000,  // 1 month default
                    'syncContractLogRetention'     => 7776000000,  // 3 months default
                    'syncLogRetention'             => 2592000000,  // 1 month default
                ];
            } else {
                $retentionData     = json_decode($retentionConfig, true);
                $data['retention'] = [
                    'successLogRetention'          => $retentionData['successLogRetention'] ?? 3600000,
                    'callLogRetention'             => $retentionData['callLogRetention'] ?? 2592000000,
                    'eventMessageRetention'        => $retentionData['eventMessageRetention'] ?? 604800000,
                    'jobLogRetention'              => $retentionData['jobLogRetention'] ?? 2592000000,
                    'syncContractLogRetention'     => $retentionData['syncContractLogRetention'] ?? 7776000000,
                    'syncLogRetention'             => $retentionData['syncLogRetention'] ?? 2592000000,
                ];
            }//end if

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve settings', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Failed to retrieve settings: '.$e->getMessage());
        }//end try

    }//end getSettings()


    /**
     * Update the retention settings configuration.
     *
     * @param array $data The settings data to update.
     *
     * @return array The updated settings configuration.
     * @throws \RuntimeException If settings update fails.
     */
    public function updateSettings(array $data): array
    {
        try {
            // Handle Retention settings
            if (isset($data['retention'])) {
                $retentionData   = $data['retention'];
                $retentionConfig = [
                    'successLogRetention'          => $retentionData['successLogRetention'] ?? 3600000,
                    'callLogRetention'             => $retentionData['callLogRetention'] ?? 2592000000,
                    'eventMessageRetention'        => $retentionData['eventMessageRetention'] ?? 604800000,
                    'jobLogRetention'              => $retentionData['jobLogRetention'] ?? 2592000000,
                    'syncContractLogRetention'     => $retentionData['syncContractLogRetention'] ?? 7776000000,
                    'syncLogRetention'             => $retentionData['syncLogRetention'] ?? 2592000000,
                ];
                $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            }

            // Return the updated settings
            return $this->getSettings();
        } catch (\Exception $e) {
            $this->logger->error('Failed to update settings', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Failed to update settings: '.$e->getMessage());
        }//end try

    }//end updateSettings()


    /**
     * Rebase all logs with current retention settings.
     *
     * This method sets expiry dates for all logs based on current retention settings,
     * using database operations for optimal performance.
     *
     * @return array Array containing the rebase operation results
     * @throws \RuntimeException If the rebase operation fails
     */
    public function rebase(): array
    {
        try {
            $startTime = new \DateTime();
            $results   = [
                'startTime'        => $startTime,
                'retentionResults' => [],
                'errors'           => [],
            ];

            // Get current settings
            $settings = $this->getSettings();
            $retention = $settings['retention'] ?? [];

            // **DATABASE-OPTIMIZED REBASE**: Use direct SQL UPDATE queries for maximum performance

            // 0. Update successful logs expiry dates
            if (isset($retention['successLogRetention']) === true && $retention['successLogRetention'] > 0) {
                try {
                    $results['retentionResults']['callLogsUpdated'] = $this->setExpiryDates(
                        'openconnector_call_logs',
                        (int) $retention['successLogRetention']
                    );
                } catch (\Exception $e) {
                    $error = 'Failed to set call logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 1. Update call logs expiry dates
            if (isset($retention['callLogRetention']) && $retention['callLogRetention'] > 0) {
                try {
                    $results['retentionResults']['callLogsUpdated'] = $this->setExpiryDates(
                        'openconnector_call_logs',
                        (int) $retention['callLogRetention']
                    );
                } catch (\Exception $e) {
                    $error = 'Failed to set call logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 2. Update event messages expiry dates (skip if expires column doesn't exist)
            if (isset($retention['eventMessageRetention']) && $retention['eventMessageRetention'] > 0) {
                try {
                    if ($this->columnExists('openconnector_event_messages', 'expires') === true) {
                        $results['retentionResults']['eventMessagesUpdated'] = $this->setExpiryDates(
                            'openconnector_event_messages',
                            (int) $retention['eventMessageRetention']
                        );
                    } else {
                        $results['retentionResults']['eventMessagesUpdated'] = 'Column expires not found - skipped';
                    }
                } catch (\Exception $e) {
                    $error = 'Failed to set event messages expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 3. Update job logs expiry dates
            if (isset($retention['jobLogRetention']) && $retention['jobLogRetention'] > 0) {
                try {
                    $results['retentionResults']['jobLogsUpdated'] = $this->setExpiryDates(
                        'openconnector_job_logs',
                        (int) $retention['jobLogRetention']
                    );
                } catch (\Exception $e) {
                    $error = 'Failed to set job logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 4. Update synchronization contract logs expiry dates (handle empty/missing created values)
            if (isset($retention['syncContractLogRetention']) && $retention['syncContractLogRetention'] > 0) {
                try {
                    $results['retentionResults']['syncContractLogsUpdated'] = $this->setExpiryDates(
                        'openconnector_synchronization_contract_logs',
                        (int) $retention['syncContractLogRetention'],
                        coalesceCreatedWithNow: true,
                        rebaseExisting: true
                    );
                } catch (\Exception $e) {
                    $error = 'Failed to set sync contract logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 5. Update synchronization logs expiry dates (handle empty/missing created values)
            if (isset($retention['syncLogRetention']) && $retention['syncLogRetention'] > 0) {
                try {
                    $results['retentionResults']['syncLogsUpdated'] = $this->setExpiryDates(
                        'openconnector_synchronization_logs',
                        (int) $retention['syncLogRetention'],
                        coalesceCreatedWithNow: true,
                        rebaseExisting: true
                    );
                } catch (\Exception $e) {
                    $error = 'Failed to set sync logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            $results['endTime']  = new \DateTime();
            $results['duration'] = $results['endTime']->diff($startTime)->format('%H:%I:%S');
            $results['success']  = empty($results['errors']);

            $this->logger->info('Rebase operation completed', [
                'duration' => $results['duration'],
                'success' => $results['success'],
                'results' => $results['retentionResults'],
                'errors' => $results['errors']
            ]);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Rebase operation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Rebase operation failed: '.$e->getMessage());
        }//end try

    }//end rebase()


    /**
     * Set expiry dates on a log table in a database-agnostic way.
     *
     * Computes `expires = <base> + retention` for rows that still need an expiry,
     * using the correct date-arithmetic syntax for the active database platform
     * (MySQL/MariaDB, PostgreSQL or SQLite). Replaces the previous MySQL-only
     * DATE_ADD/backtick query so the rebase works on PostgreSQL too.
     *
     * @param string $table                  Unprefixed table name.
     * @param int    $retentionMs            Retention period in milliseconds.
     * @param bool   $coalesceCreatedWithNow Fall back to the current time when `created` is null.
     * @param bool   $rebaseExisting         Also (re)set rows that already have a `created` value.
     *
     * @return int Number of affected rows.
     *
     * @throws \OCP\DB\Exception On query failure.
     */
    private function setExpiryDates(
        string $table,
        int $retentionMs,
        bool $coalesceCreatedWithNow = false,
        bool $rebaseExisting = false
    ): int {
        $provider = $this->db->getDatabaseProvider();
        $micros   = $retentionMs * 1000;

        // PostgreSQL and SQLite cannot compare a timestamp column to '' or a zero-date,
        // so those legacy MySQL conditions are only added for the MySQL family.
        $isMysqlFamily = in_array(
            $provider,
            [IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_SQLITE],
            true
        ) === false;

        // Current-time expression for tables whose `created` may be null.
        $now  = $provider === IDBConnection::PLATFORM_SQLITE ? "datetime('now')" : 'NOW()';
        $base = $coalesceCreatedWithNow === true ? "COALESCE(created, $now)" : 'created';

        // Platform-specific "base + retention" date arithmetic. The retention value is
        // always bound as a single parameter; only the surrounding syntax differs.
        switch ($provider) {
            case IDBConnection::PLATFORM_POSTGRES:
                // Cast the bound parameter so PostgreSQL multiplies a number, not text.
                $expiresExpr = "$base + (CAST(? AS bigint) * INTERVAL '1 microsecond')";
                break;
            case IDBConnection::PLATFORM_SQLITE:
                $expiresExpr = "datetime($base, '+' || (? / 1000000.0) || ' seconds')";
                break;
            default: // MySQL / MariaDB
                $expiresExpr = "DATE_ADD($base, INTERVAL ? MICROSECOND)";
                break;
        }

        // `expires IS NULL` is portable; the empty-string and zero-date checks are MySQL-only.
        $conditions = ['expires IS NULL'];
        if ($isMysqlFamily === true) {
            $conditions[] = "expires = ''";
            $conditions[] = "expires = '0000-00-00 00:00:00'";
        }
        if ($rebaseExisting === true) {
            $conditions[] = 'created IS NOT NULL';
        }

        $sql  = 'UPDATE *PREFIX*'.$table.' SET expires = '.$expiresExpr.' WHERE '.implode(' OR ', $conditions);
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$micros]);

        return $stmt->rowCount();

    }//end setExpiryDates()


    /**
     * Check whether a column exists on a table, portably across database platforms.
     *
     * Runs a guarded `SELECT <column> ... LIMIT 1`; if the column is absent the query
     * throws and we report it as missing. This replaces the MySQL-only `SHOW COLUMNS`.
     *
     * @param string $table  Unprefixed table name.
     * @param string $column Column name to check.
     *
     * @return bool True when the column exists.
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($column)
                ->from($table)
                ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            return true;
        } catch (\Exception $e) {
            return false;
        }

    }//end columnExists()


}//end class
