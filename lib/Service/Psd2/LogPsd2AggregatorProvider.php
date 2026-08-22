<?php

/**
 * Integriq Log PSD2 AIS Aggregator Provider.
 *
 * Sandbox/mock binding for {@see Psd2AggregatorProviderInterface}: performs no
 * real network call, returns a canned SCA redirect URL, canned accounts, and
 * canned transactions, and reads no secret. It is the default for dev/CI and
 * mirrors the `source-management` mock-mode convention (and the sibling
 * LogPeppolAccessPointProvider).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Psd2;

use DateInterval;
use DateTime;

/**
 * Sandbox PSD2 aggregator provider: canned SCA URL, accounts, and transactions.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */
class LogPsd2AggregatorProvider implements Psd2AggregatorProviderInterface {

	/**
	 * PSD2 SCA consent validity for the sandbox (90-day renewal deadline).
	 *
	 * @var integer
	 */
	private const CONSENT_VALID_DAYS = 90;

	/**
	 * Canned accounts a sandbox consent authorises.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const MOCK_ACCOUNTS = [
		[
			'iban' => 'NL00BANK0000000001',
			'bic' => 'BANKNL2A',
			'currency' => 'EUR',
			'aggregatorAccountId' => 'SANDBOX-ACC-1',
		],
	];

	/**
	 * Per-process counter for synthetic consent references (`REQ-SANDBOX-<n>`).
	 *
	 * A per-process, in-memory counter is sufficient for a sandbox binding:
	 * references only need to be locally unique for the duration of one
	 * request/job run; global uniqueness is not required for a mock id.
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object (unused — the log provider needs no secret).
	 * @param string $institutionId The sandbox institution identifier (echoed into the SCA URL).
	 * @param string $redirectUrl Where the operator's browser returns after bank SCA (unused by the sandbox).
	 *
	 * @return array{reference: string, redirectUrl: string} A synthetic reference + canned SCA URL.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
	 */
	public function createRequisition(array $sourceConfiguration, string $institutionId, string $redirectUrl): array {
		self::$counter++;
		$reference = 'REQ-SANDBOX-' . self::$counter;

		return [
			'reference' => $reference,
			'redirectUrl' => 'https://sandbox.bank.example/psd2/sca/' . rawurlencode($institutionId) . '/' . $reference,
		];

	}//end createRequisition()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object (unused — no secret).
	 * @param string $reference The consent reference issued by createRequisition.
	 *
	 * @return array The finalised consent (no token — the sandbox holds no secret):
	 *               `{consentReference, consentExpiresAt, accounts, consentToken: null}`.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-callback-finalises-consent-and-stores-only-the-reference
	 */
	public function finaliseConsent(array $sourceConfiguration, string $reference): array {
		$expiresAt = (new DateTime())->add(new DateInterval('P' . self::CONSENT_VALID_DAYS . 'D'));

		return [
			'consentReference' => $reference,
			'consentExpiresAt' => $expiresAt->format('c'),
			'accounts' => self::MOCK_ACCOUNTS,
			'consentToken' => null,
		];

	}//end finaliseConsent()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object (unused — no secret).
	 * @param string $consentReference The consent reference of an active consent (unused by the sandbox).
	 *
	 * @return array<int, array{aggregatorAccountId: string, bic: string, currency: string, iban: string}> The
	 *                                                                                                     canned authorised accounts.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-account-discovery-after-consent-req-003
	 */
	public function listAccounts(array $sourceConfiguration, string $consentReference): array {
		return self::MOCK_ACCOUNTS;
	}//end listAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object (unused — no secret).
	 * @param string $accountId The aggregator account id.
	 * @param string $since ISO 8601 start of the pull window.
	 * @param string $until ISO 8601 end of the pull window.
	 *
	 * @return array<int, array<string, mixed>> Canned aggregator-shaped transaction rows inside the window.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function listTransactions(array $sourceConfiguration, string $accountId, string $since, string $until): array {
		$bookingDate = substr($since, 0, 10);

		return [
			[
				'transactionId' => 'SANDBOX-TX-' . $accountId . '-1',
				'bookingDate' => $bookingDate,
				'valueDate' => $bookingDate,
				'transactionAmount' => ['amount' => '125.00', 'currency' => 'EUR'],
				'creditorName' => 'Example Supplier B.V.',
				'remittanceInformation' => 'Sandbox transaction 1',
			],
			[
				'transactionId' => 'SANDBOX-TX-' . $accountId . '-2',
				'bookingDate' => $bookingDate,
				'valueDate' => $bookingDate,
				'transactionAmount' => ['amount' => '-42.50', 'currency' => 'EUR'],
				'debtorName' => 'Example Customer',
				'remittanceInformation' => 'Sandbox transaction 2',
			],
		];

	}//end listTransactions()
}//end class
