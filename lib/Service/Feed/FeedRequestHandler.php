<?php

/**
 * Integriq FeedRequestHandler: answers a declared data feed.
 *
 * A feed answers GET only, with the declared fields only, page by page:
 * `$top` (capped at the declared page size) and `$skip`, `$select` over
 * declared fields and `$filter` with `eq` on declared fields. Any other query
 * option is refused by name. Every request is narrowed to the scope values
 * bound to the calling consumer.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Serves one feed request; the endpoint pipeline authenticates the consumer first.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
 */
class FeedRequestHandler {

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
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the declaring app's objects.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The scope values a consumer is bound to for a feed's app.
	 *
	 * @param array<string, mixed> $feed     The feed declaration stored on the endpoint.
	 * @param ObjectEntity         $consumer The authenticated consumer.
	 *
	 * @return array<int, string> The values; empty when the consumer has no binding.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003
	 */
	public function boundScope(array $feed, ObjectEntity $consumer): array {
		$scopes = ($consumer->getObject()['feedScopes'] ?? []);
		$bound = [];
		if (is_array($scopes) === true) {
			$bound = ($scopes[(string)($feed['app'] ?? '')] ?? []);
		}

		if (is_scalar($bound) === true) {
			$bound = [$bound];
		}

		if (is_array($bound) === false) {
			return [];
		}

		$values = [];
		foreach ($bound as $value) {
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				$values[] = trim((string)$value);
			}
		}

		return array_values(array_unique($values));
	}//end boundScope()

	/**
	 * The refusal for a consumer outside the feed's scope, or null to go on.
	 *
	 * A consumer with no binding for the declaring app is refused on every
	 * feed, and a request for a scope value not bound to the consumer is
	 * refused, whether it is asked as the scope parameter or in `$filter`.
	 *
	 * @param array<string, mixed>  $feed     The feed declaration.
	 * @param ObjectEntity|null     $consumer The authenticated consumer, null when none.
	 * @param array<string, mixed>  $query    The query parameters.
	 *
	 * @return JSONResponse|null 401 without a consumer, 403 outside the scope, else null.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003
	 */
	public function scopeRefusal(array $feed, ?ObjectEntity $consumer, array $query): ?JSONResponse {
		if ($consumer === null) {
			return new JSONResponse(
				['error' => 'unauthenticated', 'message' => 'A feed is read with a consumer key.'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$bound = $this->boundScope(feed: $feed, consumer: $consumer);
		if ($bound === []) {
			return $this->forbidden(message: 'This consumer is not bound to a scope of this feed.');
		}

		foreach ($this->requestedScopes(feed: $feed, query: $query) as $requested) {
			if (in_array($requested, $bound, true) === false) {
				return $this->forbidden(message: 'This consumer may not read the requested scope.');
			}
		}

		return null;
	}//end scopeRefusal()

	/**
	 * One page of a feed.
	 *
	 * @param array<string, mixed> $feed    The feed declaration.
	 * @param array<int, string>   $bound   The scope values bound to the consumer.
	 * @param array<string, mixed> $query   The query parameters.
	 * @param string               $baseUrl The feed's absolute URL, without a query, for the next link.
	 *
	 * @return JSONResponse `{"value": [...], "@odata.nextLink": "..."}`, or 400 naming what is not supported.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
	 */
	public function answer(array $feed, array $bound, array $query, string $baseUrl): JSONResponse {
		$options = $this->options(feed: $feed, query: $query);
		if ($options instanceof JSONResponse) {
			return $options;
		}

		$filters = array_merge(
			$options['filters'],
			$this->scopeFilter(feed: $feed, bound: $bound, query: $query),
			$this->fixedFilter(feed: $feed),
			['register' => (string)$feed['register'], 'schema' => (string)$feed['schema']]
		);

		$result = $this->objectService->findAll(
			config: ['filters' => $filters, 'limit' => ($options['top'] + 1), 'offset' => $options['skip']],
			_rbac: false,
			_multitenancy: false
		);
		$rows = array_values(($result['results'] ?? $result));

		$body = ['value' => []];
		foreach (array_slice($rows, 0, $options['top']) as $row) {
			$body['value'][] = $this->project(row: $row, fields: $options['select']);
		}

		if (count($rows) > $options['top']) {
			$next = $query;
			$next['$skip'] = (string)($options['skip'] + $options['top']);
			$next['$top'] = (string)$options['top'];
			$body['@odata.nextLink'] = $baseUrl . '?' . http_build_query($next, '', '&', PHP_QUERY_RFC3986);
		}

		return new JSONResponse($body);
	}//end answer()

	/**
	 * The parsed query options, or the 400 for the first one not supported.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array{top: int, skip: int, select: array<int, string>, filters: array<string, mixed>}|JSONResponse
	 */
	private function options(array $feed, array $query): array|JSONResponse {
		$scopeParameter = (string)($feed['scope']['parameter'] ?? '');
		foreach (array_keys($query) as $name) {
			$name = (string)$name;
			if (in_array($name, self::OPTIONS, true) === false && $name !== $scopeParameter) {
				return $this->badRequest(message: sprintf('%s is not supported by this feed.', $name));
			}
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
	}//end options()

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
	 */
	private function filter(array $feed, mixed $value): array|JSONResponse {
		if ($value === null || trim((string)$value) === '') {
			return [];
		}

		$declared = array_merge((array)($feed['fields'] ?? []), [(string)($feed['scope']['field'] ?? '')]);
		$filters = [];
		foreach (preg_split('/\s+and\s+/i', trim((string)$value)) as $comparison) {
			if (preg_match(self::COMPARISON, $comparison, $match) !== 1) {
				return $this->badRequest(message: sprintf('$filter supports only "field eq value" joined by "and"; "%s" is not supported.', trim($comparison)));
			}

			if (in_array($match[1], $declared, true) === false) {
				return $this->badRequest(message: sprintf('$filter names %s, which this feed does not declare.', $match[1]));
			}

			$filters[$match[1]] = $this->literal(match: $match);
		}

		return $filters;
	}//end filter()

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
	 * The scope values a request names, as the scope parameter or in `$filter`.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array<int, string> The values.
	 */
	private function requestedScopes(array $feed, array $query): array {
		$requested = [];
		$parameter = (string)($feed['scope']['parameter'] ?? '');
		if ($parameter !== '' && isset($query[$parameter]) === true && is_scalar($query[$parameter]) === true) {
			$requested[] = trim((string)$query[$parameter]);
		}

		$filters = $this->filter(feed: $feed, value: ($query['$filter'] ?? null));
		$field = (string)($feed['scope']['field'] ?? '');
		if (is_array($filters) === true && array_key_exists($field, $filters) === true) {
			$requested[] = (string)$filters[$field];
		}

		return $requested;
	}//end requestedScopes()

	/**
	 * The filter on the scope field: the requested value, else every bound one.
	 *
	 * @param array<string, mixed> $feed  The feed declaration.
	 * @param array<int, string>   $bound The bound values.
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array<string, mixed> The filter.
	 */
	private function scopeFilter(array $feed, array $bound, array $query): array {
		$field = (string)$feed['scope']['field'];
		$requested = $this->requestedScopes(feed: $feed, query: $query);
		if ($requested !== []) {
			return [$field => $requested[0]];
		}

		if (count($bound) === 1) {
			return [$field => $bound[0]];
		}

		return [$field => $bound];
	}//end scopeFilter()

	/**
	 * The declaration's fixed filter.
	 *
	 * @param array<string, mixed> $feed The feed declaration.
	 *
	 * @return array<string, mixed> The filter.
	 */
	private function fixedFilter(array $feed): array {
		$filter = ($feed['filter'] ?? []);
		if (is_object($filter) === true) {
			$filter = get_object_vars($filter);
		}

		if (is_array($filter) === false) {
			return [];
		}

		return $filter;
	}//end fixedFilter()

	/**
	 * One record with only the selected fields; a field it lacks reads null.
	 *
	 * @param mixed              $row    The object, as findAll() answers it.
	 * @param array<int, string> $fields The fields.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function project(mixed $row, array $fields): array {
		$data = [];
		if ($row instanceof ObjectEntity) {
			$data = $row->getObject();
		} else if (is_array($row) === true) {
			$data = $row;
		}

		$record = [];
		foreach ($fields as $field) {
			$record[$field] = ($data[$field] ?? null);
		}

		return $record;
	}//end project()

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

	/**
	 * A 403 for a request outside the consumer's scope.
	 *
	 * @param string $message The message.
	 *
	 * @return JSONResponse The answer.
	 */
	private function forbidden(string $message): JSONResponse {
		return new JSONResponse(['error' => 'scope_not_allowed', 'message' => $message], Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end class
