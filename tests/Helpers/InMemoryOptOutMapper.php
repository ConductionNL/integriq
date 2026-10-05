<?php

/**
 * An OptOutMapper over an array, for unit tests.
 *
 * It keeps the one property the real table has that the callers lean on: one
 * row per dedupe key. The SQL itself is proven against PostgreSQL on the
 * throwaway instance (iur-live), not here.
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

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutMapper;
use OCP\IDBConnection;

/**
 * Stores opt-outs in memory, keyed like the unique index.
 */
class InMemoryOptOutMapper extends OptOutMapper {

	/**
	 * The rows, by dedupe key.
	 *
	 * @var array<string,OptOut>
	 */
	public array $rows = [];

	/**
	 * How many batch reads ran.
	 *
	 * @var int
	 */
	public int $batchReads = 0;

	/**
	 * When true, every read throws, as a broken table does.
	 *
	 * @var bool
	 */
	public bool $failReads = false;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db A connection double; nothing reaches it.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db);

	}//end __construct()

	/**
	 * Every row of one address.
	 *
	 * @param string $address The recipient.
	 *
	 * @return list<OptOut> The rows.
	 */
	public function findForAddress(string $address): array {
		$address = strtolower(trim($address));

		return array_values(
			array_filter($this->rows, static fn (OptOut $row): bool => $row->getAddress() === $address)
		);

	}//end findForAddress()

	/**
	 * Every row of a batch of addresses.
	 *
	 * @param list<string> $addresses The keys.
	 *
	 * @return list<OptOut> The rows.
	 */
	public function findForAddresses(array $addresses): array {
		if ($this->failReads === true) {
			throw new \RuntimeException('the table cannot be read');
		}

		$this->batchReads++;

		return array_values(
			array_filter($this->rows, static fn (OptOut $row): bool => in_array($row->getAddress(), $addresses, true))
		);

	}//end findForAddresses()

	/**
	 * Every row of a batch of contacts.
	 *
	 * @param list<string> $contactRefs The contact refs.
	 *
	 * @return list<OptOut> The rows.
	 */
	public function findForContactRefs(array $contactRefs): array {
		$contactRefs = array_values(array_filter($contactRefs, static fn (string $ref): bool => $ref !== ''));
		if ($contactRefs === []) {
			return [];
		}

		return array_values(
			array_filter($this->rows, static fn (OptOut $row): bool => in_array((string)$row->getContactRef(), $contactRefs, true))
		);

	}//end findForContactRefs()

	/**
	 * The row of one legacy record.
	 *
	 * @param string $legacyRef The legacy id.
	 *
	 * @return OptOut|null The row.
	 */
	public function findByLegacyRef(string $legacyRef): ?OptOut {
		foreach ($this->rows as $row) {
			if ($row->getLegacyUuid() === $legacyRef) {
				return $row;
			}
		}

		return null;

	}//end findByLegacyRef()

	/**
	 * Replace a stored row, as the real mapper's update does.
	 *
	 * @param \OCP\AppFramework\Db\Entity $entity The row.
	 *
	 * @return OptOut The row.
	 */
	public function update(\OCP\AppFramework\Db\Entity $entity): OptOut {
		if ($this->failReads === true) {
			throw new \RuntimeException('the table cannot be written');
		}

		// Like the table: an update moves the row by id, also when its key changed.
		foreach ($this->rows as $key => $row) {
			if ($row->getId() === $entity->getId() && $key !== $entity->getDedupeKey()) {
				unset($this->rows[$key]);
			}
		}

		$this->rows[$entity->getDedupeKey()] = $entity;

		return $entity;

	}//end update()

	/**
	 * One row by key.
	 *
	 * @param string $dedupeKey The key.
	 *
	 * @return OptOut|null The row.
	 */
	public function findByKey(string $dedupeKey): ?OptOut {
		return ($this->rows[$dedupeKey] ?? null);

	}//end findByKey()

	/**
	 * Store once per key.
	 *
	 * @param OptOut $optOut The row.
	 *
	 * @return array{optOut:OptOut,created:bool} The stored row.
	 */
	public function insertIfAbsent(OptOut $optOut): array {
		$key = $optOut->getDedupeKey();
		if (isset($this->rows[$key]) === true) {
			return ['optOut' => $this->rows[$key], 'created' => false];
		}

		$optOut->setId(count($this->rows) + 1);
		$this->rows[$key] = $optOut;

		return ['optOut' => $optOut, 'created' => true];

	}//end insertIfAbsent()

	/**
	 * A page, newest first.
	 *
	 * @param int $limit  At most this many.
	 * @param int $offset Skip this many.
	 *
	 * @return list<OptOut> The rows.
	 */
	public function findPage(int $limit, int $offset): array {
		$rows = array_values($this->rows);
		usort($rows, static fn (OptOut $a, OptOut $b): int => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);

		return array_slice($rows, $offset, $limit);

	}//end findPage()

	/**
	 * The count.
	 *
	 * @return int The rows.
	 */
	public function countAll(): int {
		return count($this->rows);

	}//end countAll()

}//end class
