<?php

/**
 * OpenConnector REST PSD2 AIS Aggregator Provider.
 *
 * Generic REST binding for {@see Psd2AggregatorProviderInterface}, shaped
 * after the GoCardless Bank-Account-Data API (requisitions → accounts →
 * transactions) and driven by `configuration.baseUrl` +
 * `authentication.credentialRef`. Every outbound call is dispatched through
 * {@see BrokeredCallService} so the aggregator API key / OAuth token is
 * injected in-process by the OpenRegister credential broker and never stored,
 * exported, or logged in plaintext (ADR-007, REQ-006). There is no fallback to
 * an embedded secret: a source without a resolvable `credentialRef` fails
 * closed with an actionable {@see Psd2ProviderException}.
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Psd2;

use DateInterval;
use DateTime;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Exception\Psd2ConsentRevokedException;
use OCA\OpenConnector\Exception\Psd2ProviderException;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic GoCardless-shape REST PSD2 aggregator provider, dispatched through the credential broker.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- $consentScoped deliberately parameterises the one
 * semantic difference between endpoint families (401/403 = dead consent vs bad API key); splitting
 * dispatchJson into two near-identical methods would duplicate the broker guard chain.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */
class RestPsd2AggregatorProvider implements Psd2AggregatorProviderInterface {

	/**
	 * Default PSD2 SCA consent validity when the aggregator does not report one (90-day renewal).
	 *
	 * @var integer
	 */
	private const DEFAULT_CONSENT_VALID_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCallService Dispatches the call through the OpenRegister credential broker.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 */
	public function __construct(
		private readonly BrokeredCallService $brokeredCallService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object (`baseUrl`, `authentication.credentialRef`).
	 * @param string $institutionId The aggregator institution (bank) identifier.
	 * @param string $redirectUrl Where the operator's browser returns after bank SCA.
	 *
	 * @return array{reference: string, redirectUrl: string} The requisition id + bank SCA link.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
	 */
	public function createRequisition(array $sourceConfiguration, string $institutionId, string $redirectUrl): array {
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/requisitions/';

		$decoded = $this->dispatchJson(
			sourceConfiguration: $sourceConfiguration,
			method: 'POST',
			url: $url,
			json: [
				'redirect' => $redirectUrl,
				'institution_id' => $institutionId,
			]
		);

		$reference = ($decoded['id'] ?? null);
		$link = ($decoded['link'] ?? null);
		if (is_string($reference) === false || $reference === ''
			|| is_string($link) === false || $link === ''
		) {
			throw new Psd2ProviderException(
				message: 'The PSD2 aggregator accepted the requisition request but returned no usable id/link.'
			);
		}

		return [
			'reference' => $reference,
			'redirectUrl' => $link,
		];

	}//end createRequisition()

	/**
	 * {@inheritDoc}
	 *
	 * GoCardless model: the only secret is the brokered API key, so no
	 * per-consent token is returned (`consentToken` stays null); the
	 * requisition id is the non-credential consent reference.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $reference The requisition id issued by createRequisition.
	 *
	 * @return array The finalised consent:
	 *               `{consentReference, consentExpiresAt, accounts, consentToken: null}`.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-callback-finalises-consent-and-stores-only-the-reference
	 */
	public function finaliseConsent(array $sourceConfiguration, string $reference): array {
		$requisition = $this->fetchRequisition(sourceConfiguration: $sourceConfiguration, reference: $reference);

		$validDays = (int)($sourceConfiguration['accessValidForDays'] ?? self::DEFAULT_CONSENT_VALID_DAYS);
		if ($validDays < 1) {
			$validDays = self::DEFAULT_CONSENT_VALID_DAYS;
		}

		$expiresAt = (new DateTime())->add(new DateInterval('P' . $validDays . 'D'));

		return [
			'consentReference' => $reference,
			'consentExpiresAt' => $expiresAt->format('c'),
			'accounts' => $this->resolveAccountDetails(
				sourceConfiguration: $sourceConfiguration,
				accountIds: array_values((array)($requisition['accounts'] ?? []))
			),
			'consentToken' => null,
		];

	}//end finaliseConsent()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $consentReference The requisition id of an active consent.
	 *
	 * @return array<int, array{aggregatorAccountId: string, bic: string, currency: string, iban: string}> The
	 *                                                                                                     authorised accounts with IBAN/BIC/currency/account-id.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-account-discovery-after-consent-req-003
	 */
	public function listAccounts(array $sourceConfiguration, string $consentReference): array {
		$requisition = $this->fetchRequisition(sourceConfiguration: $sourceConfiguration, reference: $consentReference);

		return $this->resolveAccountDetails(
			sourceConfiguration: $sourceConfiguration,
			accountIds: array_values((array)($requisition['accounts'] ?? []))
		);

	}//end listAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $accountId The aggregator account id.
	 * @param string $since ISO 8601 start of the pull window.
	 * @param string $until ISO 8601 end of the pull window.
	 *
	 * @return array<int, array<string, mixed>> The booked transaction rows for the window.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function listTransactions(array $sourceConfiguration, string $accountId, string $since, string $until): array {
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration)
			. '/accounts/' . rawurlencode($accountId) . '/transactions/'
			. '?date_from=' . substr($since, 0, 10) . '&date_to=' . substr($until, 0, 10);

		$decoded = $this->dispatchJson(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			url: $url,
			consentScoped: true
		);

		return array_values((array)($decoded['transactions']['booked'] ?? []));
	}//end listTransactions()

	/**
	 * Fetch one requisition resource (consent-scoped).
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $reference The requisition id.
	 *
	 * @return array<string, mixed> The decoded requisition.
	 *
	 * @throws Psd2ProviderException On any configuration, transport, or upstream error.
	 */
	private function fetchRequisition(array $sourceConfiguration, string $reference): array {
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration)
			. '/requisitions/' . rawurlencode($reference) . '/';

		return $this->dispatchJson(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			url: $url,
			consentScoped: true
		);

	}//end fetchRequisition()

	/**
	 * Resolve each aggregator account id to its IBAN/BIC/currency details.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param array<string> $accountIds The aggregator account ids from the requisition.
	 *
	 * @return array<int, array<string, string>> The normalised account rows.
	 *
	 * @throws Psd2ProviderException On any configuration, transport, or upstream error.
	 */
	private function resolveAccountDetails(array $sourceConfiguration, array $accountIds): array {
		$accounts = [];
		foreach ($accountIds as $accountId) {
			$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration)
				. '/accounts/' . rawurlencode((string)$accountId) . '/details/';

			$decoded = $this->dispatchJson(
				sourceConfiguration: $sourceConfiguration,
				method: 'GET',
				url: $url,
				consentScoped: true
			);

			$detail = (array)($decoded['account'] ?? []);
			$accounts[] = [
				'iban' => (string)($detail['iban'] ?? ''),
				'bic' => (string)($detail['bic'] ?? ''),
				'currency' => (string)($detail['currency'] ?? ''),
				'aggregatorAccountId' => (string)$accountId,
			];
		}

		return $accounts;
	}//end resolveAccountDetails()

	/**
	 * Compose the trimmed base URL from the source configuration.
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 *
	 * @return string The base URL without a trailing slash.
	 */
	private function baseUrl(array $sourceConfiguration): string {
		return rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
	}//end baseUrl()

	/**
	 * Dispatch one brokered call and return its decoded JSON body, mapping
	 * every failure mode to a secret-free {@see Psd2ProviderException} — never
	 * a 500 crash, per REQ-001/REQ-006. A 401/403 on a consent-scoped call is
	 * mapped to {@see Psd2ConsentRevokedException} so the sync machinery can
	 * move the connection to `revoked` (REQ-005).
	 *
	 * @param array $sourceConfiguration The PSD2 source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $url The composed URL (only its path+query is forwarded, per BrokeredCallService).
	 * @param array|null $json Optional JSON request body.
	 * @param boolean $consentScoped Whether a 401/403 means a dead consent (vs a bad API key).
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws Psd2ProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatchJson(
		array $sourceConfiguration,
		string $method,
		string $url,
		?array $json = null,
		bool $consentScoped = false,
	): array {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($json !== null) {
			$config['json'] = $json;
		}

		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			throw new Psd2ProviderException(
				message: $this->l->t('Aggregator credential missing') . ': the `rest` PSD2 provider requires '
					. '`configuration.authentication.credentialRef` — none is configured. Configure a credentialRef '
					. 'through the OpenRegister credential broker; no plaintext-token fallback is permitted (ADR-007).'
			);
		}

		try {
			$dispatch = $this->brokeredCallService->prepare(
				config: $config,
				sourceData: ['type' => 'psd2'],
				asynchronous: false
			);

			$response = $this->brokeredCallService->dispatch(
				credentialId: $dispatch['credentialId'],
				actingUserId: $dispatch['actingUserId'],
				method: $method,
				url: $url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new Psd2ProviderException(message: $exception->getMessage(), previous: $exception);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[RestPsd2AggregatorProvider] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new Psd2ProviderException(
				message: 'The PSD2 aggregator request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}//end try

		return $this->decodeResponse(
			status: $response->getStatusCode(),
			body: (string)$response->getBody(),
			consentScoped: $consentScoped
		);

	}//end dispatchJson()

	/**
	 * Map one aggregator response to decoded JSON or a domain exception.
	 *
	 * @param integer $status The HTTP status code.
	 * @param string $body The raw response body.
	 * @param boolean $consentScoped Whether a 401/403 means a dead consent (vs a bad API key).
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws Psd2ProviderException On any non-2xx or non-JSON response.
	 * @throws Psd2ConsentRevokedException On a consent-scoped 401/403.
	 */
	private function decodeResponse(int $status, string $body, bool $consentScoped): array {
		if (($status === 401 || $status === 403) && $consentScoped === true) {
			throw new Psd2ConsentRevokedException(
				message: 'The PSD2 aggregator rejected the consent (HTTP ' . $status . ') — it was revoked or is no longer usable.'
			);
		}

		if ($status < 200 || $status >= 300) {
			throw new Psd2ProviderException(
				message: 'The PSD2 aggregator responded with HTTP ' . $status . '.'
			);
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			throw new Psd2ProviderException(
				message: 'The PSD2 aggregator returned a non-JSON response.'
			);
		}

		return $decoded;
	}//end decodeResponse()
}//end class
