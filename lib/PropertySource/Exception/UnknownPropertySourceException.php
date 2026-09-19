<?php

/**
 * Raised when a schema declares a provider id no binding answers to.
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

/**
 * An unknown provider fails loudly rather than resolving to nothing.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
class UnknownPropertySourceException extends PropertySourceException {
	/**
	 * Constructor.
	 *
	 * @param string $providerId The provider id nothing answers to.
	 * @param array<int,string> $known Provider ids that do exist.
	 */
	public function __construct(string $providerId, array $known = []) {
		$knownText = '(none)';
		if ($known !== []) {
			$knownText = implode(', ', $known);
		}

		parent::__construct(
			$providerId,
			sprintf(
				'No property source provider is registered under the id "%s". Registered ids: %s.',
				$providerId,
				$knownText
			)
		);
	}//end __construct()
}//end class
