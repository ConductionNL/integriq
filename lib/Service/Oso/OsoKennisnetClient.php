<?php

/**
 * Integriq OSO Kennisnet Client.
 *
 * Live binding for OsoProviderInterface against Kennisnet's Overstapservice
 * Onderwijs (OSO) export koppelvlak. Reuses the same WUS transport and
 * PKIoverheid certificate resolution as `RodEdukoppelingClient`/
 * `VerzuimloketEdukoppelingClient` — the transport machinery is shared even
 * though OSO's own governance gate is Kennisnet's aansluiting approval, not
 * a DUO certificate (see class docblock in the other two adapters' clients
 * for the shared M3(c) framing).
 *
 * BLOCKED TODAY, ON PURPOSE (M3(c), decisions.md): identical fail-closed
 * shape as `RodEdukoppelingClient` — `PkiOverheidCredentialResolver::
 * resolveSigningMaterial()` fails closed for every certificateRef until
 * OpenRegister ships `issueSigningMaterial`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Oso
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\OsoProviderException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Kennisnet OSO export provider: signed envelope dispatch.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */
class OsoKennisnetClient implements OsoProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param PkiOverheidCredentialResolver $credentialResolver Resolves `certificateRef` into signing material.
	 * @param WusProfileService $wusProfileService Signs the envelope for the WUS transport profile.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly PkiOverheidCredentialResolver $credentialResolver,
		private readonly WusProfileService $wusProfileService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `kennisnet` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getProviderId(): string {
		return 'kennisnet';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The OSO Kennisnet source configuration JSON Schema.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['endpoint', 'certificateRef'],
			'properties' => [
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'description' => 'Kennisnet OSO export endpoint URL.',
				],
				'certificateRef' => [
					'type' => 'string',
					'description' => 'Broker credentialRef for the PKIoverheid certificate. Never stored here '
						. '(ADR-007). Required when provider=kennisnet.',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The OSO source's `configuration` object.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered export envelope.
	 *
	 * @return string The extracted reference.
	 *
	 * @throws OsoProviderException When no certificate reference resolves, the endpoint is missing,
	 *                              or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function sendExport(array $sourceConfiguration, string $kenmerk, string $envelopeXml): string {
		$certificateRef = (string)($sourceConfiguration['certificateRef'] ?? '');

		try {
			$this->credentialResolver->resolveSigningMaterial(certificateRef: $certificateRef);
		} catch (DigikoppelingException $exception) {
			throw new OsoProviderException(
				message: $this->l->t('OSO export refused') . ': ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$endpoint = rtrim((string)($sourceConfiguration['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw new OsoProviderException(
				message: $this->l->t('OSO endpoint missing') . ': `configuration.endpoint` is required.'
			);
		}

		// Unreachable today: resolveSigningMaterial() above always throws
		// until OpenRegister's credential broker can issue in-process
		// signing material (see class docblock).
		$signedXml = $this->wusProfileService->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $envelopeXml);

		try {
			$response = $this->httpClient->request(
				'POST',
				$endpoint,
				[
					'headers' => ['Content-Type' => 'application/xml'],
					'body' => $signedXml,
					'http_errors' => false,
				]
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning('[OsoKennisnetClient] unexpected transport failure', ['exception' => $exception->getMessage()]);
			throw new OsoProviderException(
				message: 'The OSO export request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new OsoProviderException(message: 'OSO endpoint responded with HTTP ' . $status . '.');
		}

		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $kenmerk;
		}

		return $this->wusProfileService->verifyResponse(responseXml: $body);
	}//end sendExport()
}//end class
