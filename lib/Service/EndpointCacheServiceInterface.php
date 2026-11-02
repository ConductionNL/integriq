<?php

/**
 * Contract of the EndpointCacheService.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see EndpointCacheService} that other components call.
 *
 * The method signatures repeat those of EndpointCacheService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface EndpointCacheServiceInterface {
	/**
	 * Find the best matching endpoint for a path and method using cached data with smart fallback.
	 *
	 * @param string $path The path to match against endpoint regex patterns.
	 * @param string $method The HTTP method to filter by (GET, POST, etc).
	 * @param boolean $isRetry Internal flag to prevent infinite recursion.
	 *
	 * @return ObjectEntity|null Returns the best matching endpoint or null if none found.
	 *
	 * @throws \Exception When multiple endpoints match (ambiguous routing).
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function findByPathRegex(string $path, string $method, bool $isRetry = false): ?ObjectEntity;

	/**
	 * Get all endpoints from cache or database as ObjectEntity arrays (for performance).
	 *
	 * @return array Array of ObjectEntity instances (for faster filtering).
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function getAllEndpoints(): array;

	/**
	 * Refresh the endpoint cache from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function refreshCache(): void;

	/**
	 * Clear the endpoint cache.
	 *
	 * This should be called when endpoints are created, updated, or deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function clearCache(): void;

	/**
	 * The routing fields an endpoint path implies: `endpointRegex` and `endpointArray`.
	 *
	 * What the EndpointMapper wrote on every insert and update before the
	 * OpenRegister cutover (7df241bc9). EndpointRoutingListener stores them on
	 * save; findByPathRegex() derives them for a row saved without them.
	 *
	 * @param string $endpoint The endpoint path, e.g. `personen/{{id}}`.
	 *
	 * @return array{endpointRegex: string, endpointArray: list<string>}
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function routingFor(string $endpoint): array;

	/**
	 * Get cache statistics for monitoring.
	 *
	 * @return array Cache statistics.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function getCacheStats(): array;
}
