<?php

/**
 * Integriq REST Corporate Card-Feed Provider.
 *
 * Generic REST binding for {@see CardfeedProviderInterface}, shaped after a
 * typical corporate-card program API (`/cards` → `/cards/{id}/transactions`)
 * and driven by `configuration.baseUrl` + `authentication.credentialRef`. Every
 * outbound call is dispatched through {@see BrokeredCallService} so the program
 * API key is injected in-process by the OpenRegister credential broker and never
 * stored, exported, or logged in plaintext (ADR-007, REQ-005). There is no
 * fallback to an embedded secret: a source without a resolvable `credentialRef`
 * fails closed with an actionable {@see CardfeedProviderException}.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Cardfeed
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
 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Cardfeed;

use OCA\Integriq\Exception\CardfeedProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCP\IL10N;
use OCA\Integriq\Service\Helper\BrokeredCall;
use OCA\Integriq\Service\Helper\BrokeredCallRefusals;
use OCA\Integriq\Service\Helper\BrokeredJsonDispatch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST corporate-card provider, dispatched through the credential broker.
 *
 * Example production configuration (placeholders only — the real key is
 * broker-held, never inline):
 *
 * ```json
 * {
 *   "provider": "rest",
 *   "baseUrl": "https://api.card-program.example/v1",
 *   "authentication": { "credentialRef": "YOUR_API_KEY_HERE" }
 * }
 * ```
 *
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */
class RestCardfeedProvider implements CardfeedProviderInterface, BrokeredCallRefusals {
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
	 * @param array $sourceConfiguration The cardfeed source's `configuration` object (`baseUrl`, `authentication.credentialRef`).
	 *
	 * @return array<int, array{cardId: string, last4: string, cardholderName: string, currency: string}> The program's cards.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
	 */
	public function listCards(array $sourceConfiguration): array {
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/cards';

		$decoded = $this->dispatchJson(sourceConfiguration: $sourceConfiguration, url: $url);

		$cards = [];
		foreach (array_values((array)($decoded['cards'] ?? $decoded['data'] ?? [])) as $card) {
			$card = (array)$card;
			$cards[] = [
				'cardId' => (string)($card['id'] ?? $card['cardId'] ?? ''),
				'last4' => (string)($card['last4'] ?? ''),
				'cardholderName' => (string)($card['cardholderName'] ?? $card['cardholder'] ?? ''),
				'currency' => (string)($card['currency'] ?? ''),
			];
		}

		return $cards;
	}//end listCards()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The cardfeed source's `configuration` object.
	 * @param string $cardId The card id.
	 * @param string $since ISO 8601 start of the pull window.
	 * @param string $until ISO 8601 end of the pull window.
	 *
	 * @return array<int, array<string, mixed>> The transaction rows for the window.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	public function listTransactions(array $sourceConfiguration, string $cardId, string $since, string $until): array {
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration)
			. '/cards/' . rawurlencode($cardId) . '/transactions'
			. '?since=' . rawurlencode(substr($since, 0, 10)) . '&until=' . rawurlencode(substr($until, 0, 10));

		$decoded = $this->dispatchJson(sourceConfiguration: $sourceConfiguration, url: $url);

		return array_values((array)($decoded['transactions'] ?? $decoded['data'] ?? []));
	}//end listTransactions()

	/**
	 * Compose the trimmed base URL from the source configuration.
	 *
	 * @param array $sourceConfiguration The cardfeed source's `configuration` object.
	 *
	 * @return string The base URL without a trailing slash.
	 */
	private function baseUrl(array $sourceConfiguration): string {
		return rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
	}//end baseUrl()

	/**
	 * Dispatch one brokered GET and return its decoded JSON body, mapping
	 * every failure mode to a secret-free {@see CardfeedProviderException}.
	 *
	 * @param array  $sourceConfiguration The cardfeed source's `configuration` object.
	 * @param string $url                 The composed URL (only its path+query is forwarded, per BrokeredCallService).
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws CardfeedProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatchJson(array $sourceConfiguration, string $url): array {
		$response = $this->brokered()->send(sourceConfiguration: $sourceConfiguration, call: new BrokeredCall(method: 'GET', url: $url));

		return $this->decodeResponse(status: $response->getStatusCode(), body: (string)$response->getBody());
	}//end dispatchJson()

	/**
	 * The brokered dispatch this provider sends its calls through.
	 *
	 * @return BrokeredJsonDispatch The dispatch.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	private function brokered(): BrokeredJsonDispatch {
		return new BrokeredJsonDispatch(
			broker: $this->brokeredCallService,
			refusals: $this,
			sourceType: 'cardfeed'
		);

	}//end brokered()

	/**
	 * {@inheritDoc}
	 *
	 * @return CardfeedProviderException The refusal.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function credentialMissing(): CardfeedProviderException {
		return new CardfeedProviderException(
			message: $this->l->t('Card provider credential missing') . ': the `rest` cardfeed provider requires '
				. '`configuration.authentication.credentialRef` — none is configured. Configure a credentialRef '
				. 'through the OpenRegister credential broker; no plaintext-key fallback is permitted (ADR-007).'
		);

	}//end credentialMissing()

	/**
	 * {@inheritDoc}
	 *
	 * @param BrokeredCallConfigurationException $cause The broker's refusal.
	 *
	 * @return CardfeedProviderException The refusal.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function configurationRefused(BrokeredCallConfigurationException $cause): CardfeedProviderException {
		return new CardfeedProviderException(message: $cause->getMessage(), previous: $cause);

	}//end configurationRefused()

	/**
	 * {@inheritDoc}
	 *
	 * @param Throwable $cause The failure.
	 *
	 * @return CardfeedProviderException The refusal.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function transportFailed(Throwable $cause): CardfeedProviderException {
		$this->logger->warning(
			'[RestCardfeedProvider] unexpected transport failure',
			['exception' => $cause->getMessage()]
		);

		return new CardfeedProviderException(
			message: 'The card provider request failed unexpectedly: ' . $cause->getMessage(),
			previous: $cause
		);

	}//end transportFailed()

	/**
	 * Map one provider response to decoded JSON or a domain exception.
	 *
	 * @param integer $status The HTTP status code.
	 * @param string $body The raw response body.
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws CardfeedProviderException On any non-2xx or non-JSON response.
	 */
	private function decodeResponse(int $status, string $body): array {
		if ($status < 200 || $status >= 300) {
			throw new CardfeedProviderException(
				message: 'The card provider responded with HTTP ' . $status . '.'
			);
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			throw new CardfeedProviderException(
				message: 'The card provider returned a non-JSON response.'
			);
		}

		return $decoded;
	}//end decodeResponse()
}//end class
