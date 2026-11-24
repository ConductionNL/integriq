<?php

/**
 * Integriq IdpKindAdapterFactories.
 *
 * The adapter factories this app ships, one per registration kind (D1): the
 * OIDC Relying Party for `oidc-rp` and the SAML Service Provider for
 * `saml-sp`. The registry asks it for the builder of a kind; a kind without a
 * factory has none, so its registrations are never live.
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

/**
 * The built-in builder per registration kind.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
final class IdpKindAdapterFactories {

	/**
	 * Constructor.
	 *
	 * @param OidcRelyingPartyAdapterFactory|null    $oidc Builds an `oidc-rp` registration's adapter.
	 * @param SamlServiceProviderAdapterFactory|null $saml Builds a `saml-sp` registration's adapter.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function __construct(
		private readonly ?OidcRelyingPartyAdapterFactory $oidc = null,
		private readonly ?SamlServiceProviderAdapterFactory $saml = null,
	) {

	}//end __construct()

	/**
	 * The builder of a registration kind, taking the IdpRegistration, or null.
	 *
	 * @param string $kind The registration kind.
	 *
	 * @return callable|null
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function builder(string $kind): ?callable {
		if ($kind === IdpRegistration::KIND_OIDC && $this->oidc !== null) {
			return $this->oidc->build(...);
		}

		if ($kind === IdpRegistration::KIND_SAML && $this->saml !== null) {
			return $this->saml->build(...);
		}

		return null;

	}//end builder()

}//end class
