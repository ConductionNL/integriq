<?php

/**
 * Integriq OptOutLogMapper.
 *
 * Writes and reads the opt-out decision log. There is no update path: a log
 * row is written once. Reading it is for administrators, which the controller
 * that exposes it enforces.
 *
 * @category Db
 * @package  OCA\Integriq\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The opt-out log table.
 *
 * @template-extends QBMapper<OptOutLogEntry>
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */
class OptOutLogMapper extends QBMapper {

	/**
	 * The table, unprefixed.
	 *
	 * @var string
	 */
	public const TABLE = 'integriq_opt_out_log';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: OptOutLogEntry::class);

	}//end __construct()

	/**
	 * Append one row.
	 *
	 * @param OptOutLogEntry $entry The row.
	 *
	 * @return OptOutLogEntry The stored row.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function append(OptOutLogEntry $entry): OptOutLogEntry {
		return $this->insert(entity: $entry);

	}//end append()

	/**
	 * One page of the log, newest first, optionally for one correlation id.
	 *
	 * @param int    $limit         At most this many rows.
	 * @param int    $offset        Skip this many.
	 * @param string $correlationId Only this correlation id, when not empty.
	 *
	 * @return list<OptOutLogEntry> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function findPage(int $limit, int $offset, string $correlationId = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset));
		if ($correlationId !== '') {
			$qb->where($qb->expr()->eq('correlation_id', $qb->createNamedParameter($correlationId)));
		}

		return array_values($this->findEntities(query: $qb));

	}//end findPage()

	/**
	 * Delete every row written before a moment.
	 *
	 * @param int $before The unix time; rows strictly older go.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function deleteOlderThan(int $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();

	}//end deleteOlderThan()

	/**
	 * Every entry for one of these addresses, oldest first.
	 *
	 * @param list<string> $addresses The recipient keys.
	 *
	 * @return list<OptOutLogEntry> The entries.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	public function findForAddresses(array $addresses): array {
		$addresses = array_values(array_filter(array_unique($addresses), static fn (string $address): bool => $address !== ''));
		if ($addresses === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->in('address', $qb->createNamedParameter($addresses, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC');

		return array_values($this->findEntities(query: $qb));

	}//end findForAddresses()

	/**
	 * Overwrite a redacted entry. The only change an entry ever gets after it is written.
	 *
	 * @param OptOutLogEntry $entry The redacted entry.
	 *
	 * @return OptOutLogEntry The entry.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	public function redact(OptOutLogEntry $entry): OptOutLogEntry {
		return $this->update(entity: $entry);

	}//end redact()

}//end class
