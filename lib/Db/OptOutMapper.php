<?php

/**
 * Integriq OptOutMapper.
 *
 * Reads and writes integriq's own opt-out table. No RBAC sits in front of it,
 * on purpose: the decision whether to send must see every opt-out, and a read
 * that a permission check could empty would send mail to someone who asked
 * not to receive it (integriq#2114). Who may list the rows is decided by the
 * controller that exposes them (administrators only).
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
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The opt-out table.
 *
 * @template-extends QBMapper<OptOut>
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class OptOutMapper extends QBMapper {

	/**
	 * The table, unprefixed.
	 *
	 * @var string
	 */
	public const TABLE = 'integriq_opt_outs';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: OptOut::class);

	}//end __construct()

	/**
	 * Every opt-out of one address, instance wide and per case.
	 *
	 * @param string $address The recipient.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function findForAddress(string $address): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('address', $qb->createNamedParameter(strtolower(trim($address)))));

		return array_values($this->findEntities(query: $qb));

	}//end findForAddress()

	/**
	 * Every row of a batch of addresses, in one query.
	 *
	 * @param list<string> $addresses The recipient keys, already normalised.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function findForAddresses(array $addresses): array {
		return $this->findIn(column: 'address', values: $addresses);

	}//end findForAddresses()

	/**
	 * Every row of a batch of sibling-app contacts, in one query.
	 *
	 * @param list<string> $contactRefs The contact refs.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	public function findForContactRefs(array $contactRefs): array {
		return $this->findIn(column: 'contact_ref', values: $contactRefs);

	}//end findForContactRefs()

	/**
	 * The row a migration wrote for one legacy record, or null.
	 *
	 * @param string $legacyRef The sibling app's record id.
	 *
	 * @return OptOut|null The row.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function findByLegacyRef(string $legacyRef): ?OptOut {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('legacy_uuid', $qb->createNamedParameter($legacyRef)))
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return array_values($rows)[0];

	}//end findByLegacyRef()

	/**
	 * Rows whose column is one of the values. Empty values are dropped; no
	 * values reads nothing.
	 *
	 * @param string       $column The column.
	 * @param list<string> $values The values.
	 *
	 * @return list<OptOut> The rows.
	 */
	private function findIn(string $column, array $values): array {
		$values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
		if ($values === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY)));

		return array_values($this->findEntities(query: $qb));

	}//end findIn()

	/**
	 * One opt-out by its key, or null.
	 *
	 * @param string $dedupeKey The key from OptOut::keyFor().
	 *
	 * @return OptOut|null The row.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function findByKey(string $dedupeKey): ?OptOut {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}

	}//end findByKey()

	/**
	 * Store an opt-out once. Following the same link twice adds one row.
	 *
	 * @param OptOut $optOut The opt-out, its dedupe key set.
	 *
	 * @return array{optOut:OptOut,created:bool} The stored row, and whether this call added it.
	 *
	 * @throws DbException On any database failure other than the row already being there.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function insertIfAbsent(OptOut $optOut): array {
		$existing = $this->findByKey(dedupeKey: $optOut->getDedupeKey());
		if ($existing !== null) {
			return ['optOut' => $existing, 'created' => false];
		}

		try {
			return ['optOut' => $this->insert(entity: $optOut), 'created' => true];
		} catch (DbException $exception) {
			// Two clicks at once: the unique index let one through.
			if ($exception->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $exception;
			}

			$existing = $this->findByKey(dedupeKey: $optOut->getDedupeKey());
			if ($existing === null) {
				throw $exception;
			}

			return ['optOut' => $existing, 'created' => false];
		}

	}//end insertIfAbsent()

	/**
	 * One page of the list, newest first.
	 *
	 * @param int $limit  At most this many rows.
	 * @param int $offset Skip this many.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function findPage(int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset));

		return array_values($this->findEntities(query: $qb));

	}//end findPage()

	/**
	 * How many opt-outs there are.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from(self::TABLE);
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;

	}//end countAll()

}//end class
