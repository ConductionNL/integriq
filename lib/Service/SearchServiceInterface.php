<?php

/**
 * Contract of the SearchService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see SearchService} that other components call.
 */
interface SearchServiceInterface {
	/**
	 * This function unsets all keys starting with _ from filters.
	 *
	 * @param array $filters Query parameters from request.
	 *
	 * @return array $filters
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function unsetSpecialQueryParams(array $filters): array;

	/**
	 * Parses the request query string and returns it as an array of queries.
	 *
	 * @param string $queryString The input query string from the request.
	 *
	 * @return array The resulting array of query parameters.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function parseQueryString(string $queryString = ''): array;
}
