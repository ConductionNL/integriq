<?php

/**
 * Integriq OidcRelyingPartyAdapterFactory.
 *
 * Builds the OIDC Relying Party adapter of one ready `oidc-rp` registration,
 * with the collaborators the container provides and the registration's
 * provider callback as its redirect URI.
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

use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;

/**
 * One OIDC Relying Party adapter per ready registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class OidcRelyingPartyAdapterFactory {

	/**
	 * Constructor.
	 *
	 * @param IdpSecretSeam    $secrets      Reads the registration's credentials.
	 * @param IClientService   $http         Reaches the issuer.
	 * @param OidcLoginStore   $logins       Holds each login's nonce and code verifier.
	 * @param TrustLevelMapper $trust        Says which spellings reach the floor.
	 * @param IURLGenerator    $urlGenerator Builds the callback address.
	 */
	public function __construct(
		private readonly IdpSecretSeam $secrets,
		private readonly IClientService $http,
		private readonly OidcLoginStore $logins,
		private readonly TrustLevelMapper $trust,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * The adapter of one registration.
	 *
	 * @param IdpRegistration $registration The ready `oidc-rp` registration.
	 *
	 * @return OidcRelyingPartyAdapter
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function build(IdpRegistration $registration): OidcRelyingPartyAdapter {
		return new OidcRelyingPartyAdapter(
			registration: $registration,
			secrets: $this->secrets,
			http: $this->http,
			logins: $this->logins,
			trust: $this->trust,
			redirectUri: $this->urlGenerator->linkToRouteAbsolute(
				'integriq.idpBroker.callback',
				['provider' => $registration->provider()]
			)
		);

	}//end build()

}//end class
