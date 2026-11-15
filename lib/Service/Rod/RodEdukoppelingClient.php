<?php

/**
 * Integriq DUO ROD Edukoppeling Client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Live binding for {@see RodProviderInterface} against DUO's ROD (Register
 * Onderwijsdeelnemers) koppelvlak over Edukoppeling — the education
 * sector's profile of the Digikoppeling M2M transport standards. Rather
 * than a bespoke SOAP/HTTP client, this is a thin wrapper over the
 * transport machinery `digikoppeling-adapter` already ships: signing is
 * resolved through {@see PkiOverheidCredentialResolverInterface}
 * and the envelope is signed via
 * {@see SignedSoapProfileInterface}.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRefusals;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Edukoppeling (Digikoppeling WUS) ROD provider: signed envelope dispatch.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class RodEdukoppelingClient implements RodProviderInterface, SignedEnvelopeRefusals {

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param PkiOverheidCredentialResolverInterface $credentialResolver Resolves `certificateRef` into signing material.
	 * @param SignedSoapProfileInterface $wusProfileService Signs the envelope for the WUS transport profile.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly PkiOverheidCredentialResolverInterface $credentialResolver,
		private readonly SignedSoapProfileInterface $wusProfileService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `edukoppeling` provider identifier.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string {
		return 'edukoppeling';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The ROD Edukoppeling source configuration JSON Schema.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array {
		return SignedEnvelopeDelivery::configSchema(
			endpointDescription: 'DUO ROD Edukoppeling endpoint URL (WUS transport profile).',
			certificateDescription: 'Broker credentialRef for the PKIoverheid / DUO software-vendor certificate. '
				. 'Never stored here (ADR-007). Activation is gated on the DUO certificate holder decision '
				. '(M3(c), open in decisions.md).'
		);

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
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function send(array $sourceConfiguration, string $berichtsoort, string $kenmerk, string $envelopeXml): string {
		$request = new SignedEnvelopeRequest(
			sourceConfiguration: $sourceConfiguration,
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			headers: ['X-Berichtsoort' => $berichtsoort]
		);

		return $this->delivery()->deliver(request: $request);
	}//end send()

	/**
	 * The delivery that signs and sends this client's envelopes.
	 *
	 * @return SignedEnvelopeDelivery The delivery.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	private function delivery(): SignedEnvelopeDelivery {
		return new SignedEnvelopeDelivery(
			httpClient: $this->httpClient,
			credentialResolver: $this->credentialResolver,
			profile: $this->wusProfileService,
			refusals: $this
		);
	}//end delivery()

	/**
	 * {@inheritDoc}
	 *
	 * @param DigikoppelingException $cause The resolver's refusal.
	 *
	 * @return RodProviderException The refusal.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function signingRefused(DigikoppelingException $cause): RodProviderException {
		return new RodProviderException(
			message: $this->l->t('DUO ROD send refused') . ': ' . $cause->getMessage(),
			previous: $cause
		);
	}//end signingRefused()

	/**
	 * {@inheritDoc}
	 *
	 * @return RodProviderException The refusal.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function endpointMissing(): RodProviderException {
		return new RodProviderException(
			message: $this->l->t('DUO ROD endpoint missing') . ': `configuration.endpoint` is required.'
		);
	}//end endpointMissing()

	/**
	 * {@inheritDoc}
	 *
	 * @param GuzzleException $cause The transport failure.
	 *
	 * @return RodProviderException The refusal.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function transportFailed(GuzzleException $cause): RodProviderException {
		// A transport message can quote the request or response body, which
		// carries the persoonsgebonden nummer: redact before logging or rethrowing.
		$detail = (new RodPersonalNumberRedactor())->redact(text: $cause->getMessage());
		$this->logger->warning('[RodEdukoppelingClient] unexpected transport failure', ['exception' => $detail]);

		return new RodProviderException(
			message: 'The DUO ROD request failed unexpectedly: ' . $detail,
			previous: $cause
		);
	}//end transportFailed()

	/**
	 * {@inheritDoc}
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return RodProviderException The refusal.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function statusRefused(int $status): RodProviderException {
		return new RodProviderException(message: 'DUO ROD endpoint responded with HTTP ' . $status . '.');
	}//end statusRefused()
}//end class
