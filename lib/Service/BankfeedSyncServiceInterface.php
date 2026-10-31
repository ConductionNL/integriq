<?php

/**
 * Contract of the BankfeedSyncService.
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

use OCA\Integriq\Exception\Psd2ProviderException;
use OCA\Integriq\Service\Psd2\Psd2AggregatorProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see BankfeedSyncService} that other components call.
 */
interface BankfeedSyncServiceInterface {
	/**
	 * Start the redirect-based SCA flow: create a requisition and persist a
	 * `pending` bankfeed_connection carrying the redirectUrl registered at
	 * connect time (open-redirect defence — callback only redirects there).
	 *
	 * @param string $sourceSlug The PSD2 source slug (openconnector Source).
	 * @param string $institutionId The aggregator institution (bank) identifier.
	 * @param string $redirectUrl Where the operator's browser returns after bank SCA.
	 *
	 * @return array{redirectUrl: string, reference: string, connectionId: string} The bank SCA URL + identifiers.
	 *
	 * @throws Psd2ProviderException When the source is unknown/disabled or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-connect-returns-a-bank-sca-redirect-url
	 */
	public function connect(string $sourceSlug, string $institutionId, string $redirectUrl): array;

	/**
	 * Finalise a consent after the bank redirected the operator back.
	 *
	 * Validates the `ref` against the pending requisition created at connect
	 * time (CSRF/mix-up defence): an unknown or already-finalised reference is
	 * rejected. Any token material returned by the provider is stored through
	 * the credential broker — never on the connection object (REQ-002/006).
	 * Emits `nl.conduction.bankfeed.consent.granted` once active.
	 *
	 * @param string $reference The consent reference returned by the aggregator redirect.
	 *
	 * @return array{redirectUrl: string, connectionId: string} The redirect target registered at connect time.
	 *
	 * @throws Psd2ProviderException When the reference is unknown, already finalised, or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-callback-finalises-consent-and-stores-only-the-reference
	 */
	public function finaliseConsent(string $reference): array;

	/**
	 * Discover (or re-discover) the accounts authorised by an active consent.
	 *
	 * Idempotent: the account set is replaced in place, keyed by aggregator
	 * account id (falling back to IBAN), so re-discovery updates and never
	 * duplicates (REQ-003). No token is read or exposed here.
	 *
	 * @param string $connectionId The connection identifier.
	 *
	 * @return array<int, array<string, string>> The recorded account set.
	 *
	 * @throws Psd2ProviderException When the connection is unknown or not active, or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-re-discovery-is-idempotent
	 */
	public function discoverAccounts(string $connectionId): array;

	/**
	 * Run one sync sweep over every bankfeed connection.
	 *
	 * Per connection: apply the expiry lifecycle (expired connections are
	 * marked and skipped without an aggregator call; a consent entering the
	 * warning window emits a single expiring event), then pull each account's
	 * transactions from the last watermark, persist one `bankfeed_batch` per
	 * account, emit the synced event, and only then advance the watermark —
	 * a failed pull leaves the watermark untouched so the same window is
	 * re-attempted (REQ-004). A revoked consent moves the connection to
	 * `revoked` and emits the revoked event (REQ-005).
	 *
	 * @return integer The number of batches persisted in this sweep.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function syncAll(): int;

	/**
	 * Move a connection to `revoked` and emit the revoked lifecycle event.
	 *
	 * @param ObjectEntity $connection The connection whose consent was revoked.
	 * @param string $reason Secret-free reason for the log line.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-lifecycle-cloudevents-for-consumer-state-transitions-req-005
	 */
	public function markRevoked(ObjectEntity $connection, string $reason): void;

	/**
	 * Resolve the enabled PSD2 source for a slug.
	 *
	 * @param string $sourceSlug The openconnector Source slug (or uuid).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws Psd2ProviderException When the source is missing, not `type=psd2`, or disabled.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveSource(string $sourceSlug): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The PSD2 source's `configuration` object.
	 *
	 * @return Psd2AggregatorProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): Psd2AggregatorProviderInterface;
}
