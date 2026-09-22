<?php

/**
 * Integriq LogGovernmentIdpAdapter.
 *
 * The dormant seam. It records the attempt and throws "broker not
 * configured", and it never authenticates anybody. Every provider binds to
 * this until a live broker is configured, its certificates are loaded, the
 * feature flag is flipped and the binding is swapped.
 *
 * It is a class rather than a null binding because a missing binding fails
 * with a container error nobody can act on, and a fail-open resolver, the
 * `catch (Throwable) { return null; }` shape, would let a caller read "no
 * adapter" as "no check needed".
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

use OCA\Integriq\Exception\IdpAssertionException;
use Psr\Log\LoggerInterface;

/**
 * The adapter that refuses instead of authenticating.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
 */
class LogGovernmentIdpAdapter implements GovernmentIdpAdapterInterface {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records the attempt.
	 * @param string $providerId The provider this instance stands in for.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $providerId = TrustLevelMapper::PROVIDER_DIGID,
	) {

	}//end __construct()

	/**
	 * The provider id this adapter answers to.
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function getProviderId(): string {
		return $this->providerId;

	}//end getProviderId()

	/**
	 * Whether this adapter can authenticate anybody. It cannot.
	 *
	 * @return boolean Always false.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function isConfigured(): bool {
		return false;

	}//end isConfigured()

	/**
	 * Record the attempt and refuse it.
	 *
	 * @param array<string,mixed> $context The authentication context.
	 *
	 * @return array{requestId: string, redirectUrl: string} Never returns.
	 *
	 * @throws IdpAssertionException Always.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function beginAuthentication(array $context): array {
		$this->refuse(call: 'beginAuthentication', context: $context);

	}//end beginAuthentication()

	/**
	 * Record the attempt and refuse it.
	 *
	 * @param array<string,mixed> $callback What arrived on the callback endpoint.
	 *
	 * @return array<string,mixed> Never returns.
	 *
	 * @throws IdpAssertionException Always.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function readAssertion(array $callback): array {
		$this->refuse(call: 'readAssertion', context: []);

	}//end readAssertion()

	/**
	 * Log and throw.
	 *
	 * The callback payload is deliberately not logged: it is the one place a
	 * raw assertion exists, and a log line is the easiest place to leak one
	 * from.
	 *
	 * @param string $call Which method was reached.
	 * @param array<string,mixed> $context The non-assertion context.
	 *
	 * @return never
	 *
	 * @throws IdpAssertionException Always.
	 */
	private function refuse(string $call, array $context): never {
		$this->logger->warning(
			'Integriq idp-broker: an authentication reached the dormant adapter, so no broker is configured.',
			[
				'provider' => $this->providerId,
				'call' => $call,
				'organisation' => (string)($context['organisation'] ?? ''),
				'consumer' => (string)($context['consumer'] ?? ''),
			]
		);

		throw new IdpAssertionException(
			message: 'Broker not configured for provider "' . $this->providerId
			. '": no authentication, envelope or session results.'
		);

	}//end refuse()

}//end class
