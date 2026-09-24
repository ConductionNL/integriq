<?php

/**
 * Integriq GovernmentIdpAdapterInterface.
 *
 * One government identity provider is one adapter: it starts an
 * authentication and it turns what comes back into a normalised assertion.
 * Everything after that, the guard, the pseudonym, the trust mapping and the
 * envelope, is shared and knows nothing about SAML or OIDC.
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * The contract every government identity provider answers to.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
 */
interface GovernmentIdpAdapterInterface {

	/**
	 * The provider id this adapter answers to.
	 *
	 * @return string `digid`, `eherkenning` or `eidas`.
	 */
	public function getProviderId(): string;

	/**
	 * Whether this adapter can actually authenticate anybody.
	 *
	 * @return boolean True only when a live broker is configured.
	 */
	public function isConfigured(): bool;

	/**
	 * Start an authentication.
	 *
	 * @param array<string,mixed> $context `{organisation, consumer, trust, relayState}`.
	 *
	 * @return array{requestId: string, redirectUrl: string} Where to send the browser, and what to expect back.
	 *
	 * @throws \OCA\Integriq\Exception\IdpAssertionException When the broker is not configured.
	 */
	public function beginAuthentication(array $context): array;

	/**
	 * Turn what the provider sent back into a normalised assertion.
	 *
	 * The returned shape is what {@see AssertionGuard} reads:
	 * `{id, inResponseTo, audience, notBefore, notOnOrAfter, subject,
	 * subType, assuranceLevel, organisation}`.
	 *
	 * @param array<string,mixed> $callback What arrived on the callback endpoint.
	 *
	 * @return array<string,mixed> The normalised assertion.
	 *
	 * @throws \OCA\Integriq\Exception\IdpAssertionException When the broker is not configured or the callback is unreadable.
	 */
	public function readAssertion(array $callback): array;

}//end interface
