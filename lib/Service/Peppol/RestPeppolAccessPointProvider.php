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
use OCA\Integriq\Service\Helper\BrokeredCall;
use OCA\Integriq\Service\Helper\BrokeredCallRefusals;
use OCA\Integriq\Service\Helper\BrokeredJsonDispatch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST Peppol Access Point provider, dispatched through the credential broker.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
 */
class RestPeppolAccessPointProvider implements PeppolAccessPointProviderInterface, BrokeredCallRefusals {
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

		$response = $this->dispatch(sourceConfiguration: $sourceConfiguration, call: new BrokeredCall(method: 'GET', url: $url));

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
			call: new BrokeredCall(
				method: 'POST',
				url: $url,
				json: [
					'recipientPeppolId' => $recipientPeppolId,
					'documentType' => $documentType,
					'payload' => $payload,
				]
			)
		);

		return $this->transmissionId(response: $response);
	}//end submitDocument()

	/**
	 * The transmissionId the Access Point assigned to a submission.
	 *
	 * @param string $response The submission answer body.
	 *
	 * @return string The transmission id.
	 *
	 * @throws PeppolProviderException When the answer carries no usable transmissionId.
	 */
	private function transmissionId(string $response): string {
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
	}//end transmissionId()

	/**
	 * Dispatch one brokered call and return its raw body, mapping every
	 * failure mode to a secret-free {@see PeppolProviderException} — never a
	 * 500 crash, per REQ-001/REQ-006.
	 *
	 * @param array        $sourceConfiguration The Peppol source's `configuration` object.
	 * @param BrokeredCall $call                The method, URL and optional JSON body.
	 *
	 * @return string The response body.
	 *
	 * @throws PeppolProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, BrokeredCall $call): string {
		$response = $this->brokered()->send(sourceConfiguration: $sourceConfiguration, call: $call);

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
	 * The brokered dispatch this provider sends its calls through.
	 *
	 * @return BrokeredJsonDispatch The dispatch.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	private function brokered(): BrokeredJsonDispatch {
		return new BrokeredJsonDispatch(
			broker: $this->brokeredCallService,
			refusals: $this,
			sourceType: 'peppol'
		);

	}//end brokered()

	/**
	 * {@inheritDoc}
	 *
	 * @return PeppolProviderException The refusal.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function credentialMissing(): PeppolProviderException {
		return new PeppolProviderException(
			message: $this->l->t('Access point credential missing') . ': the `rest` Peppol provider requires '
				. '`configuration.authentication.credentialRef` — none is configured. Configure a credentialRef '
				. 'through the OpenRegister credential broker; no plaintext-key fallback is permitted (ADR-007).'
		);

	}//end credentialMissing()

	/**
	 * {@inheritDoc}
	 *
	 * @param BrokeredCallConfigurationException $cause The broker's refusal.
	 *
	 * @return PeppolProviderException The refusal.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function configurationRefused(BrokeredCallConfigurationException $cause): PeppolProviderException {
		return new PeppolProviderException(message: $cause->getMessage(), previous: $cause);

	}//end configurationRefused()

	/**
	 * {@inheritDoc}
	 *
	 * @param Throwable $cause The failure.
	 *
	 * @return PeppolProviderException The refusal.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
	 */
	public function transportFailed(Throwable $cause): PeppolProviderException {
		$this->logger->warning(
			'[RestPeppolAccessPointProvider] unexpected transport failure',
			['exception' => $cause->getMessage()]
		);

		return new PeppolProviderException(
			message: 'The Peppol Access Point request failed unexpectedly: ' . $cause->getMessage(),
			previous: $cause
		);

	}//end transportFailed()
}//end class
