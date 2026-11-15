<?php

/**
 * Integriq EgressResidencyPolicy.
 *
 * Decides whether EU-only mode refuses a call to a source. A source in the EU
 * is always called. Any other source is refused while the setting
 * `egress_eu_only` is on, and also when the setting cannot be read, so a
 * failing read refuses rather than sends.
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
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Residency;

use OCP\IAppConfig;
use Throwable;

/**
 * The EU-only check a call passes before any request is sent.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
 */
final class EgressResidencyPolicy {

	/**
	 * The app-config key of EU-only mode.
	 *
	 * @var string
	 */
	public const SETTING = 'egress_eu_only';

	/**
	 * The status message prefix of a refused call.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused-residency';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the EU-only setting.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether EU-only mode is on. A read that fails reads as on.
	 *
	 * @return bool True when calls outside the EU are refused.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
	 */
	public function isEuOnly(): bool {
		try {
			return $this->appConfig->getValueBool('integriq', self::SETTING, false);
		} catch (Throwable) {
			return true;
		}
	}//end isEuOnly()

	/**
	 * The refused country of a source, or null when the call may go out.
	 *
	 * @param array<string,mixed> $sourceData The source data.
	 *
	 * @return string|null The source's country, or `unknown`, when refused; null when allowed.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
	 */
	public function refusedCountry(array $sourceData): ?string {
		$country = ResidencyZones::countryOf(residency: $sourceData['residency'] ?? null);
		if (ResidencyZones::zoneOf(country: $country) === ResidencyZones::ZONE_EU) {
			return null;
		}

		if ($this->isEuOnly() === false) {
			return null;
		}

		return $country ?? ResidencyZones::ZONE_UNKNOWN;
	}//end refusedCountry()
}//end class
