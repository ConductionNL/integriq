<?php

/**
 * Integriq Datasource Query.
 *
 * One dashboard value asked of a source: the source id, the JSONPath-lite
 * expression, the query parameters forwarded to the source and the requested
 * TTL, plus the cache keys that question is stored under.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Datasource;

/**
 * A dashboard value asked of a source, and its cache keys.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 */
final class DatasourceQuery {

	/**
	 * The deterministic, control-character-safe cache key of this query.
	 *
	 * @var string
	 */
	private readonly string $cacheKey;

	/**
	 * Constructor.
	 *
	 * @param string       $sourceId  UUID of the source the value is resolved against.
	 * @param string       $valueExpr JSONPath-lite expression evaluated against the response body.
	 * @param array        $params    Query parameters forwarded to the source call (url/host already removed).
	 * @param integer|null $ttl       The requested cache TTL in seconds, if any.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 */
	public function __construct(
		public readonly string $sourceId,
		public readonly string $valueExpr,
		public readonly array $params,
		public readonly ?int $ttl,
	) {
		ksort($params);
		$this->cacheKey = 'oc_ddc_' . hash('sha256', $sourceId . '|' . $valueExpr . '|' . json_encode($params));
	}//end __construct()

	/**
	 * The cache key of the entry that is fresh within the TTL.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 */
	public function freshKey(): string {
		return 'fresh_' . $this->cacheKey;
	}//end freshKey()

	/**
	 * The cache key of the long-lived last-known-good entry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 */
	public function staleKey(): string {
		return 'stale_' . $this->cacheKey;
	}//end staleKey()

	/**
	 * The CallService request config: the parameters as query, or none.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-egress-is-constrained-to-the-source-never-the-caller
	 */
	public function requestConfig(): array {
		if (empty($this->params) === true) {
			return [];
		}

		return ['query' => $this->params];
	}//end requestConfig()
}//end class
