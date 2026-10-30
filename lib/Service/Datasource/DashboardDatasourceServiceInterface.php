<?php

/**
 * Contract of the DashboardDatasourceService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Datasource
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

namespace OCA\Integriq\Service\Datasource;

/**
 * Public operations of {@see DashboardDatasourceService} that other components call.
 */
interface DashboardDatasourceServiceInterface {
	/**
	 * Resolve a single value from a named source.
	 *
	 * Read-only: only ever dispatches a GET/read call against the source
	 * (`CallService::call(..., method: 'GET', read: true)`); never mutates
	 * the source, its synchronizations, or any other object. The caller's
	 * `$params` are used exclusively as query parameters on the source's OWN
	 * configured location — any `url`/`host` entry is stripped before use,
	 * so a caller can never redirect egress away from the stored source
	 * (REQ "Egress is constrained to the source, never the caller").
	 *
	 * @param string $sourceId UUID of the `source` object to resolve against.
	 * @param string $valueExpr JSONPath-lite expression (`$.a.b.c`, `$.a[0].b`) evaluated against the response body.
	 * @param array $params Optional query parameters forwarded to the source call. `url`/`host` keys are ignored.
	 * @param int|null $ttl Optional requested cache TTL (seconds); clamped to the source-configured maximum.
	 *
	 * @return array{value: mixed, fetchedAt: string, stale: bool} The resolved value and cache-freshness metadata.
	 *
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the current user may not read the source.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the source does not exist.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-egress-is-constrained-to-the-source-never-the-caller
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 * @spec openspec/specs/dashboard-http-datasource/spec.md#requirement-caller-authorization-honours-the-source-s-read-access
	 */
	public function resolve(string $sourceId, string $valueExpr, array $params = [], ?int $ttl = null): array;
}
