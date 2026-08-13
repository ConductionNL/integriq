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

use OCP\IAppConfig;
use OCP\IDBConnection;
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
class SettingsService {
	/**
	 * SettingsService constructor.
	 *
	 * @param IDBConnection $db Database connection for optimized queries.
	 * @param IAppConfig $config App configuration interface for settings storage.
	 * @param LoggerInterface $logger Logger interface for error handling.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {

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
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	private function expiresExpression(string $createdColumn): string {
		// Tested against PostgreSQL rather than FOR MySQL, deliberately.
		// `::interval` is PostgreSQL-only syntax, so a platform this code has
		// not met must not receive it — `DATE_ADD` at least fails on a server
		// that can parse the rest of the statement. Inverting the test would
		// make every unrecognised platform emit SQL nothing else understands.
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
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
	 * @param string $column The column name to check.
	 *
	 * @return bool True when the column exists, false otherwise.
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	private function columnExists(string $unprefixedTable, string $column): bool {
		try {
			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
				$stmt = $this->db->prepare(
					'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1'
				);
				$stmt->execute(['oc_' . $unprefixedTable, $column]);
			} else {
				$sql = sprintf(
					'SHOW COLUMNS FROM `*PREFIX*%s` LIKE %s',
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
	 * Retrieve the current retention settings from app configuration.
	 *
	 * @return array The current retention settings configuration.
	 *
	 * @throws \RuntimeException If settings retrieval fails.
	 */
	public function getSettings(): array {
		try {
			$retentionConfig = $this->config->getValueString('openconnector', 'retention', '');
			if (empty($retentionConfig) === true) {
				return [
					'retention' => [
						'successLogRetention' => 3600000,
						'callLogRetention' => 2592000000,
						'eventMessageRetention' => 604800000,
						'jobLogRetention' => 2592000000,
						'syncContractLogRetention' => 7776000000,
						'syncLogRetention' => 2592000000,
					],
				];
			}

			$retentionData = json_decode($retentionConfig, true) ?? [];
			return [
				'retention' => [
					'successLogRetention' => $retentionData['successLogRetention'] ?? 3600000,
					'callLogRetention' => $retentionData['callLogRetention'] ?? 2592000000,
					'eventMessageRetention' => $retentionData['eventMessageRetention'] ?? 604800000,
					'jobLogRetention' => $retentionData['jobLogRetention'] ?? 2592000000,
					'syncContractLogRetention' => $retentionData['syncContractLogRetention'] ?? 7776000000,
					'syncLogRetention' => $retentionData['syncLogRetention'] ?? 2592000000,
				],
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to retrieve settings', ['exception' => $e->getMessage()]);
			throw new \RuntimeException('Failed to retrieve settings: ' . $e->getMessage());
		}//end try

	}//end getSettings()

	/**
	 * Rebase all logs with current retention settings.
	 *
	 * This method sets expiry dates for all logs based on current retention settings,
	 * using database operations for optimal performance.
	 *
	 * @return array Array containing the rebase operation results
	 * @throws \RuntimeException If the rebase operation fails
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	public function rebase(): array {
		try {
			$startTime = new \DateTime();
			$results = [
				'startTime' => $startTime,
				'retentionResults' => [],
				'errors' => [],
			];

			// Get current settings.
			$settings = $this->getSettings();
			$retention = $settings['retention'] ?? [];

			// Platform-portable `expires = <createdCol> + <microseconds>` SQL fragments.
			// MySQL uses DATE_ADD; PostgreSQL uses native interval arithmetic.
			// See {@see expiresExpression()} — fixes GH #822.
			$expiresExpr = $this->expiresExpression(createdColumn: 'created');
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
					$stmt = $this->db->prepare($expiryQuery);
					$stmt->execute([$retentionMs * 1000]);
					// Convert ms to microseconds.
					$results['retentionResults']['callLogsUpdated'] = $stmt->rowCount();
				} catch (\Exception $e) {
					$error = 'Failed to set call logs expiry dates: ' . $e->getMessage();
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
					$stmt = $this->db->prepare($expiryQuery);
					$stmt->execute([$retentionMs * 1000]);
					// Convert ms to microseconds.
					$results['retentionResults']['callLogsUpdated'] = $stmt->rowCount();
				} catch (\Exception $e) {
					$error = 'Failed to set call logs expiry dates: ' . $e->getMessage();
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
						$stmt = $this->db->prepare($expiryQuery);
						$stmt->execute([$retentionMs * 1000]);
						$results['retentionResults']['eventMessagesUpdated'] = $stmt->rowCount();
					}
				} catch (\Exception $e) {
					$error = 'Failed to set event messages expiry dates: ' . $e->getMessage();
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
					$stmt = $this->db->prepare($expiryQuery);
					$stmt->execute([$retentionMs * 1000]);
					$results['retentionResults']['jobLogsUpdated'] = $stmt->rowCount();
				} catch (\Exception $e) {
					$error = 'Failed to set job logs expiry dates: ' . $e->getMessage();
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
					$stmt = $this->db->prepare($expiryQuery);
					$stmt->execute([$retentionMs * 1000]);
					$results['retentionResults']['syncContractLogsUpdated'] = $stmt->rowCount();
				} catch (\Exception $e) {
					$error = 'Failed to set sync contract logs expiry dates: ' . $e->getMessage();
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
					$stmt = $this->db->prepare($expiryQuery);
					$stmt->execute([$retentionMs * 1000]);
					$results['retentionResults']['syncLogsUpdated'] = $stmt->rowCount();
				} catch (\Exception $e) {
					$error = 'Failed to set sync logs expiry dates: ' . $e->getMessage();
					$results['errors'][] = $error;
					$this->logger->error($error);
				}
			}

			$results['endTime'] = new \DateTime();
			$results['duration'] = $results['endTime']->diff($startTime)->format('%H:%I:%S');
			$results['success'] = empty($results['errors']) === true;

			$this->logger->info(
				'Rebase operation completed',
				[
					'duration' => $results['duration'],
					'success' => $results['success'],
					'results' => $results['retentionResults'],
					'errors' => $results['errors'],
				]
			);

			return $results;
		} catch (\Exception $e) {
			$this->logger->error(
				'Rebase operation failed',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw new \RuntimeException('Rebase operation failed: ' . $e->getMessage());
		}//end try

	}//end rebase()
}//end class
