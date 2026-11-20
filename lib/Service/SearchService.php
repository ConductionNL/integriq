<?php

/**
 * Integriq SearchService.
 *
 * Query parameter helpers for listing and search requests.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

/**
 * Helper service for query parameter handling.
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
class SearchService implements SearchServiceInterface {

	/**
	 * This function adds a single query param to the given $vars array. ?$name=$value.
	 *
	 * Will check if request query $name has [...] inside the parameter, like this: ?queryParam[$nameKey]=$value.
	 * Works recursive, so in case we have ?queryParam[$nameKey][$anotherNameKey][etc][etc]=$value.
	 * Also checks for queryParams ending on [] like: ?queryParam[$nameKey][] (or just ?queryParam[]), if this is the case
	 * this function will add given value to an array of [queryParam][$nameKey][] = $value or [queryParam][] = $value.
	 * If none of the above this function will just add [queryParam] = $value to $vars.
	 *
	 * @param array $vars The vars array we are going to store the query parameter in.
	 * @param string $name The full $name of the query param, like this: ?$name=$value.
	 * @param string $nameKey The full $name of the query param, unless it contains [] like: ?queryParam[$nameKey]=$value.
	 * @param string $value The full $value of the query param, like this: ?$name=$value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function recursiveRequestQueryKey(array &$vars, string $name, string $nameKey, string $value): void {
		$matches = [];
		$matchesCount = preg_match(pattern: '/(\[[^[\]]*])/', subject: $name, matches:$matches);
		if ($matchesCount <= 0) {
			$vars[$nameKey] = $value;
			return;
		}

		$key = $matches[0];
		$name = str_replace(search: $key, replace:'', subject: $name);
		$key = trim(string: $key, characters: '[]');
		if (empty($key) === true) {
			$vars[$nameKey][] = $value;
			return;
		}

		$vars[$nameKey] = ($vars[$nameKey] ?? []);
		$this->recursiveRequestQueryKey(
			vars: $vars[$nameKey],
			name: $name,
			nameKey: $key,
			value: $value
		);

	}//end recursiveRequestQueryKey()

	/**
	 * This function unsets all keys starting with _ from filters.
	 *
	 * @param array $filters Query parameters from request.
	 *
	 * @return array $filters
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function unsetSpecialQueryParams(array $filters): array {
		foreach (array_keys($filters) as $key) {
			if (str_starts_with((string)$key, '_') === true) {
				unset($filters[$key]);
			}
		}

		return $filters;
	}//end unsetSpecialQueryParams()

	/**
	 * Parses the request query string and returns it as an array of queries.
	 *
	 * @param string $queryString The input query string from the request.
	 *
	 * @return array The resulting array of query parameters.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function parseQueryString(string $queryString = ''): array {
		$pairs = explode(separator: '&', string: $queryString);
		$vars = [];

		foreach ($pairs as $pair) {
			$kvpair = explode(separator: '=', string: $pair);

			$key = urldecode(string: $kvpair[0]);
			$value = '';
			if (count(value: $kvpair) === 2) {
				$value = urldecode(string: $kvpair[1]);
			}

			$this->recursiveRequestQueryKey(
				vars: $vars,
				name: $key,
				nameKey: substr(
					string: $key,
					offset: 0,
					length: strpos(
						haystack: $key,
						needle: '['
					)
				),
				value: $value
			);
		}//end foreach

		return $vars;
	}//end parseQueryString()
}//end class
