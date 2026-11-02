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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see SearchService} that other components call.
 */
interface SearchServiceInterface {
	/**
	 * Merge two facet aggregations by _id, summing the count.
	 *
	 * @param array $existingAggregation Already-collected aggregation entries.
	 * @param array $newAggregation New aggregation entries to merge.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function mergeFacets(array $existingAggregation, array $newAggregation): array;

	/**
	 * Comparator (usort) that orders search results by descending `_score`.
	 *
	 * @param array $a Left side.
	 * @param array $b Right side.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function sortResultArray(array $a, array $b): int;

	/**
	 * Run the federated search across local and remote endpoints.
	 *
	 * @param array $parameters Request query parameters.
	 * @param array $elasticConfig Elasticsearch configuration block.
	 * @param array $dbConfig Database configuration block.
	 * @param array $catalogi Optional catalog filter array.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function search(array $parameters, array $elasticConfig, array $dbConfig, array $catalogi = []): array;

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
