<?php

/**
 * Integriq REST Peppol Access Point Provider.
 *
 * Generic REST binding for {@see PeppolAccessPointProviderInterface}, driven
 * by `configuration.baseUrl` and `authentication.credentialRef`. Every
 * outbound call is dispatched through {@see BrokeredCallService} so the AP API
 * key is injected in-process by the OpenRegister credential broker and never
 * stored, exported, or logged in plaintext (ADR-007, REQ-006). There is no
 * fallback to an embedded secret: a source without a resolvable
 * `credentialRef` fails closed with an actionable {@see PeppolProviderException}.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Peppol
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
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Peppol;

use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCP\IL10N;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST Peppol Access Point provider, dispatched through the credential broker.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
 */
class RestPeppolAccessPointProvider implements PeppolAccessPointProviderInterface {
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
	 * @param array $sourceConfiguration The Peppol source's `configuration` object (`baseUrl`, `authentication.credentialRef`).
	 * @param string $peppolId The participant identifier, `scheme:identifier`.
	 *
	 * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant-smp-lookup-endpoint-req-001
	 */
	public function lookupParticipant(array $sourceConfiguration, string $peppolId): array {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		$url = $baseUrl . '/participants/' . rawurlencode($peppolId);

		$response = $this->dispatch(sourceConfiguration: $sourceConfiguration, call: new AccessPointCall(method: 'GET', url: $url));

		$decoded = json_decode($response, true);
		if (is_array($decoded) === false) {
			throw new PeppolProviderException(
				message: 'The Peppol Access Point returned a non-JSON response for the participant lookup.'
			);
		}

		return [
			'exists' => (bool)($decoded['exists'] ?? false),
			'supportedDocTypes' => array_values((array)($decoded['supportedDocTypes'] ?? [])),
		];

	}//end lookupParticipant()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object (`baseUrl`, `authentication.credentialRef`).
	 * @param string $recipientPeppolId The recipient participant identifier, `scheme:identifier`.
	 * @param string $documentType The UBL document type slug.
	 * @param string $payload The UBL document itself (XML).
	 *
	 * @return string The Access Point-assigned transmission id.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function submitDocument(
		array $sourceConfiguration,
		string $recipientPeppolId,
		string $documentType,
		string $payload,
	): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		$url = $baseUrl . '/documents';

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			call: new AccessPointCall(
				method: 'POST',
				url: $url,
				json: [
					'recipientPeppolId' => $recipientPeppolId,
					'documentType' => $documentType,
					'payload' => $payload,
				]
			)
		);

		$decoded = json_decode($response, true);
		$transmissionId = null;
		if (is_array($decoded) === true) {
			$transmissionId = ($decoded['transmissionId'] ?? null);
		}

		if (is_string($transmissionId) === false || $transmissionId === '') {
			throw new PeppolProviderException(
				message: 'The Peppol Access Point accepted the request but returned no usable transmissionId.'
			);
		}

		return $transmissionId;
	}//end submitDocument()

	/**
	 * Dispatch one brokered call and return its raw body, mapping every
	 * failure mode to a secret-free {@see PeppolProviderException} — never a
	 * 500 crash, per REQ-001/REQ-006.
	 *
	 * @param array           $sourceConfiguration The Peppol source's `configuration` object.
	 * @param AccessPointCall $call                The method, URL and optional JSON body.
	 *
	 * @return string The response body.
	 *
	 * @throws PeppolProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, AccessPointCall $call): string {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($call->json !== null) {
			$config['json'] = $call->json;
		}

		$this->assertCredentialConfigured(config: $config);
		$response = $this->brokeredDispatch(config: $config, call: $call);

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new PeppolProviderException(
				message: 'The Peppol Access Point responded with HTTP ' . $status . '.'
			);
		}

		return $body;
	}//end dispatch()

	/**
	 * Refuse closed when the source names no broker credentialRef (ADR-007).
	 *
	 * @param array $config The brokered call configuration.
	 *
	 * @return void
	 *
	 * @throws PeppolProviderException When no credentialRef is configured.
	 */
	private function assertCredentialConfigured(array $config): void {
		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			throw new PeppolProviderException(
				message: $this->l->t('Access point credential missing') . ': the `rest` Peppol provider requires '
					. '`configuration.authentication.credentialRef` — none is configured. Configure a credentialRef '
					. 'through the OpenRegister credential broker; no plaintext-key fallback is permitted (ADR-007).'
			);
		}

	}//end assertCredentialConfigured()

	/**
	 * Prepare and dispatch the call through the credential broker.
	 *
	 * @param array           $config The brokered call configuration.
	 * @param AccessPointCall $call   The method and URL.
	 *
	 * @return ResponseInterface The Access Point's answer.
	 *
	 * @throws PeppolProviderException When the broker refuses the configuration or the transport fails.
	 */
	private function brokeredDispatch(array $config, AccessPointCall $call): ResponseInterface {
		try {
			$dispatch = $this->prepareDispatch(config: $config);

			return $this->brokeredCallService->dispatch(
				credentialId: $dispatch['credentialId'],
				actingUserId: $dispatch['actingUserId'],
				method: $call->method,
				url: $call->url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new PeppolProviderException(message: $exception->getMessage(), previous: $exception);
		} catch (Throwable $exception) {
			throw $this->unexpectedFailure(exception: $exception);
		}

	}//end brokeredDispatch()

	/**
	 * Ask the broker which credential and acting user the call runs under.
	 *
	 * @param array $config The brokered call configuration.
	 *
	 * @return array{credentialId: string, actingUserId: string|null} The prepared dispatch.
	 *
	 * @throws BrokeredCallConfigurationException When the broker refuses the configuration.
	 */
	private function prepareDispatch(array $config): array {
		return $this->brokeredCallService->prepare(
			config: $config,
			sourceData: ['type' => 'peppol'],
			asynchronous: false
		);

	}//end prepareDispatch()

	/**
	 * Log an unexpected transport failure and wrap it in a secret-free exception.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return PeppolProviderException The exception to throw.
	 */
	private function unexpectedFailure(Throwable $exception): PeppolProviderException {
		$this->logger->warning(
			'[RestPeppolAccessPointProvider] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new PeppolProviderException(
			message: 'The Peppol Access Point request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);

	}//end unexpectedFailure()
}//end class
