<?php

/**
 * Integriq IdpDisclosureIncomplete Exception.
 *
 * Raised at the callback of an attribute login (Yivi) when the identity
 * provider's answer lacks an attribute the login asked for. Like a login
 * below the floor it is explained to the person: integriq shows its own page
 * saying not every requested detail was shared, with one action back to the
 * consumer.
 *
 * It carries no attribute value, only where the page leads back to.
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
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when a requested attribute is missing from the identity provider's answer.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 */
class IdpDisclosureIncompleteException extends IdpAssertionException {

	/**
	 * Constructor.
	 *
	 * @param string $returnUrl Where the page's one action leads, with `error` and `relayState`; empty until known.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function __construct(
		private readonly string $returnUrl = '',
	) {
		parent::__construct(message: 'The identity provider did not return every attribute the login asked for.');

	}//end __construct()

	/**
	 * Where the refusal page's one action leads.
	 *
	 * @return string The consumer's return address with `error=attributes_not_disclosed` and the relay state.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
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
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function withReturnUrl(string $returnUrl): self {
		return new self(returnUrl: $returnUrl);

	}//end withReturnUrl()

}//end class
