<?php

/**
 * Integriq IdpMetadataUnavailable Exception.
 *
 * Raised when a registration's metadata cannot be published because a
 * certificate it names cannot be read. The message names the registration
 * and the role, never a byte of the credential.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a registration's certificate cannot be read for its metadata.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */
class IdpMetadataUnavailableException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $detail What could not be read, naming the registration and the role only.
	 */
	public function __construct(string $detail) {
		parent::__construct(message: $detail);

	}//end __construct()
}//end class
