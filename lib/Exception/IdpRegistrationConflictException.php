<?php

/**
 * Integriq IdpRegistrationConflictException.
 *
 * Thrown when a second identity provider registration is saved for an
 * organisation and provider that already have one.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * A second registration for the same organisation and provider.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class IdpRegistrationConflictException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $existing The id of the registration that already exists.
	 * @param string $message  The message, naming the existing registration.
	 */
	public function __construct(
		public readonly string $existing,
		string $message,
	) {
		parent::__construct(message: $message);

	}//end __construct()
}//end class
