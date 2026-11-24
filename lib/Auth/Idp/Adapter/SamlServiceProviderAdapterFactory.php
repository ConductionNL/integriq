<?php

/**
 * Integriq SamlServiceProviderAdapterFactory.
 *
 * Builds the SAML Service Provider adapter of one ready `saml-sp`
 * registration, with the collaborators the container provides, the
 * registration's EntityID (D3) and its provider callback as the assertion
 * consumer service.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use OCA\Integriq\Auth\Idp\IdpMetadataService;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;

/**
 * One SAML Service Provider adapter per ready registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class SamlServiceProviderAdapterFactory {

	/**
	 * Constructor.
	 *
	 * @param IdpSecretSeam      $secrets      Reads the registration's credentials.
	 * @param IClientService     $http         Reads the identity provider's metadata.
	 * @param TrustLevelMapper   $trust        Says which spellings reach the floor.
	 * @param IURLGenerator      $urlGenerator Builds the assertion consumer service address.
	 * @param IdpMetadataService $metadata     Answers the registration's EntityID, as its metadata publishes it.
	 */
	public function __construct(
		private readonly IdpSecretSeam $secrets,
		private readonly IClientService $http,
		private readonly TrustLevelMapper $trust,
		private readonly IURLGenerator $urlGenerator,
		private readonly IdpMetadataService $metadata,
	) {

	}//end __construct()

	/**
	 * The adapter of one registration.
	 *
	 * @param IdpRegistration $registration The ready `saml-sp` registration.
	 *
	 * @return SamlServiceProviderAdapter
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function build(IdpRegistration $registration): SamlServiceProviderAdapter {
		return new SamlServiceProviderAdapter(
			registration: $registration,
			secrets: $this->secrets,
			http: $this->http,
			trust: $this->trust,
			entityId: $this->metadata->entityId(registration: $registration),
			acsUrl: $this->urlGenerator->linkToRouteAbsolute(
				'integriq.idpBroker.callback',
				['provider' => $registration->provider()]
			)
		);

	}//end build()

}//end class
