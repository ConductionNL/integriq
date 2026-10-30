<?php

/**
 * Integriq Settings Service
 *
 * This file contains the service class for handling settings in the Integriq application.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving database statistics and
 * system information for the Integriq application.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class SettingsService implements SettingsServiceInterface {
	/**
	 * The log tables rebase() sets expiry dates on, in order: the retention
	 * settings that drive each, the result key, and which rows it touches.
	 * Successful and failed call logs share one table, each setting runs its
	 * own update, and the later setting's count is the one reported.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const REBASE_TABLES = [
		[
			'settings' => ['successLogRetention', 'callLogRetention'],
			'table' => 'openconnector_call_logs',
			'result' => 'callLogsUpdated',
			'label' => 'call logs',
			'expiresFrom' => 'created',
			'where' => self::WHERE_NO_EXPIRY,
			'checkColumn' => false,
		],
		[
			'settings' => ['eventMessageRetention'],
			'table' => 'openconnector_event_messages',
			'result' => 'eventMessagesUpdated',
			'label' => 'event messages',
			'expiresFrom' => 'created',
			'where' => self::WHERE_NO_EXPIRY,
			'checkColumn' => true,
		],
		[
			'settings' => ['jobLogRetention'],
			'table' => 'openconnector_job_logs',
			'result' => 'jobLogsUpdated',
			'label' => 'job logs',
			'expiresFrom' => 'created',
			'where' => self::WHERE_NO_EXPIRY,
			'checkColumn' => false,
		],
		[
			'settings' => ['syncContractLogRetention'],
			'table' => 'openconnector_synchronization_contract_logs',
			'result' => 'syncContractLogsUpdated',
			'label' => 'sync contract logs',
			'expiresFrom' => 'createdOrNow',
			'where' => self::WHERE_EMPTY_EXPIRY_OR_CREATED,
			'checkColumn' => false,
		],
		[
			'settings' => ['syncLogRetention'],
			'table' => 'openconnector_synchronization_logs',
			'result' => 'syncLogsUpdated',
			'label' => 'sync logs',
			'expiresFrom' => 'createdOrNow',
			'where' => self::WHERE_EMPTY_EXPIRY_OR_CREATED,
			'checkColumn' => false,
		],
	];

	/**
	 * Rows without an expiry date.
	 *
	 * @var string
	 */
	private const WHERE_NO_EXPIRY = "expires IS NULL OR expires = ''";

	/**
	 * Rows without a usable expiry date, or with a creation date (synchronization logs).
	 *
	 * @var string
	 */
	private const WHERE_EMPTY_EXPIRY_OR_CREATED = "expires IS NULL OR expires = '' OR expires = '0000-00-00 00:00:00' OR created IS NOT NULL";

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
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md#requirement-settingscontroller-must-shrink-to-connector-specific-actions-only
	 */
	public function getSettings(): array {
		try {
			$retentionConfig = $this->config->getValueString('integriq', 'retention', '');
			$retentionData = [];
			if (empty($retentionConfig) === false) {
				$retentionData = json_decode($retentionConfig, true);
			}

			if (is_array($retentionData) === false) {
				$retentionData = [];
			}

			// The same table the log writers fall back to, so what this read
			// reports is what the logs get (integriq#2210).
			$retention = [];
			foreach (RetentionDefaults::BY_SETTING as $key => $default) {
				$retention[$key] = ($retentionData[$key] ?? $default);
			}

			return ['retention' => $retention];
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
			$expires = [
				'created' => $this->expiresExpression(createdColumn: 'created'),
				'createdOrNow' => $this->expiresExpression(createdColumn: 'COALESCE(created, NOW())'),
			];

			// **DATABASE-OPTIMIZED REBASE**: Use direct SQL UPDATE queries for maximum performance.
			foreach (self::REBASE_TABLES as $table) {
				$table['expires'] = $expires[$table['expiresFrom']];
				foreach ($table['settings'] as $setting) {
					if (isset($retention[$setting]) === true && $retention[$setting] > 0) {
						$table['retentionMs'] = $retention[$setting];
						$this->rebaseTable(table: $table, results: $results);
					}
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

	/**
	 * Set the expiry date of every row in one log table that has none.
	 *
	 * A failure is recorded in the results and logged; the rebase goes on
	 * with the next table.
	 *
	 * @param array $table   One entry of REBASE_TABLES, with the `retentionMs` of one of its settings and its `expires` expression.
	 * @param array $results The rebase results, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	private function rebaseTable(array $table, array &$results): void {
		try {
			if ($table['checkColumn'] === true) {
				// Check if expires column exists before updating (portable per GH #822).
				$results['retentionResults'][$table['result']] = 'Column expires not found - skipped';
				if ($this->columnExists(unprefixedTable: $table['table'], column: 'expires') === false) {
					return;
				}
			}

			$stmt = $this->db->prepare(
				'UPDATE `*PREFIX*' . $table['table'] . '` SET expires = ' . $table['expires'] . ' WHERE ' . $table['where']
			);
			// Convert ms to microseconds.
			$stmt->execute([$table['retentionMs'] * 1000]);
			$results['retentionResults'][$table['result']] = $stmt->rowCount();
		} catch (\Exception $e) {
			$error = 'Failed to set ' . $table['label'] . ' expiry dates: ' . $e->getMessage();
			$results['errors'][] = $error;
			$this->logger->error($error);
		}//end try
	}//end rebaseTable()
}//end class
