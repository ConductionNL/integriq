<?php

/**
 * Raised when a binding has no source configured behind it.
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
 * A configuration error, raised before any HTTP call is attempted.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class MissingSourceConfigurationException extends PropertySourceException {
	/**
	 * Constructor.
	 *
	 * @param string $providerId Provider id whose source is missing.
	 * @param string $sourceSlug The source slug that is not configured.
	 */
	public function __construct(string $providerId, string $sourceSlug) {
		parent::__construct(
			providerId: $providerId,
			message: sprintf(
				'Provider "%s" has no source configured under "%s", so no call was made.',
				$providerId,
				$sourceSlug
			)
		);

		$this->sourceSlug = $sourceSlug;
	}//end __construct()

	/**
	 * The source slug that was missing.
	 *
	 * @var string
	 */
	private string $sourceSlug = '';

	/**
	 * The source slug that was missing.
	 *
	 * @return string Source slug.
	 */
	public function getSourceSlug(): string {
		return $this->sourceSlug;
	}//end getSourceSlug()
}//end class
