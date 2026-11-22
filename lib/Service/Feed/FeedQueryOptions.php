<?php

/**
 * Integriq FeedQueryOptions: reads the query options of a feed request.
 *
 * `$top` (capped at the declared page size) and `$skip`, `$select` over
 * declared fields and `$filter` with `eq` on declared fields. Any other query
 * option, the feed's scope parameter aside, is refused by name.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Feed
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
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Feed;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The OData query options a declared feed accepts, read and checked.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
 */
class FeedQueryOptions {

	/**
	 * The query options a feed understands.
	 *
	 * @var array<int, string>
	 */
	public const OPTIONS = ['$top', '$skip', '$select', '$filter'];

	/**
	 * One `field eq value` comparison of `$filter`.
	 *
	 * @var string
	 */
	private const COMPARISON = "/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+eq\s+(?:'((?:[^']|'')*)'|(-?\d+(?:\.\d+)?)|(true|false))\s*$/";

	/**
	 * The parsed query options, or the 400 for the first one not supported.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array{top: int, skip: int, select: array<int, string>, filters: array<string, mixed>}|JSONResponse
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
	 */
	public function parse(array $feed, array $query): array|JSONResponse {
		$unsupported = $this->unsupportedOption(feed: $feed, query: $query);
		if ($unsupported !== null) {
			return $unsupported;
		}

		$pageSize = max(1, (int)($feed['pageSize'] ?? 1));
		$top = $this->wholeNumber(value: ($query['$top'] ?? $pageSize), minimum: 1);
		$skip = $this->wholeNumber(value: ($query['$skip'] ?? 0), minimum: 0);
		if ($top === null || $skip === null) {
			return $this->badRequest(message: '$top and $skip are whole numbers; $top is at least 1.');
		}

		$select = $this->select(feed: $feed, value: ($query['$select'] ?? null));
		if ($select instanceof JSONResponse) {
			return $select;
		}

		$filters = $this->filter(feed: $feed, value: ($query['$filter'] ?? null));
		if ($filters instanceof JSONResponse) {
			return $filters;
		}

		return ['top' => min($top, $pageSize), 'skip' => $skip, 'select' => $select, 'filters' => $filters];
	}//end parse()

	/**
	 * The refusal of the first query option the feed does not support, or null.
	 *
	 * @param array                $feed  The feed declaration.
	 * @param array<string, mixed> $query The query.
	 *
	 * @return JSONResponse|null
	 */
	private function unsupportedOption(array $feed, array $query): ?JSONResponse {
		$supported = array_merge(self::OPTIONS, [(string)($feed['scope']['parameter'] ?? '')]);
		foreach (array_keys($query) as $name) {
			if (in_array((string)$name, $supported, true) === false) {
				return $this->badRequest(message: sprintf('%s is not supported by this feed.', (string)$name));
			}
		}

		return null;
	}//end unsupportedOption()

	/**
	 * The fields `$select` names, all declared ones when it is absent.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param mixed                $value The `$select` value.
	 *
	 * @return array<int, string>|JSONResponse The fields, or 400 naming an undeclared one.
	 */
	private function select(array $feed, mixed $value): array|JSONResponse {
		$declared = array_values((array)($feed['fields'] ?? []));
		if ($value === null || trim((string)$value) === '') {
			return $declared;
		}

		$fields = array_values(array_unique(array_map('trim', explode(',', (string)$value))));
		foreach ($fields as $field) {
			if (in_array($field, $declared, true) === false) {
				return $this->badRequest(message: sprintf('$select names %s, which this feed does not declare.', $field));
			}
		}

		return $fields;
	}//end select()

	/**
	 * The equality filters `$filter` asks for.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param mixed                $value The `$filter` value.
	 *
	 * @return array<string, mixed>|JSONResponse The filters, or 400 naming what is not supported.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
	 */
	public function filter(array $feed, mixed $value): array|JSONResponse {
		if ($value === null || trim((string)$value) === '') {
			return [];
		}

		$declared = array_merge((array)($feed['fields'] ?? []), [(string)($feed['scope']['field'] ?? '')]);
		$filters = [];
		foreach (preg_split('/\s+and\s+/i', trim((string)$value)) as $comparison) {
			$match = $this->comparison(comparison: $comparison, declared: $declared);
			if ($match instanceof JSONResponse) {
				return $match;
			}

			$filters[$match[1]] = $this->literal(match: $match);
		}

		return $filters;
	}//end filter()

	/**
	 * One `field eq value` comparison of a $filter, on a declared field.
	 *
	 * @param string             $comparison The comparison.
	 * @param array<int, string> $declared   The fields the filter may name.
	 *
	 * @return array<int, string>|JSONResponse The regular expression match, or the refusal.
	 */
	private function comparison(string $comparison, array $declared): array|JSONResponse {
		if (preg_match(self::COMPARISON, $comparison, $match) !== 1) {
			return $this->badRequest(message: sprintf('$filter supports only "field eq value" joined by "and"; "%s" is not supported.', trim($comparison)));
		}

		if (in_array($match[1], $declared, true) === false) {
			return $this->badRequest(message: sprintf('$filter names %s, which this feed does not declare.', $match[1]));
		}

		return $match;
	}//end comparison()

	/**
	 * The value of one `$filter` comparison.
	 *
	 * @param array<int, string> $match The comparison's match.
	 *
	 * @return string|int|float|bool The value.
	 */
	private function literal(array $match): string|int|float|bool {
		if (($match[4] ?? '') !== '') {
			return $match[4] === 'true';
		}

		if (($match[3] ?? '') !== '' && str_contains($match[3], '.') === true) {
			return (float)$match[3];
		}

		if (($match[3] ?? '') !== '') {
			return (int)$match[3];
		}

		return str_replace("''", "'", $match[2]);
	}//end literal()

	/**
	 * A whole number from a query value, or null when it is not one.
	 *
	 * @param mixed   $value   The value.
	 * @param integer $minimum The smallest allowed.
	 *
	 * @return integer|null The number.
	 */
	private function wholeNumber(mixed $value, int $minimum): ?int {
		if (is_int($value) === false && (is_string($value) === false || ctype_digit($value) === false)) {
			return null;
		}

		$number = (int)$value;
		if ($number < $minimum) {
			return null;
		}

		return $number;
	}//end wholeNumber()

	/**
	 * A 400 that names what is not supported.
	 *
	 * @param string $message The message.
	 *
	 * @return JSONResponse The answer.
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(['error' => 'not_supported', 'message' => $message], Http::STATUS_BAD_REQUEST);
	}//end badRequest()
}//end class
