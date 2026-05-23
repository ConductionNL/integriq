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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCP\IDBConnection;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving database statistics and
 * system information for the OpenConnector application.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.MissingImport)
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
     * @param IDBConnection   $db     Database connection for optimized queries.
     * @param IAppConfig      $config App configuration interface for settings storage.
     * @param LoggerInterface $logger Logger interface for error handling.
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
     * Build a platform-portable SQL expression that adds a microsecond interval
     * to a column value.
     *
     * The microsecond value is bound as a `?` placeholder. Returns the expression
     * as a string ready to splice into SQL.
     *
     * - MySQL/MariaDB:  `DATE_ADD(<column>, INTERVAL ? MICROSECOND)`.
     * - PostgreSQL:     `<column> + (? || ' microseconds')::interval`.
     *
     * Fixes GH #822 (Postgres portability of rebase()'s SQL).
     *
     * @param string $createdColumn The column expression to add the interval to.
     *
     * @return string The SQL expression.
     */
    private function expiresExpression(string $createdColumn): string
    {
        $platform = $this->db->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('%s + (? || \' microseconds\')::interval', $createdColumn);
        }

        return sprintf('DATE_ADD(%s, INTERVAL ? MICROSECOND)', $createdColumn);
    }//end expiresExpression()

    /**
     * Portable replacement for `SHOW COLUMNS FROM <table> LIKE 'X'`.
     *
     * Returns true when the named column exists on the named table.
     * Fixes GH #822 (Postgres portability of rebase()'s column probe).
     *
     * @param string $unprefixedTable The Nextcloud table name without the `oc_` prefix.
     * @param string $column          The column name to check.
     *
     * @return bool True when the column exists, false otherwise.
     */
    private function columnExists(string $unprefixedTable, string $column): bool
    {
        $platform = $this->db->getDatabasePlatform();
        try {
            if ($platform instanceof PostgreSQLPlatform) {
                $stmt = $this->db->prepare(
                    'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1'
                );
                $stmt->execute(['oc_'.$unprefixedTable, $column]);
            } else {
                $sql  = sprintf(
                    "SHOW COLUMNS FROM `*PREFIX*%s` LIKE %s",
                    $unprefixedTable,
                    $this->db->quote($column)
                );
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
            }

            $row = $stmt->fetch();
            return $row !== false;
        } catch (\Throwable) {
            // If the table itself doesn't exist (legacy table dropped post #820),
            // the column doesn't exist either.
            return false;
        }//end try
    }//end columnExists()

    /**
     * Get comprehensive statistics for the settings dashboard.
     *
     * This method provides warning counts for items that need attention,
     * as well as total counts for all OpenConnector tables using optimized SQL queries.
     *
     * @return array Array containing warning counts and total counts for all tables.
     *
     * @throws \RuntimeException If statistics retrieval fails.
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'warnings'    => [
                    'callLogsWithoutExpiry'         => 0,
                    'eventMessagesWithoutExpiry'    => 0,
                    'jobLogsWithoutExpiry'          => 0,
                    'syncContractLogsWithoutExpiry' => 0,
                    'syncLogsWithoutExpiry'         => 0,
                    'expiredCallLogs'               => 0,
                    'expiredEventMessages'          => 0,
                    'expiredJobLogs'                => 0,
                    'expiredSyncContractLogs'       => 0,
                    'expiredSyncLogs'               => 0,
                ],
                'totals'      => [
                    'totalCallLogs'                    => 0,
                    'totalConsumers'                   => 0,
                    'totalEndpoints'                   => 0,
                    'totalEventMessages'               => 0,
                    'totalEventSubscriptions'          => 0,
                    'totalEvents'                      => 0,
                    'totalJobLogs'                     => 0,
                    'totalJobs'                        => 0,
                    'totalMappings'                    => 0,
                    'totalRules'                       => 0,
                    'totalSources'                     => 0,
                    'totalSynchronizationContractLogs' => 0,
                    'totalSynchronizationContracts'    => 0,
                    'totalSynchronizationLogs'         => 0,
                    'totalSynchronizations'            => 0,
                ],
                'sizes'       => [
                    'totalCallLogsSize'           => 0,
                    'totalEventMessagesSize'      => 0,
                    'totalJobLogsSize'            => 0,
                    'totalSyncContractLogsSize'   => 0,
                    'totalSyncLogsSize'           => 0,
                    'expiredCallLogsSize'         => 0,
                    'expiredEventMessagesSize'    => 0,
                    'expiredJobLogsSize'          => 0,
                    'expiredSyncContractLogsSize' => 0,
                    'expiredSyncLogsSize'         => 0,
                ],
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            // **OPTIMIZED QUERIES**: Use direct SQL COUNT queries for maximum performance.
            // All tables - simple counts (OpenConnector tables don't have size/expires columns like OpenRegister).
            $allTables = [
                'callLogs'                    => '`*PREFIX*openconnector_call_logs`',
                'consumers'                   => '`*PREFIX*openconnector_consumers`',
                'endpoints'                   => '`*PREFIX*openconnector_endpoints`',
                'eventMessages'               => '`*PREFIX*openconnector_event_messages`',
                'eventSubscriptions'          => '`*PREFIX*openconnector_event_subscriptions`',
                'events'                      => '`*PREFIX*openconnector_events`',
                'jobLogs'                     => '`*PREFIX*openconnector_job_logs`',
                'jobs'                        => '`*PREFIX*openconnector_jobs`',
                'mappings'                    => '`*PREFIX*openconnector_mappings`',
                'rules'                       => '`*PREFIX*openconnector_rules`',
                'sources'                     => '`*PREFIX*openconnector_sources`',
                'synchronizationContractLogs' => '`*PREFIX*openconnector_synchronization_contract_logs`',
                'synchronizationContracts'    => '`*PREFIX*openconnector_synchronization_contracts`',
                'synchronizationLogs'         => '`*PREFIX*openconnector_synchronization_logs`',
                'synchronizations'            => '`*PREFIX*openconnector_synchronizations`',
            ];

            foreach ($allTables as $key => $tableName) {
                try {
                    $countQuery = "SELECT COUNT(*) as total FROM {$tableName}";
                    $result     = $this->db->executeQuery($countQuery);
                    $count      = $result->fetchColumn();
                    $result->closeCursor();

                    $stats['totals']['total'.ucfirst($key)] = (int) ($count ?? 0);
                } catch (\Exception $e) {
                    // Table might not exist, set to 0 and continue.
                    $stats['totals']['total'.ucfirst($key)] = 0;
                    $this->logger->debug(
                            'Table does not exist or query failed',
                            [
                                'table' => $tableName,
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to retrieve statistics',
                    [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
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

            // Version information.
            $data['version'] = [
                'appName'    => 'Open Connector',
                'appVersion' => '0.2.0',
            ];

            // Retention settings with defaults.
            $data['retention'] = [
                // 1 Hour default.
                'successLogRetention'      => 3600000,
                // 1 month default.
                'callLogRetention'         => 2592000000,
                // 1 week default.
                'eventMessageRetention'    => 604800000,
                // 1 month default.
                'jobLogRetention'          => 2592000000,
                // 3 months default.
                'syncContractLogRetention' => 7776000000,
                // 1 month default.
                'syncLogRetention'         => 2592000000,
            ];

            $retentionConfig = $this->config->getValueString($this->appName, 'retention', '');
            if (empty($retentionConfig) === false) {
                $retentionData     = json_decode($retentionConfig, true);
                $data['retention'] = [
                    'successLogRetention'      => $retentionData['successLogRetention'] ?? 3600000,
                    'callLogRetention'         => $retentionData['callLogRetention'] ?? 2592000000,
                    'eventMessageRetention'    => $retentionData['eventMessageRetention'] ?? 604800000,
                    'jobLogRetention'          => $retentionData['jobLogRetention'] ?? 2592000000,
                    'syncContractLogRetention' => $retentionData['syncContractLogRetention'] ?? 7776000000,
                    'syncLogRetention'         => $retentionData['syncLogRetention'] ?? 2592000000,
                ];
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to retrieve settings',
                    [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
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
            // Handle Retention settings.
            if (isset($data['retention']) === true) {
                $retentionData   = $data['retention'];
                $retentionConfig = [
                    'successLogRetention'      => $retentionData['successLogRetention'] ?? 3600000,
                    'callLogRetention'         => $retentionData['callLogRetention'] ?? 2592000000,
                    'eventMessageRetention'    => $retentionData['eventMessageRetention'] ?? 604800000,
                    'jobLogRetention'          => $retentionData['jobLogRetention'] ?? 2592000000,
                    'syncContractLogRetention' => $retentionData['syncContractLogRetention'] ?? 7776000000,
                    'syncLogRetention'         => $retentionData['syncLogRetention'] ?? 2592000000,
                ];
                $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            }

            // Return the updated settings.
            return $this->getSettings();
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update settings',
                    [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
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

            // Get current settings.
            $settings  = $this->getSettings();
            $retention = $settings['retention'] ?? [];

            // Platform-portable `expires = <createdCol> + <microseconds>` SQL fragments.
            // MySQL uses DATE_ADD; PostgreSQL uses native interval arithmetic.
            // See {@see expiresExpression()} — fixes GH #822.
            $expiresExpr         = $this->expiresExpression(createdColumn: 'created');
            $expiresExprCoalesce = $this->expiresExpression(createdColumn: 'COALESCE(created, NOW())');

            // **DATABASE-OPTIMIZED REBASE**: Use direct SQL UPDATE queries for maximum performance.
            // 0. Update successful logs expiry dates.
            if (isset($retention['successLogRetention']) === true && $retention['successLogRetention'] > 0) {
                try {
                    $retentionMs = $retention['successLogRetention'];
                    $expiryQuery = "
                        UPDATE `*PREFIX*openconnector_call_logs`
                        SET expires = $expiresExpr
                        WHERE expires IS NULL OR expires = ''
                    ";
                    $stmt        = $this->db->prepare($expiryQuery);
                    $stmt->execute([$retentionMs * 1000]);
                    // Convert ms to microseconds.
                    $results['retentionResults']['callLogsUpdated'] = $stmt->rowCount();
                } catch (\Exception $e) {
                    $error = 'Failed to set call logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 1. Update call logs expiry dates.
            if (isset($retention['callLogRetention']) === true && $retention['callLogRetention'] > 0) {
                try {
                    $retentionMs = $retention['callLogRetention'];
                    $expiryQuery = "
                        UPDATE `*PREFIX*openconnector_call_logs`
                        SET expires = $expiresExpr
                        WHERE expires IS NULL OR expires = ''
                    ";
                    $stmt        = $this->db->prepare($expiryQuery);
                    $stmt->execute([$retentionMs * 1000]);
                    // Convert ms to microseconds.
                    $results['retentionResults']['callLogsUpdated'] = $stmt->rowCount();
                } catch (\Exception $e) {
                    $error = 'Failed to set call logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 2. Update event messages expiry dates (skip if expires column doesn't exist).
            if (isset($retention['eventMessageRetention']) === true && $retention['eventMessageRetention'] > 0) {
                try {
                    $retentionMs = $retention['eventMessageRetention'];
                    // Check if expires column exists before updating (portable per GH #822).
                    $results['retentionResults']['eventMessagesUpdated'] = 'Column expires not found - skipped';
                    if ($this->columnExists(unprefixedTable: 'openconnector_event_messages', column: 'expires') === true) {
                        $expiryQuery = "
                            UPDATE `*PREFIX*openconnector_event_messages`
                            SET expires = $expiresExpr
                            WHERE expires IS NULL OR expires = ''
                        ";
                        $stmt        = $this->db->prepare($expiryQuery);
                        $stmt->execute([$retentionMs * 1000]);
                        $results['retentionResults']['eventMessagesUpdated'] = $stmt->rowCount();
                    }
                } catch (\Exception $e) {
                    $error = 'Failed to set event messages expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }//end try
            }//end if

            // 3. Update job logs expiry dates.
            if (isset($retention['jobLogRetention']) === true && $retention['jobLogRetention'] > 0) {
                try {
                    $retentionMs = $retention['jobLogRetention'];
                    $expiryQuery = "
                        UPDATE `*PREFIX*openconnector_job_logs`
                        SET expires = $expiresExpr
                        WHERE expires IS NULL OR expires = ''
                    ";
                    $stmt        = $this->db->prepare($expiryQuery);
                    $stmt->execute([$retentionMs * 1000]);
                    $results['retentionResults']['jobLogsUpdated'] = $stmt->rowCount();
                } catch (\Exception $e) {
                    $error = 'Failed to set job logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 4. Update synchronization contract logs expiry dates (handle empty expires values).
            if (isset($retention['syncContractLogRetention']) === true && $retention['syncContractLogRetention'] > 0) {
                try {
                    $retentionMs = $retention['syncContractLogRetention'];
                    $expiryQuery = "
                        UPDATE `*PREFIX*openconnector_synchronization_contract_logs`
                        SET expires = $expiresExprCoalesce
                        WHERE expires IS NULL OR expires = '' OR expires = '0000-00-00 00:00:00' OR created IS NOT NULL
                    ";
                    $stmt        = $this->db->prepare($expiryQuery);
                    $stmt->execute([$retentionMs * 1000]);
                    $results['retentionResults']['syncContractLogsUpdated'] = $stmt->rowCount();
                } catch (\Exception $e) {
                    $error = 'Failed to set sync contract logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            // 5. Update synchronization logs expiry dates (handle empty expires values).
            if (isset($retention['syncLogRetention']) === true && $retention['syncLogRetention'] > 0) {
                try {
                    $retentionMs = $retention['syncLogRetention'];
                    $expiryQuery = "
                        UPDATE `*PREFIX*openconnector_synchronization_logs`
                        SET expires = $expiresExprCoalesce
                        WHERE expires IS NULL OR expires = '' OR expires = '0000-00-00 00:00:00' OR created IS NOT NULL
                    ";
                    $stmt        = $this->db->prepare($expiryQuery);
                    $stmt->execute([$retentionMs * 1000]);
                    $results['retentionResults']['syncLogsUpdated'] = $stmt->rowCount();
                } catch (\Exception $e) {
                    $error = 'Failed to set sync logs expiry dates: '.$e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error($error);
                }
            }

            $results['endTime']  = new \DateTime();
            $results['duration'] = $results['endTime']->diff($startTime)->format('%H:%I:%S');
            $results['success']  = empty($results['errors']) === true;

            $this->logger->info(
                    'Rebase operation completed',
                    [
                        'duration' => $results['duration'],
                        'success'  => $results['success'],
                        'results'  => $results['retentionResults'],
                        'errors'   => $results['errors'],
                    ]
                    );

            return $results;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Rebase operation failed',
                    [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
            throw new \RuntimeException('Rebase operation failed: '.$e->getMessage());
        }//end try

    }//end rebase()
}//end class
