<?php

/**
 * Integriq DUO Verzuimloket Edukoppeling Client.
 *
 * Live binding for VerzuimloketProviderInterface against DUO's Verzuimloket
 * (VSV-M2M) koppelvlak over Edukoppeling. Reuses the same WUS transport and
 * PKIoverheid certificate resolution as `RodEdukoppelingClient` — ROD,
 * Verzuimloket and OSO all share "1 certificaat per softwareleverancier"
 * (parnassys#13.1).
 *
 * BLOCKED TODAY, ON PURPOSE (M3(c), decisions.md): identical fail-closed
 * shape as `RodEdukoppelingClient` — `PkiOverheidCredentialResolver::
 * resolveSigningMaterial()` fails closed for every certificateRef until
 * OpenRegister ships `issueSigningMaterial`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Verzuimloket
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Edukoppeling (Digikoppeling WUS) Verzuimloket provider: signed envelope dispatch.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class VerzuimloketEdukoppelingClient implements VerzuimloketProviderInterface {

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
	 * @return string The stable `edukoppeling` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string {
		return 'edukoppeling';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The Verzuimloket Edukoppeling source configuration JSON Schema.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['endpoint', 'certificateRef'],
			'properties' => [
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'description' => 'DUO Verzuimloket Edukoppeling endpoint URL (WUS transport profile).',
				],
				'certificateRef' => [
					'type' => 'string',
					'description' => 'Broker credentialRef for the PKIoverheid / DUO software-vendor certificate. '
						. 'Never stored here (ADR-007). Activation is gated on the DUO certificate holder decision '
						. '(M3(c), open in decisions.md).',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The Verzuimloket source's `configuration` object.
	 * @param string $meldingType The melding kind being sent.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered Edukoppeling envelope.
	 *
	 * @return string The extracted reference.
	 *
	 * @throws VerzuimloketProviderException When no certificate reference resolves, the endpoint is
	 *                                       missing, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function send(array $sourceConfiguration, string $meldingType, string $kenmerk, string $envelopeXml): string {
		$certificateRef = (string)($sourceConfiguration['certificateRef'] ?? '');

		try {
			$this->credentialResolver->resolveSigningMaterial(certificateRef: $certificateRef);
		} catch (DigikoppelingException $exception) {
			throw new VerzuimloketProviderException(
				message: $this->l->t('DUO Verzuimloket send refused') . ': ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$endpoint = rtrim((string)($sourceConfiguration['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw new VerzuimloketProviderException(
				message: $this->l->t('DUO Verzuimloket endpoint missing') . ': `configuration.endpoint` is required.'
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
					'headers' => ['Content-Type' => 'application/xml', 'X-Melding-Type' => $meldingType],
					'body' => $signedXml,
					'http_errors' => false,
				]
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning('[VerzuimloketEdukoppelingClient] unexpected transport failure', ['exception' => $exception->getMessage()]);
			throw new VerzuimloketProviderException(
				message: 'The DUO Verzuimloket request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new VerzuimloketProviderException(message: 'DUO Verzuimloket endpoint responded with HTTP ' . $status . '.');
		}

		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $kenmerk;
		}

		return $this->wusProfileService->verifyResponse(responseXml: $body);
	}//end send()
}//end class
