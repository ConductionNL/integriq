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
 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRefusals;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Edukoppeling (Digikoppeling WUS) Verzuimloket provider: signed envelope dispatch.
 *
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class VerzuimloketEdukoppelingClient implements VerzuimloketProviderInterface, SignedEnvelopeRefusals {

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
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getProviderId(): string {
		return 'edukoppeling';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The Verzuimloket Edukoppeling source configuration JSON Schema.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function getConfigSchema(): array {
		return SignedEnvelopeDelivery::configSchema(
			endpointDescription: 'DUO Verzuimloket Edukoppeling endpoint URL (WUS transport profile).',
			certificateDescription: 'Broker credentialRef for the PKIoverheid / DUO software-vendor certificate. '
				. 'Never stored here (ADR-007). Activation is gated on the DUO certificate holder decision '
				. '(M3(c), open in decisions.md).'
		);

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
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function send(array $sourceConfiguration, string $meldingType, string $kenmerk, string $envelopeXml): string {
		$request = new SignedEnvelopeRequest(
			sourceConfiguration: $sourceConfiguration,
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			headers: ['X-Melding-Type' => $meldingType]
		);

		$delivery = new SignedEnvelopeDelivery(
			httpClient: $this->httpClient,
			credentialResolver: $this->credentialResolver,
			profile: $this->wusProfileService,
			refusals: $this
		);

		return $delivery->deliver(request: $request);
	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param DigikoppelingException $cause The resolver's refusal.
	 *
	 * @return VerzuimloketProviderException The refusal.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function signingRefused(DigikoppelingException $cause): VerzuimloketProviderException {
		return new VerzuimloketProviderException(
			message: $this->l->t('DUO Verzuimloket send refused') . ': ' . $cause->getMessage(),
			previous: $cause
		);
	}//end signingRefused()

	/**
	 * {@inheritDoc}
	 *
	 * @return VerzuimloketProviderException The refusal.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function endpointMissing(): VerzuimloketProviderException {
		return new VerzuimloketProviderException(
			message: $this->l->t('DUO Verzuimloket endpoint missing') . ': `configuration.endpoint` is required.'
		);
	}//end endpointMissing()

	/**
	 * {@inheritDoc}
	 *
	 * @param GuzzleException $cause The transport failure.
	 *
	 * @return VerzuimloketProviderException The refusal.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function transportFailed(GuzzleException $cause): VerzuimloketProviderException {
		$this->logger->warning('[VerzuimloketEdukoppelingClient] unexpected transport failure', ['exception' => $cause->getMessage()]);

		return new VerzuimloketProviderException(
			message: 'The DUO Verzuimloket request failed unexpectedly: ' . $cause->getMessage(),
			previous: $cause
		);
	}//end transportFailed()

	/**
	 * {@inheritDoc}
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return VerzuimloketProviderException The refusal.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function statusRefused(int $status): VerzuimloketProviderException {
		return new VerzuimloketProviderException(message: 'DUO Verzuimloket endpoint responded with HTTP ' . $status . '.');
	}//end statusRefused()
}//end class
