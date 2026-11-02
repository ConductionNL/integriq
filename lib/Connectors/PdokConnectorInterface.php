<?php

/**
 * Contract of the PdokConnector.
 *
 * @category Contract
 * @package  OCA\Integriq\Connectors
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

namespace OCA\Integriq\Connectors;

/**
 * Public operations of {@see PdokConnector} that other components call.
 */
interface PdokConnectorInterface {
	/**
	 * Suggest addresses for an autocomplete query.
	 *
	 * @param string $query Search query (must be non-empty).
	 *
	 * @return array Normalised suggestion documents.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::suggest()
	 */
	public function suggest(string $query): array;

	/**
	 * Look up a single PDOK document by id.
	 *
	 * @param string $id PDOK identifier (e.g., `adr-0363200000218908`).
	 *
	 * @return array Normalised lookup document.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::lookup()
	 */
	public function lookup(string $id): array;

	/**
	 * Free-text search.
	 *
	 * @param string $query Search query.
	 * @param int $rows Page size (default 10).
	 * @param int $start Page offset (default 0).
	 *
	 * @return array Normalised free-search documents.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::free()
	 */
	public function free(string $query, int $rows = 10, int $start = 0): array;

	/**
	 * Reverse-geocode coordinates.
	 *
	 * @param float $lat Latitude (WGS84).
	 * @param float $lng Longitude (WGS84).
	 *
	 * @return array Normalised reverse-geocode document.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::reverse()
	 */
	public function reverse(float $lat, float $lng): array;

	/**
	 * Map a single PDOK document to the canonical PostalAddress shape.
	 *
	 * Missing fields map to `null` (never absent). `centroide_ll` WKT
	 * `"POINT(lng lat)"` becomes a GeoJSON Point `[lng, lat]` per RFC 7946.
	 *
	 * @param array $pdokDoc Raw PDOK document.
	 *
	 * @return array Canonical PostalAddress object.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::normalize()
	 */
	public function normalize(array $pdokDoc): array;

	/**
	 * Persist a normalised PDOK document into OR's `addresses` register.
	 *
	 * @param array $doc Normalised PostalAddress.
	 *
	 * @return void
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PdokConnector::writeThrough()
	 */
	public function writeThrough(array $doc): void;
}
