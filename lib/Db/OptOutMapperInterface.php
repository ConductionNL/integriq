<?php

/**
 * Contract of the OptOutMapper.
 *
 * @category Contract
 * @package  OCA\Integriq\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;

/**
 * Public operations of {@see OptOutMapper} that other components call.
 */
interface OptOutMapperInterface {
	/**
	 * Every opt-out of one address, instance wide and per case.
	 *
	 * @param string $address The recipient.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function findForAddress(string $address): array;

	/**
	 * Every row of a batch of addresses, in one query.
	 *
	 * @param list<string> $addresses The recipient keys, already normalised.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function findForAddresses(array $addresses): array;

	/**
	 * Every row of a batch of sibling-app contacts, in one query.
	 *
	 * @param list<string> $contactRefs The contact refs.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	public function findForContactRefs(array $contactRefs): array;

	/**
	 * The row a migration wrote for one legacy record, or null.
	 *
	 * @param string $legacyRef The sibling app's record id.
	 *
	 * @return OptOut|null The row.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function findByLegacyRef(string $legacyRef): ?OptOut;

	/**
	 * Every row that carries a purpose. Few: only rows written since purposes
	 * existed, so the re-key migration reads them in one query.
	 *
	 * @return list<OptOut> The rows.
	 *
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function findWithPurpose(): array;

	/**
	 * One opt-out by its key, or null.
	 *
	 * @param string $dedupeKey The key from OptOut::keyFor().
	 *
	 * @return OptOut|null The row.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function findByKey(string $dedupeKey): ?OptOut;

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
	public function insertIfAbsent(OptOut $optOut): array;

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
	public function findPage(int $limit, int $offset): array;

	/**
	 * How many opt-outs there are.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function countAll(): int;

	/**
	 * Write a changed opt-out row back.
	 *
	 * @param OptOut $entity The row to write.
	 *
	 * @return OptOut The written row.
	 *
	 * @spec exclude declaration only, the behaviour is QBMapper::update(), which OptOutMapper inherits
	 */
	public function update(Entity $entity): Entity;
}
