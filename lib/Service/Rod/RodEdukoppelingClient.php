<?php

/**
 * Integriq DUO ROD Edukoppeling Client.
 *
 * Live binding for {@see RodProviderInterface} against DUO's ROD (Register
 * Onderwijsdeelnemers) koppelvlak over Edukoppeling — the education
 * sector's profile of the Digikoppeling M2M transport standards. Rather
 * than a bespoke SOAP/HTTP client, this is a thin wrapper over the
 * transport machinery `digikoppeling-adapter` already ships: signing is
 * resolved through {@see \OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver}
 * and the envelope is signed via
 * {@see \OCA\Integriq\Adapters\Digikoppeling\WusProfileService}.
 *
 * BLOCKED TODAY, ON PURPOSE (M3(c), decisions.md): `resolveSigningMaterial()`
 * fails closed for EVERY certificateRef until OpenRegister's credential
 * broker ships `issueSigningMaterial` (see PkiOverheidCredentialResolver's
 * own docblock) — the same blocker `berichtenbox-digital-post-adapter`
 * documents for its own live leg. This class therefore always refuses
 * closed past that point today; the HTTP dispatch below is written for
 * when both the broker capability and a DUO software-vendor certificate
 * (M3(c), open) exist, but is unverified against any live DUO endpoint —
 * no ROD test-environment access was available in this environment.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\RodProviderException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Edukoppeling (Digikoppeling WUS) ROD provider: signed envelope dispatch.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class RodEdukoppelingClient implements RodProviderInterface {

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
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string {
		return 'edukoppeling';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The ROD Edukoppeling source configuration JSON Schema.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['endpoint', 'certificateRef'],
			'properties' => [
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'description' => 'DUO ROD Edukoppeling endpoint URL (WUS transport profile).',
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
	 * @param array $sourceConfiguration The ROD source's `configuration` object.
	 * @param string $berichtsoort The berichtsoort being sent.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered Edukoppeling envelope.
	 *
	 * @return string The extracted reference.
	 *
	 * @throws RodProviderException When no certificate reference resolves (refuses closed — see class
	 *                              docblock), the endpoint is missing, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function send(array $sourceConfiguration, string $berichtsoort, string $kenmerk, string $envelopeXml): string {
		$certificateRef = (string)($sourceConfiguration['certificateRef'] ?? '');

		try {
			$this->credentialResolver->resolveSigningMaterial(certificateRef: $certificateRef);
		} catch (DigikoppelingException $exception) {
			throw new RodProviderException(
				message: $this->l->t('DUO ROD send refused') . ': ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$endpoint = rtrim((string)($sourceConfiguration['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw new RodProviderException(
				message: $this->l->t('DUO ROD endpoint missing') . ': `configuration.endpoint` is required.'
			);
		}

		// Unreachable today: resolveSigningMaterial() above always throws
		// until OpenRegister's credential broker can issue in-process
		// signing material (see class docblock). Written for that future
		// state rather than left absent, so the shape is reviewable now.
		$signedXml = $this->wusProfileService->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $envelopeXml);

		try {
			$response = $this->httpClient->request(
				'POST',
				$endpoint,
				[
					'headers' => ['Content-Type' => 'application/xml', 'X-Berichtsoort' => $berichtsoort],
					'body' => $signedXml,
					'http_errors' => false,
				]
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning('[RodEdukoppelingClient] unexpected transport failure', ['exception' => $exception->getMessage()]);
			throw new RodProviderException(
				message: 'The DUO ROD request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new RodProviderException(message: 'DUO ROD endpoint responded with HTTP ' . $status . '.');
		}

		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $kenmerk;
		}

		return $this->wusProfileService->verifyResponse(responseXml: $body);
	}//end send()
}//end class
