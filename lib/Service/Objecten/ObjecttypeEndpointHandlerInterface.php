<?php

/**
 * Contract of the ObjecttypeEndpointHandler.
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
 * Public operations of {@see ObjecttypeEndpointHandler} that other components call.
 */
interface ObjecttypeEndpointHandlerInterface {
	/**
	 * `GET /api/v2/objecttypes`.
	 *
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function index(string $baseUrl = ''): array;

	/**
	 * `GET /api/v2/objecttypes/{uuid}`.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function show(string $uuid, string $baseUrl = ''): array;

	/**
	 * `GET /api/v2/objecttypes/{uuid}/versions/{version}`.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $version The version.
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function version(string $uuid, string $version, string $baseUrl = ''): array;
}
