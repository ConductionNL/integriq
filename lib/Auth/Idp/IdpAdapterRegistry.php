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
	];

	/**
	 * Constructor.
	 *
	 * @param GovernmentIdpAdapterInterface $boundAdapter The adapter the container binds.
	 * @param LoggerInterface $logger Handed to a dormant adapter.
	 */
	public function __construct(
		private readonly GovernmentIdpAdapterInterface $boundAdapter,
		private readonly LoggerInterface $logger,
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

}//end class
