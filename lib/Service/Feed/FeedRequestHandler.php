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
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the declaring app's objects.
	 * @param FeedQueryOptions $queryOptions Reads the OData query options of a request.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly FeedQueryOptions $queryOptions = new FeedQueryOptions(),
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
		$bound = $this->boundValues(feed: $feed, consumer: $consumer);
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
	 * What the consumer's `feedScopes` holds for the feed's app, as stored.
	 *
	 * @param array        $feed     The feed declaration.
	 * @param ObjectEntity $consumer The consumer.
	 *
	 * @return mixed The stored value; an empty list when there is none.
	 */
	private function boundValues(array $feed, ObjectEntity $consumer): mixed {
		$scopes = ($consumer->getObject()['feedScopes'] ?? []);
		if (is_array($scopes) === false) {
			return [];
		}

		return ($scopes[(string)($feed['app'] ?? '')] ?? []);
	}//end boundValues()

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
		$options = $this->queryOptions->parse(feed: $feed, query: $query);
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

		$filters = $this->queryOptions->filter(feed: $feed, value: ($query['$filter'] ?? null));
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
