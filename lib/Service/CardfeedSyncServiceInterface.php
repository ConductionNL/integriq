<?php

/**
 * Contract of the CardfeedSyncService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\CardfeedProviderException;
use OCA\Integriq\Service\Cardfeed\CardfeedProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see CardfeedSyncService} that other components call.
 */
interface CardfeedSyncServiceInterface {
	/**
	 * Enroll a card program: discover its cards and persist (or update in place)
	 * a `cardfeed_account`.
	 *
	 * Idempotent: re-enrolling the same source updates the card set on the
	 * existing account (keyed by cardId) rather than creating a second account
	 * or duplicating a card (REQ-002).
	 *
	 * @param string $sourceSlug The cardfeed source slug (integriq Source).
	 *
	 * @return array<string, mixed> The enrolled account summary
	 *                              (`accountId`, `cardfeedSourceSlug`, `cards`, `lifecycleState`).
	 *
	 * @throws CardfeedProviderException When the source is unknown/disabled or the provider errors.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-enrollment-discovers-and-records-cards-idempotently
	 */
	public function enrollSource(string $sourceSlug): array;

	/**
	 * Run one sync sweep over every cardfeed account.
	 *
	 * Per account: skip if not `active` (no provider call), else pull each card's
	 * transactions from the last watermark, drop any transaction id already in
	 * `seenTransactionIds`, persist one `cardfeed_batch` per card that has new
	 * transactions, emit the synced event, record the new ids, and advance the
	 * watermark. A failed pull leaves the watermark and seen set untouched so the
	 * same window is re-attempted (REQ-003). A replayed sweep whose transactions
	 * are all already seen persists nothing and emits nothing (REQ-004).
	 *
	 * @return integer The number of batches persisted in this sweep.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	public function syncAll(): int;

	/**
	 * Resolve the enabled cardfeed source for a slug.
	 *
	 * @param string $sourceSlug The integriq Source slug (or uuid).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws CardfeedProviderException When the source is missing, not `type=cardfeed`, or disabled.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveSource(string $sourceSlug): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The cardfeed source's `configuration` object.
	 *
	 * @return CardfeedProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): CardfeedProviderInterface;
}
