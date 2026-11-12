<?php

/**
 * Who asks which registry source, for one read through RegistrySourceGateway.
 *
 * The provider id names the binding in every failure; the source slug names
 * the configured source in every failure message and log line.
 *
 * @category Service
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

/**
 * The asking provider and the source it reads.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
final class RegistryRead {

	/**
	 * Constructor.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $sourceSlug,
	) {
	}//end __construct()
}//end class
