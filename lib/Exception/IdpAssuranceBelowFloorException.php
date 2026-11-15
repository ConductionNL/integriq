<?php

/**
 * Integriq IdpAssuranceBelowFloor Exception.
 *
 * Raised at the callback when the identity provider answered with a level
 * below the assurance floor. Unlike every other refusal it is explained to
 * the person: integriq shows its own page naming the level they used and the
 * level this service needs, with one action back to the consumer.
 *
 * It carries the provider and the level the person chose, both of which the
 * person already knows, and never the subject.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when an assertion's assurance level is below the floor.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 */
class IdpAssuranceBelowFloorException extends IdpAssertionException {

	/**
	 * Constructor.
	 *
	 * @param string $provider  The provider the login came back on.
	 * @param string $levelUsed The canonical level the assertion carried.
	 * @param string $returnUrl Where the page's one action leads, with `error` and `relayState`; empty until known.
	 */
	public function __construct(
		private readonly string $provider,
		private readonly string $levelUsed,
		private readonly string $returnUrl = '',
	) {
		parent::__construct(message: 'The assurance level is below the floor of substantial.');

	}//end __construct()

	/**
	 * The provider the login came back on.
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function getProvider(): string {
		return $this->provider;

	}//end getProvider()

	/**
	 * The canonical level the assertion carried.
	 *
	 * @return string The level.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function getLevelUsed(): string {
		return $this->levelUsed;

	}//end getLevelUsed()

	/**
	 * Where the refusal page's one action leads.
	 *
	 * @return string The consumer's return address with `error=assurance_below_floor` and the relay state.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function getReturnUrl(): string {
		return $this->returnUrl;

	}//end getReturnUrl()

	/**
	 * The same refusal, with the address its page leads back to.
	 *
	 * @param string $returnUrl The consumer's return address with the error and relay state.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function withReturnUrl(string $returnUrl): self {
		return new self(provider: $this->provider, levelUsed: $this->levelUsed, returnUrl: $returnUrl);

	}//end withReturnUrl()

}//end class
