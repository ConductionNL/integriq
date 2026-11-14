<?php

/**
 * Unit tests: the residency zone of a country.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Residency
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Residency;

use OCA\Integriq\Service\Residency\ResidencyZones;
use PHPUnit\Framework\TestCase;

/**
 * Zones, codes and the country of a stored residency.
 */
class ResidencyZonesTest extends TestCase {

	/**
	 * Norway is EEA, the Netherlands EU, the US outside, no country unknown.
	 *
	 * @return void
	 */
	public function testZones(): void {
		$this->assertSame(expected: 'eea', actual: ResidencyZones::zoneOf(country: 'NO'));
		$this->assertSame(expected: 'eu', actual: ResidencyZones::zoneOf(country: 'NL'));
		$this->assertSame(expected: 'outside', actual: ResidencyZones::zoneOf(country: 'US'));
		$this->assertSame(expected: 'unknown', actual: ResidencyZones::zoneOf(country: null));
		$this->assertSame(expected: 'unknown', actual: ResidencyZones::zoneOf(country: 'XX'));
		$this->assertSame(expected: 'outside', actual: ResidencyZones::zoneOf(country: 'GB'));
		$this->assertSame(expected: 'outside', actual: ResidencyZones::zoneOf(country: 'CH'));
	}//end testZones()

	/**
	 * Every EU and EEA state is an assigned code, and the lists do not overlap.
	 *
	 * @return void
	 */
	public function testTheZoneListsAreAssignedAndDisjoint(): void {
		$this->assertCount(expectedCount: 27, haystack: ResidencyZones::EU);
		$this->assertCount(expectedCount: 249, haystack: array_unique(ResidencyZones::ISO_3166_1));
		$this->assertSame(expected: [], actual: array_diff(ResidencyZones::EU, ResidencyZones::ISO_3166_1));
		$this->assertSame(expected: [], actual: array_diff(ResidencyZones::EEA_OUTSIDE_EU, ResidencyZones::ISO_3166_1));
		$this->assertSame(expected: [], actual: array_intersect(ResidencyZones::EU, ResidencyZones::EEA_OUTSIDE_EU));
	}//end testTheZoneListsAreAssignedAndDisjoint()

	/**
	 * Only an upper-case assigned code is a country.
	 *
	 * @return void
	 */
	public function testIsCountry(): void {
		$this->assertTrue(ResidencyZones::isCountry(country: 'DE'));
		$this->assertFalse(ResidencyZones::isCountry(country: 'de'));
		$this->assertFalse(ResidencyZones::isCountry(country: 'EU'));
		$this->assertFalse(ResidencyZones::isCountry(country: 31));
		$this->assertFalse(ResidencyZones::isCountry(country: null));
	}//end testIsCountry()

	/**
	 * The country of a stored residency, or null when it has none that is valid.
	 *
	 * @return void
	 */
	public function testCountryOf(): void {
		$this->assertSame(expected: 'FR', actual: ResidencyZones::countryOf(residency: ['country' => 'FR', 'basis' => 'seeded']));
		$this->assertNull(ResidencyZones::countryOf(residency: ['country' => 'fr']));
		$this->assertNull(ResidencyZones::countryOf(residency: ['basis' => 'seeded']));
		$this->assertNull(ResidencyZones::countryOf(residency: 'FR'));
		$this->assertNull(ResidencyZones::countryOf(residency: null));
	}//end testCountryOf()
}//end class
