<?php

/**
 * Integriq SRU transport.
 *
 * Sends one SRU searchRetrieve request and returns the body, so the source
 * reads live answers and recorded ones the same way.
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

/**
 * Sends SRU requests.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
interface SruTransport {
	/**
	 * Send one request.
	 *
	 * @param array<string,string> $parameters The SRU query parameters.
	 *
	 * @return string The response body.
	 *
	 * @throws SourceUnavailableException On a timeout, a transport error or a status other than 200.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function fetch(array $parameters): string;

	/**
	 * When the last request was answered.
	 *
	 * @return string|null ISO 8601 time, or null when none is known.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function lastSuccess(): ?string;
}//end interface
