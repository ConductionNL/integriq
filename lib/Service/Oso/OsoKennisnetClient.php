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
 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRefusals;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Kennisnet OSO export provider: signed envelope dispatch.
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */
class OsoKennisnetClient implements OsoProviderInterface, SignedEnvelopeRefusals {

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
	 * @return string The stable `kennisnet` provider identifier.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getProviderId(): string {
		return 'kennisnet';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The OSO Kennisnet source configuration JSON Schema.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function getConfigSchema(): array {
		return SignedEnvelopeDelivery::configSchema(
			endpointDescription: 'Kennisnet OSO export endpoint URL.',
			certificateDescription: 'Broker credentialRef for the PKIoverheid certificate. Never stored here '
				. '(ADR-007). Required when provider=kennisnet.'
		);

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
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function sendExport(array $sourceConfiguration, string $kenmerk, string $envelopeXml): string {
		$request = new SignedEnvelopeRequest(
			sourceConfiguration: $sourceConfiguration,
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml
		);

		$delivery = new SignedEnvelopeDelivery(
			httpClient: $this->httpClient,
			credentialResolver: $this->credentialResolver,
			profile: $this->wusProfileService,
			refusals: $this
		);

		return $delivery->deliver(request: $request);
	}//end sendExport()

	/**
	 * {@inheritDoc}
	 *
	 * @param DigikoppelingException $cause The resolver's refusal.
	 *
	 * @return OsoProviderException The refusal.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function signingRefused(DigikoppelingException $cause): OsoProviderException {
		return new OsoProviderException(
			message: $this->l->t('OSO export refused') . ': ' . $cause->getMessage(),
			previous: $cause
		);
	}//end signingRefused()

	/**
	 * {@inheritDoc}
	 *
	 * @return OsoProviderException The refusal.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function endpointMissing(): OsoProviderException {
		return new OsoProviderException(
			message: $this->l->t('OSO endpoint missing') . ': `configuration.endpoint` is required.'
		);
	}//end endpointMissing()

	/**
	 * {@inheritDoc}
	 *
	 * @param GuzzleException $cause The transport failure.
	 *
	 * @return OsoProviderException The refusal.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function transportFailed(GuzzleException $cause): OsoProviderException {
		$this->logger->warning('[OsoKennisnetClient] unexpected transport failure', ['exception' => $cause->getMessage()]);

		return new OsoProviderException(
			message: 'The OSO export request failed unexpectedly: ' . $cause->getMessage(),
			previous: $cause
		);
	}//end transportFailed()

	/**
	 * {@inheritDoc}
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return OsoProviderException The refusal.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function statusRefused(int $status): OsoProviderException {
		return new OsoProviderException(message: 'OSO endpoint responded with HTTP ' . $status . '.');
	}//end statusRefused()
}//end class
