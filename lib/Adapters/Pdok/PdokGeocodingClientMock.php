<?php

/**
 * OpenConnector PDOK Geocoding Client (mock).
 *
 * Deterministic, no-network implementation of {@see PdokGeocodingClient}.
 * Ships dormant — DI returns this class until `pdok.feature_flag` is set
 * to `1`. The canned responses match the normalized PostalAddress shape
 * documented in `hydra/openspec/changes/shared-pdok-via-openconnector/
 * design.md` so downstream code can exercise its branches off real-looking
 * data.
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.openconnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Pdok;

/**
 * Mock PDOK geocoding client — dormant default.
 */
final class PdokGeocodingClientMock extends PdokGeocodingClient {
	/**
	 * Canonical canned PostalAddress (Conduction HQ, Lauriergracht 37,
	 * 1016 RG Amsterdam) — matches the seed fixture in
	 * `tests/fixtures/pdok/fixture-lauriergracht.json` so downstream
	 * tests can rely on it.
	 *
	 * @return array<string,mixed>
	 */
	private function cannedLauriergracht(): array {
		return [
			'displayName' => 'Lauriergracht 37, 1016 RG Amsterdam',
			'streetAddress' => 'Lauriergracht',
			'houseNumber' => '37',
			'postalCode' => '1016 RG',
			'addressLocality' => 'Amsterdam',
			'addressRegion' => 'Noord-Holland',
			'location' => [
				'type' => 'Point',
				'coordinates' => [4.88525, 52.37025],
			],
			'bagAddressId' => '0363200000406543',
			'bagBuildingId' => '0363100012180043',
			'pdokId' => 'adr-mock-lauriergracht-37',
			'source' => 'pdok',
		];
	}//end cannedLauriergracht()

	/**
	 * Dormant suggest — returns one canned entry regardless of query.
	 *
	 * @param string $query Free-text query.
	 * @param int $rows Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function suggest(string $query, int $rows = 10): array {
		unset($query, $rows);
		return [$this->cannedLauriergracht()];
	}//end suggest()

	/**
	 * Dormant lookup — returns the canned entry for any id.
	 *
	 * @param string $pdokId PDOK id.
	 *
	 * Narrower than the abstract parent's `?array` on purpose: the mock always
	 * has a canned entry to hand back, so it never returns null. Return types
	 * are covariant in PHP, so narrowing here is legal and tells callers of the
	 * mock something true.
	 *
	 * @return array<string,mixed>
	 */
	public function lookup(string $pdokId): array {
		unset($pdokId);
		return $this->cannedLauriergracht();
	}//end lookup()

	/**
	 * Dormant reverse — returns one candidate regardless of input.
	 *
	 * @param float $latitude Latitude.
	 * @param float $longitude Longitude.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function reverse(float $latitude, float $longitude): array {
		unset($latitude, $longitude);
		return [$this->cannedLauriergracht()];
	}//end reverse()

	/**
	 * Flavour identifier.
	 *
	 * @return string
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()
}//end class
