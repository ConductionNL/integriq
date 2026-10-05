<?php

/**
 * An OptOutLogMapper over an array, for unit tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Db\OptOutLogMapper;
use OCP\IDBConnection;

/**
 * Appends log rows to a list.
 */
class InMemoryOptOutLogMapper extends OptOutLogMapper {

	/**
	 * The rows, in order.
	 *
	 * @var list<OptOutLogEntry>
	 */
	public array $rows = [];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db A connection double; nothing reaches it.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db);

	}//end __construct()

	/**
	 * Append one row.
	 *
	 * @param OptOutLogEntry $entry The row.
	 *
	 * @return OptOutLogEntry The row.
	 */
	public function append(OptOutLogEntry $entry): OptOutLogEntry {
		$entry->setId(count($this->rows) + 1);
		$this->rows[] = $entry;

		return $entry;

	}//end append()

	/**
	 * A page, newest first.
	 *
	 * @param int    $limit         At most this many.
	 * @param int    $offset        Skip this many.
	 * @param string $correlationId Only this correlation id.
	 *
	 * @return list<OptOutLogEntry> The rows.
	 */
	public function findPage(int $limit, int $offset, string $correlationId = ''): array {
		$rows = array_values(
			array_filter(
				array_reverse($this->rows),
				static fn (OptOutLogEntry $row): bool => $correlationId === '' || $row->getCorrelationId() === $correlationId
			)
		);

		return array_slice($rows, $offset, $limit);

	}//end findPage()

	/**
	 * Delete rows older than a moment.
	 *
	 * @param int $before The unix time.
	 *
	 * @return int How many went.
	 */
	public function deleteOlderThan(int $before): int {
		$kept = array_values(array_filter($this->rows, static fn (OptOutLogEntry $row): bool => $row->getAt() >= $before));
		$deleted = count($this->rows) - count($kept);
		$this->rows = $kept;

		return $deleted;

	}//end deleteOlderThan()

	/**
	 * The rows of one kind.
	 *
	 * @param string $kind The kind.
	 *
	 * @return list<OptOutLogEntry> The rows.
	 */
	public function ofKind(string $kind): array {
		return array_values(array_filter($this->rows, static fn (OptOutLogEntry $row): bool => $row->getKind() === $kind));

	}//end ofKind()

}//end class
