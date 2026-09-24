<?php

/**
 * Integriq TrustLevelMapper.
 *
 * Maps a government provider's assurance level onto the fleet trust
 * vocabulary. Fail-closed: a level the table does not know ends the login.
 * Mapping an unknown level onto `low` would let an unrecognised assertion
 * authenticate somebody, which is the one outcome a broker must never
 * produce.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * Turns an assurance level into a trust level, or refuses.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
 */
class TrustLevelMapper {

	/**
	 * The lowest trust level.
	 *
	 * @var string
	 */
	public const TRUST_LOW = 'low';

	/**
	 * The middle trust level.
	 *
	 * @var string
	 */
	public const TRUST_SUBSTANTIAL = 'substantial';

	/**
	 * The highest trust level.
	 *
	 * @var string
	 */
	public const TRUST_HIGH = 'high';

	/**
	 * The DigiD provider id.
	 *
	 * @var string
	 */
	public const PROVIDER_DIGID = 'digid';

	/**
	 * The eHerkenning provider id.
	 *
	 * @var string
	 */
	public const PROVIDER_EHERKENNING = 'eherkenning';

	/**
	 * The eIDAS provider id.
	 *
	 * @var string
	 */
	public const PROVIDER_EIDAS = 'eidas';

	/**
	 * The mapping table, keyed by provider and then by the level's canonical
	 * lowercase name.
	 *
	 * The eHerkenning and eIDAS URNs are in here because both are published,
	 * stable and unambiguous: `urn:etoegang:core:assurance-class:loaN` and
	 * `http://eidas.europa.eu/LoA/<level>`.
	 *
	 * 🔴 THE DigiD URNs ARE DELIBERATELY NOT IN HERE. DigiD's
	 * AuthnContextClassRef spellings could not be verified against Logius
	 * while this was written, and a guessed URN does not fail loudly: it
	 * falls through to "unknown", which this class refuses, or worse matches
	 * the wrong row and maps Hoog onto low. An operator supplies them once,
	 * from the tenant's own metadata, through `$aliases`.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const TABLE = [
		self::PROVIDER_DIGID => [
			'basis' => self::TRUST_LOW,
			'midden' => self::TRUST_LOW,
			'substantieel' => self::TRUST_SUBSTANTIAL,
			'hoog' => self::TRUST_HIGH,
		],
		self::PROVIDER_EHERKENNING => [
			'eh2' => self::TRUST_LOW,
			'eh2+' => self::TRUST_LOW,
			'eh3' => self::TRUST_SUBSTANTIAL,
			'eh4' => self::TRUST_HIGH,
			'urn:etoegang:core:assurance-class:loa2' => self::TRUST_LOW,
			'urn:etoegang:core:assurance-class:loa2plus' => self::TRUST_LOW,
			'urn:etoegang:core:assurance-class:loa3' => self::TRUST_SUBSTANTIAL,
			'urn:etoegang:core:assurance-class:loa4' => self::TRUST_HIGH,
		],
		self::PROVIDER_EIDAS => [
			'low' => self::TRUST_LOW,
			'substantial' => self::TRUST_SUBSTANTIAL,
			'high' => self::TRUST_HIGH,
			'http://eidas.europa.eu/loa/low' => self::TRUST_LOW,
			'http://eidas.europa.eu/loa/substantial' => self::TRUST_SUBSTANTIAL,
			'http://eidas.europa.eu/loa/high' => self::TRUST_HIGH,
		],
	];

	/**
	 * Map one provider's assurance level onto a trust level.
	 *
	 * @param string $provider `digid`, `eherkenning` or `eidas`.
	 * @param string $level The assurance level the assertion carried.
	 * @param array<string,string> $aliases Tenant-configured aliases, `<raw level> => <canonical level>`.
	 *
	 * @return string One of `low`, `substantial`, `high`.
	 *
	 * @throws IdpAssertionException When the provider or the level is not in the table.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
	 */
	public function map(string $provider, string $level, array $aliases = []): string {
		$providerKey = strtolower(trim($provider));
		if (isset(self::TABLE[$providerKey]) === false) {
			throw new IdpAssertionException(
				message: 'No trust mapping exists for provider "' . $providerKey . '", so no envelope is issued.'
			);
		}

		$levelKey = strtolower(trim($level));
		$levelKey = strtolower(trim((string)($aliases[$level] ?? $aliases[$levelKey] ?? $levelKey)));

		$trust = (self::TABLE[$providerKey][$levelKey] ?? null);
		if ($trust === null) {
			// Fail-closed. The message names the provider and the level so an
			// operator can add the alias, and carries nothing else off the
			// assertion.
			throw new IdpAssertionException(
				message: 'Assurance level "' . $levelKey . '" is not mapped for provider "'
				. $providerKey . '", so no envelope is issued.'
			);
		}

		return $trust;

	}//end map()

	/**
	 * Whether one trust level is at least another.
	 *
	 * @param string $have The level held.
	 * @param string $need The level required.
	 *
	 * @return boolean True when `$have` is at least `$need`.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
	 */
	public function satisfies(string $have, string $need): bool {
		return $this->rank(trust: $have) >= $this->rank(trust: $need);

	}//end satisfies()

	/**
	 * Every level this mapper knows for one provider, for a configuration
	 * screen.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array<int,string> The level names, sorted.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
	 */
	public function levelsFor(string $provider): array {
		$levels = array_keys((self::TABLE[strtolower(trim($provider))] ?? []));
		sort($levels);
		return $levels;

	}//end levelsFor()

	/**
	 * One trust level's rank.
	 *
	 * An unknown level ranks below `low` rather than equal to it, so a
	 * comparison against a level nobody recognises can never pass.
	 *
	 * @param string $trust The trust level.
	 *
	 * @return integer The rank.
	 */
	private function rank(string $trust): int {
		return match (strtolower(trim($trust))) {
			self::TRUST_HIGH => 3,
			self::TRUST_SUBSTANTIAL => 2,
			self::TRUST_LOW => 1,
			default => 0,
		};

	}//end rank()

}//end class
