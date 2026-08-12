<?php

/**
 * OpenConnector PSD2 AIS Aggregator Provider Interface.
 *
 * Narrow domain seam through which every PSD2 Account Information Service
 * (AIS) operation occurs: requisition/consent creation, consent finalisation,
 * account discovery, and transaction pulls. A new aggregator vendor (Tink,
 * Klarna Kosma, Yapily, …) is added by implementing this interface, never by
 * editing BankfeedSyncService or Psd2Controller — see design.md and the
 * sibling Peppol provider seam.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Psd2;

use OCA\OpenConnector\Exception\Psd2ConsentRevokedException;
use OCA\OpenConnector\Exception\Psd2ProviderException;

/**
 * A PSD2 AIS aggregator binding: SCA requisition + consent + accounts + transactions.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */
interface Psd2AggregatorProviderInterface {
	/**
	 * Create a requisition/consent request and return the bank SCA redirect URL.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object
	 *                                   (`provider`, `baseUrl`, `authentication.credentialRef`).
	 * @param string $institutionId The aggregator's institution (bank) identifier.
	 * @param string $redirectUrl Where the operator's browser returns after bank SCA.
	 *
	 * @return array{reference: string, redirectUrl: string} The aggregator consent reference and the bank SCA URL.
	 *
	 * @throws Psd2ProviderException When the aggregator is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
	 */
	public function createRequisition(array $sourceConfiguration, string $institutionId, string $redirectUrl): array;

	/**
	 * Finalise a consent after the operator authenticated at the bank.
	 *
	 * The returned `consentToken` is null for aggregators whose only secret is
	 * the brokered API key (GoCardless model). When a provider DOES return
	 * token material, the caller MUST store it via the credential broker and
	 * MUST NOT persist it plaintext (REQ-002/REQ-006).
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $reference The consent reference issued by {@see createRequisition}.
	 *
	 * @return array The finalised consent:
	 *               `{consentReference: string, consentExpiresAt: string,
	 *               accounts: array<int, array{iban, bic, currency, aggregatorAccountId}>,
	 *               consentToken: string|null}`.
	 *
	 * @throws Psd2ProviderException When the aggregator is unreachable, errors, or the reference is unknown.
	 * @throws Psd2ConsentRevokedException When the consent was rejected/revoked at the bank.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
	 */
	public function finaliseConsent(array $sourceConfiguration, string $reference): array;

	/**
	 * List the accounts authorised by an active consent.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $consentReference The consent reference of an active consent.
	 *
	 * @return array<int, array{iban: string, bic: string, currency: string, aggregatorAccountId: string}> The authorised accounts.
	 *
	 * @throws Psd2ProviderException When the aggregator is unreachable or errors.
	 * @throws Psd2ConsentRevokedException When the consent is no longer usable.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-account-discovery-after-consent-req-003
	 */
	public function listAccounts(array $sourceConfiguration, string $consentReference): array;

	/**
	 * Pull the transactions for one account in a `[since, until]` window.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $accountId The aggregator account id (from {@see listAccounts}).
	 * @param string $since ISO 8601 start of the pull window (inclusive).
	 * @param string $until ISO 8601 end of the pull window (inclusive).
	 *
	 * @return array<int, array<string, mixed>> Aggregator-normalised transaction rows.
	 *
	 * @throws Psd2ProviderException When the aggregator is unreachable or errors (the caller MUST NOT advance its watermark).
	 * @throws Psd2ConsentRevokedException When the consent is no longer usable.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function listTransactions(array $sourceConfiguration, string $accountId, string $since, string $until): array;
}//end interface
