<?php

/**
 * Integriq ResidencyZones.
 *
 * The zone a country sits in for data residency: the 27 EU member states, the
 * three EEA states outside the EU, everything else outside, and no country
 * unknown. A constant list, never a guess: no geolocation is involved.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Residency
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Residency;

/**
 * Country codes and the residency zone of each.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
final class ResidencyZones {

	/**
	 * Zone of a country inside the EU.
	 *
	 * @var string
	 */
	public const ZONE_EU = 'eu';

	/**
	 * Zone of an EEA country outside the EU.
	 *
	 * @var string
	 */
	public const ZONE_EEA = 'eea';

	/**
	 * Zone of any other valid country.
	 *
	 * @var string
	 */
	public const ZONE_OUTSIDE = 'outside';

	/**
	 * Zone of a destination without a (valid) country.
	 *
	 * @var string
	 */
	public const ZONE_UNKNOWN = 'unknown';

	/**
	 * Every zone, in the order a page lists the counts.
	 *
	 * @var array<int,string>
	 */
	public const ZONES = [self::ZONE_EU, self::ZONE_EEA, self::ZONE_OUTSIDE, self::ZONE_UNKNOWN];

	/**
	 * The 27 EU member states.
	 *
	 * @var array<int,string>
	 */
	public const EU = [
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
		'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
	];

	/**
	 * The EEA states that are not in the EU.
	 *
	 * @var array<int,string>
	 */
	public const EEA_OUTSIDE_EU = ['IS', 'LI', 'NO'];

	/**
	 * Every officially assigned ISO 3166-1 alpha-2 code.
	 *
	 * @var array<int,string>
	 */
	public const ISO_3166_1 = [
		'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
		'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
		'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
		'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
		'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
		'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
		'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
		'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
		'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
		'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
		'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
		'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
		'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
		'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
		'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
		'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
	];

	/**
	 * Whether a value is an assigned ISO 3166-1 alpha-2 code, upper case.
	 *
	 * @param mixed $country The value.
	 *
	 * @return bool True for a valid code.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
	 */
	public static function isCountry(mixed $country): bool {
		return is_string($country) === true && in_array($country, self::ISO_3166_1, true) === true;
	}//end isCountry()

	/**
	 * The zone of a country; an absent or invalid code is unknown.
	 *
	 * @param string|null $country The ISO 3166-1 alpha-2 code.
	 *
	 * @return string One of the ZONE_ constants.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
	 */
	public static function zoneOf(?string $country): string {
		if (self::isCountry(country: $country) === false) {
			return self::ZONE_UNKNOWN;
		}

		if (in_array($country, self::EU, true) === true) {
			return self::ZONE_EU;
		}

		if (in_array($country, self::EEA_OUTSIDE_EU, true) === true) {
			return self::ZONE_EEA;
		}

		return self::ZONE_OUTSIDE;
	}//end zoneOf()

	/**
	 * The country of a stored residency, or null when it has none.
	 *
	 * @param mixed $residency The stored residency object.
	 *
	 * @return string|null The code, null when absent or invalid.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
	 */
	public static function countryOf(mixed $residency): ?string {
		if (is_array($residency) === false || self::isCountry(country: $residency['country'] ?? null) === false) {
			return null;
		}

		return $residency['country'];
	}//end countryOf()
}//end class
