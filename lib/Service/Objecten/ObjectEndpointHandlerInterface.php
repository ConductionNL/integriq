<?php

/**
 * Contract of the ObjectEndpointHandler.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Objecten
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

namespace OCA\Integriq\Service\Objecten;

/**
 * Public operations of {@see ObjectEndpointHandler} that other components call.
 */
interface ObjectEndpointHandlerInterface {
	/**
	 * `GET /api/v2/objects`.
	 *
	 * @param array<string, mixed> $query     The query parameters.
	 * @param string               $baseUrl   The API base.
	 * @param string               $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function index(array $query, string $baseUrl = '', string $principal = ''): array;

	/**
	 * `GET /api/v2/objects/{uuid}`.
	 *
	 * @param string $type      The objecttype the token was checked against.
	 * @param string $uuid      The object.
	 * @param string $baseUrl   The API base.
	 * @param string $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function show(string $type, string $uuid, string $baseUrl = '', string $principal = ''): array;

	/**
	 * `POST /api/v2/objects/search` — the geometry search.
	 *
	 * @param string               $type      The objecttype.
	 * @param array<string, mixed> $body      The search body.
	 * @param string               $baseUrl   The API base.
	 * @param string               $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function search(string $type, array $body, string $baseUrl = '', string $principal = ''): array;

	/**
	 * Great-circle distance in metres between two lon/lat points.
	 *
	 * The haversine formula. Exact enough for a municipal radius search — its
	 * error against the ellipsoid is well under a metre at these distances —
	 * and it needs no spatial extension, which is the point.
	 *
	 * @param array{0: float, 1: float} $a One point, lon then lat.
	 * @param array{0: float, 1: float} $b The other.
	 *
	 * @return float The distance in metres.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function metresBetween(array $a, array $b): float;
}
