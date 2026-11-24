<?php

/**
 * Integriq IdpAdapterRegistry.
 *
 * Answers the adapter for one provider. The container binds one adapter; a
 * provider it does not answer to gets the dormant adapter for that provider,
 * which logs and refuses. So a provider without a live binding refuses, and
 * never falls through to another provider's adapter.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapterFactory;
use OCA\Integriq\Auth\Idp\Adapter\SamlServiceProviderAdapterFactory;
use OCA\Integriq\Exception\IdpAssertionException;
use Psr\Log\LoggerInterface;

/**
 * One adapter per provider.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */
class IdpAdapterRegistry {

	/**
	 * The providers a login can be started for.
	 *
	 * @var array<int,string>
	 */
	public const PROVIDERS = [
		TrustLevelMapper::PROVIDER_DIGID,
		TrustLevelMapper::PROVIDER_EHERKENNING,
		TrustLevelMapper::PROVIDER_EIDAS,
		TrustLevelMapper::PROVIDER_YIVI,
	];

	/**
	 * Constructor.
	 *
	 * @param GovernmentIdpAdapterInterface $boundAdapter The adapter the container binds.
	 * @param LoggerInterface $logger Handed to a dormant adapter.
	 * @param IdpRegistrationService|null $registrations The per-organisation registrations and their
	 *                                                   readiness (portal-idp-broker-config); null
	 *                                                   when OpenRegister is not there.
	 * @param array<string, callable> $kindAdapters A builder per registration kind (`saml-sp`, `oidc-rp`),
	 *                                              taking the IdpRegistration; a kind without one is never live.
	 * @param OidcRelyingPartyAdapterFactory|null $oidcAdapters Builds an `oidc-rp` registration's adapter when
	 *                                                         $kindAdapters names no builder for that kind.
	 * @param SamlServiceProviderAdapterFactory|null $samlAdapters Builds a `saml-sp` registration's adapter when
	 *                                                            $kindAdapters names no builder for that kind.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function __construct(
		private readonly GovernmentIdpAdapterInterface $boundAdapter,
		private readonly LoggerInterface $logger,
		private readonly ?IdpRegistrationService $registrations = null,
		private readonly array $kindAdapters = [],
		private readonly ?OidcRelyingPartyAdapterFactory $oidcAdapters = null,
		private readonly ?SamlServiceProviderAdapterFactory $samlAdapters = null,
	) {

	}//end __construct()

	/**
	 * The adapter for one provider.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return GovernmentIdpAdapterInterface The adapter.
	 *
	 * @throws IdpAssertionException When the provider is not one this broker knows.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function forProvider(string $provider): GovernmentIdpAdapterInterface {
		if (in_array($provider, self::PROVIDERS, true) === false) {
			throw new IdpAssertionException(message: 'Unknown identity provider, so no login starts.');
		}

		if ($this->boundAdapter->getProviderId() === $provider) {
			return $this->boundAdapter;
		}

		return new LogGovernmentIdpAdapter(logger: $this->logger, providerId: $provider);

	}//end forProvider()

	/**
	 * The adapter for one organisation's login through one provider.
	 *
	 * A live adapter only when the organisation's registration is ready (the
	 * same readiness the settings screen shows) and its kind has a builder;
	 * in every other case the Log adapter, which refuses.
	 *
	 * @param string $organisation The organisation the login is for.
	 * @param string $provider     The provider id.
	 *
	 * @return GovernmentIdpAdapterInterface The adapter.
	 *
	 * @throws IdpAssertionException When the provider is not one this broker knows.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function forRegistration(string $organisation, string $provider): GovernmentIdpAdapterInterface {
		if (in_array($provider, self::PROVIDERS, true) === false) {
			throw new IdpAssertionException(message: 'Unknown identity provider, so no login starts.');
		}

		$refusing = new LogGovernmentIdpAdapter(logger: $this->logger, providerId: $provider);
		$registration = $this->registrations?->find(organisation: $organisation, provider: $provider);
		if ($registration === null) {
			return $refusing;
		}

		if ($this->registrations->readiness(registration: $registration)->isReady() === false) {
			return $refusing;
		}

		$build = ($this->kindAdapters[$registration->kind()] ?? $this->builtInBuilder(kind: $registration->kind()));
		if (is_callable($build) === false) {
			return $refusing;
		}

		return $build($registration);

	}//end forRegistration()

	/**
	 * The builder this app ships for a registration kind, or null.
	 *
	 * @param string $kind The registration kind.
	 *
	 * @return callable|null
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	private function builtInBuilder(string $kind): ?callable {
		if ($kind === IdpRegistration::KIND_OIDC && $this->oidcAdapters !== null) {
			return $this->oidcAdapters->build(...);
		}

		if ($kind === IdpRegistration::KIND_SAML && $this->samlAdapters !== null) {
			return $this->samlAdapters->build(...);
		}

		return null;

	}//end builtInBuilder()

}//end class
