<?php

/**
 * Integriq IdpRegistrationRefusedException.
 *
 * Thrown when a registration's own fields break a save rule.
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
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * A registration that may not be saved as it is.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
class IdpRegistrationRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $reason  The refusal code, for example idp_registration_needs_oidc.
	 * @param string $message The message for the administrator.
	 */
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct(message: $message);

	}//end __construct()
}//end class
