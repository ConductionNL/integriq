<?php

/**
 * Integriq UWLR/Edu-V Kennisnet Client.
 *
 * Live binding for UwlrEduVProviderInterface against the shared
 * Kennisnet-adjacent koppelvlak for UWLR, Edu-V, Basispoort and
 * Entree-content. Reuses the same WUS transport and PKIoverheid
 * certificate resolution as `OsoKennisnetClient`/`RodEdukoppelingClient`/
 * `VerzuimloketEdukoppelingClient` — the transport machinery is shared
 * even though each of the four targets' own governance gate differs
 * (Edu-V keurmerk per data service, Basispoort's own connection
 * agreement, UWLR's own access agreement — see decisions.md M3(c)).
 *
 * BLOCKED TODAY, ON PURPOSE (M3(c), decisions.md): identical fail-closed
 * shape as the other three adapters in this lane —
 * `PkiOverheidCredentialResolver::resolveSigningMaterial()` fails closed
 * for every certificateRef until OpenRegister ships `issueSigningMaterial`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Kennisnet UWLR/Edu-V/Basispoort/Entree-content provider: signed envelope dispatch.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
 */
class UwlrEduVKennisnetClient implements UwlrEduVProviderInterface {

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
	 * @return string The stable `uwlr-eduv` provider identifier.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getProviderId(): string {
		return 'uwlr-eduv';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The UWLR/Edu-V Kennisnet source configuration JSON Schema.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['endpoint', 'certificateRef'],
			'properties' => [
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'description' => 'UWLR/Edu-V/Basispoort/Entree-content koppelvlak endpoint URL.',
				],
				'certificateRef' => [
					'type' => 'string',
					'description' => 'Broker credentialRef for the PKIoverheid certificate. Never stored here '
						. '(ADR-007). Required when provider=uwlr-eduv.',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The UWLR/Edu-V source's `configuration` object.
	 * @param string $target One of `uwlr`, `edu-v`, `basispoort`, `entree-content`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered envelope.
	 *
	 * @return string The extracted reference.
	 *
	 * @throws UwlrEduVProviderException When no certificate reference resolves, the endpoint is missing,
	 *                                   or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 */
	public function send(array $sourceConfiguration, string $target, string $kenmerk, string $envelopeXml): string {
		$certificateRef = (string)($sourceConfiguration['certificateRef'] ?? '');

		try {
			$this->credentialResolver->resolveSigningMaterial(certificateRef: $certificateRef);
		} catch (DigikoppelingException $exception) {
			throw new UwlrEduVProviderException(
				message: $this->l->t('UWLR/Edu-V send refused') . ': ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$endpoint = rtrim((string)($sourceConfiguration['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw new UwlrEduVProviderException(
				message: $this->l->t('UWLR/Edu-V endpoint missing') . ': `configuration.endpoint` is required.'
			);
		}

		// Unreachable today: resolveSigningMaterial() above always throws
		// until OpenRegister's credential broker can issue in-process
		// signing material (see class docblock).
		$signedXml = $this->wusProfileService->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $envelopeXml);

		try {
			$response = $this->httpClient->request(
				'POST',
				$endpoint . '/' . $target,
				[
					'headers' => ['Content-Type' => 'application/xml'],
					'body' => $signedXml,
					'http_errors' => false,
				]
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning('[UwlrEduVKennisnetClient] unexpected transport failure', ['exception' => $exception->getMessage()]);
			throw new UwlrEduVProviderException(
				message: 'The UWLR/Edu-V send request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new UwlrEduVProviderException(message: 'UWLR/Edu-V endpoint responded with HTTP ' . $status . '.');
		}

		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $kenmerk;
		}

		return $this->wusProfileService->verifyResponse(responseXml: $body);
	}//end send()
}//end class
