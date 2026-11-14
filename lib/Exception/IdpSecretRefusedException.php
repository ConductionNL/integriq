<?php

/**
 * Integriq IdpSecretRefused Exception.
 *
 * Raised when an identity provider secret cannot be read: Keepiq is missing,
 * the credential broker is missing, or the reference is empty, unknown or
 * resolves to nothing. The message names the registration and the role,
 * never a value or a reference. A refusal is never a skipped check.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when an identity provider secret is refused.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 */
class IdpSecretRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $reason The refusal, one of the IdpSecretSeam REASON_* constants.
	 * @param string $detail What was refused, naming the registration and the role only.
	 */
	public function __construct(
		public readonly string $reason,
		string $detail,
	) {
		parent::__construct(message: $detail);

	}//end __construct()
}//end class
