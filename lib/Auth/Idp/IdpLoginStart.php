<?php

/**
 * Integriq IdpLoginStart: one login being started at the broker — the
 * provider and organisation, the consumer and the address it returns to, the
 * trust asked for, the consumer's relay state and the broker's own nonce.
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

/**
 * One login start in progress. Built and used by IdpLoginService only.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */
final class IdpLoginStart {
	/**
	 * The consumer id asked for; once checked, the consumer's own id.
	 *
	 * @var string
	 */
	public string $consumer = '';

	/**
	 * The requested trust.
	 *
	 * @var string
	 */
	public string $trust = '';

	/**
	 * The return address asked for.
	 *
	 * @var string
	 */
	public string $returnUrl = '';

	/**
	 * The consumer's relay state.
	 *
	 * @var string
	 */
	public string $relayState = '';

	/**
	 * The broker's own relay state toward the identity provider.
	 *
	 * @var string
	 */
	public string $nonce = '';

	/**
	 * The attributes an attribute login asks the person to disclose; null for any other login.
	 *
	 * @var AttributeDisclosureRequest|null
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public ?AttributeDisclosureRequest $disclosure = null;

	/**
	 * Constructor.
	 *
	 * @param string $provider     The provider id from the route.
	 * @param string $organisation The organisation the login is for.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $organisation,
	) {
	}//end __construct()
}//end class
