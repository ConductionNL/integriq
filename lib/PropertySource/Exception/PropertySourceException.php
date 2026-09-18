<?php

/**
 * Base failure for the property-source resolver family.
 *
 * @category Exception
 * @package  OCA\Integriq\PropertySource\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\PropertySource\Exception;

use RuntimeException;

/**
 * Every property-source failure carries the provider id it happened under.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md
 */
class PropertySourceException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $providerId Provider id the failure happened under.
	 * @param string $message Human readable reason.
	 */
	public function __construct(
		private readonly string $providerId,
		string $message,
	) {
		parent::__construct($message);
	}//end __construct()

	/**
	 * The provider id this failure happened under.
	 *
	 * @return string Provider id.
	 */
	public function getProviderId(): string {
		return $this->providerId;
	}//end getProviderId()
}//end class
