<?php

/**
 * Integriq ColumnPairMover: renames or back-fills one Dutch/English column
 * pair of a shard table.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Runs the statement that moves one column pair; built by
 * {@see RenameDutchColumns}.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class ColumnPairMover {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Rename the source column when the destination is absent, else back-fill
	 * the destination from it.
	 *
	 * @param TableColumnMove                                $move The table.
	 * @param array{from: string, to: string, kind: string} $pair Which way the pair moves.
	 *
	 * @return string|null The tally to count, or null when the statement failed.
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function move(TableColumnMove $move, array $pair): ?string {
		if ($move->has(column: $pair['to']) === false) {
			return $this->rename(move: $move, pair: $pair);
		}

		return $this->backfill(move: $move, pair: $pair);

	}//end move()

	/**
	 * Rename the source column to the destination.
	 *
	 * @param TableColumnMove                                $move The table.
	 * @param array{from: string, to: string, kind: string} $pair Which way the pair moves.
	 *
	 * @return string|null The pair's kind, or null when the statement failed.
	 */
	private function rename(TableColumnMove $move, array $pair): ?string {
		$sql = 'ALTER TABLE ' . $move->quotedTable . ' RENAME COLUMN '
			. $this->quote(identifier: $pair['from']) . ' TO ' . $this->quote(identifier: $pair['to']);
		if ($this->run(sql: $sql) === true) {
			return $pair['kind'];
		}

		return null;

	}//end rename()

	/**
	 * Back-fill the destination column from the source where it is empty.
	 *
	 * @param TableColumnMove                                $move The table.
	 * @param array{from: string, to: string, kind: string} $pair Which way the pair moves.
	 *
	 * @return string|null `copied`, or null when the statement failed.
	 */
	private function backfill(TableColumnMove $move, array $pair): ?string {
		$qTo = $this->quote(identifier: $pair['to']);
		$qFrom = $this->quote(identifier: $pair['from']);
		$sql = 'UPDATE ' . $move->quotedTable . ' SET ' . $qTo . ' = ' . $qFrom
			. ' WHERE ' . $qTo . ' IS NULL AND ' . $qFrom . ' IS NOT NULL';
		if ($this->run(sql: $sql) === true) {
			return 'copied';
		}

		return null;

	}//end backfill()

	/**
	 * Run a schema or data statement, logging a failure instead of throwing.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool True when it ran.
	 */
	private function run(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end run()

	/**
	 * Quote an identifier for the connected platform.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);

	}//end quote()
}//end class
