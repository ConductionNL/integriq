<?php

/**
 * Integriq learning material source unavailable exception.
 *
 * Thrown when a learning material source times out, answers with an error
 * or answers with a body that is not a search response.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use RuntimeException;

/**
 * The source did not answer.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
final class SourceUnavailableException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $source The source's name, such as `edurep`.
	 * @param string|null $lastSuccess ISO 8601 time of the last answered call, or null when none is known.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function __construct(
		public readonly string $source,
		public readonly ?string $lastSuccess,
	) {
		parent::__construct(message: sprintf('The learning material source %s did not answer.', $source));
	}//end __construct()
}//end class
