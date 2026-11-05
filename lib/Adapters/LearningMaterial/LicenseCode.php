<?php

/**
 * Integriq learning material licence code.
 *
 * The closed set of normalised licence codes a learning material record
 * carries, with the translation to and from the codes Edurep indexes
 * (`cc-by-40`, `cc0-10`, `pdm-10`) and from a Creative Commons URL.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Normalised licence code of a learning material record.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
enum LicenseCode: string {
	case CC0 = 'CC0-1.0';
	case PDM = 'PDM';
	case CC_BY_40 = 'CC-BY-4.0';
	case CC_BY_SA_40 = 'CC-BY-SA-4.0';
	case CC_BY_NC_40 = 'CC-BY-NC-4.0';
	case CC_BY_ND_40 = 'CC-BY-ND-4.0';
	case CC_BY_NC_SA_40 = 'CC-BY-NC-SA-4.0';
	case CC_BY_NC_ND_40 = 'CC-BY-NC-ND-4.0';
	case CC_BY_30 = 'CC-BY-3.0';
	case CC_BY_SA_30 = 'CC-BY-SA-3.0';
	case CC_BY_NC_30 = 'CC-BY-NC-3.0';
	case CC_BY_ND_30 = 'CC-BY-ND-3.0';
	case CC_BY_NC_SA_30 = 'CC-BY-NC-SA-3.0';
	case CC_BY_NC_ND_30 = 'CC-BY-NC-ND-3.0';
	case RIGHTS_RESERVED = 'rights-reserved';
	case UNKNOWN = 'unknown';

	/**
	 * The code from an Edurep rights value such as `cc-by-sa-40` or `pdm-10`.
	 *
	 * @param string $value The `copyrightandotherrestrictions` value.
	 *
	 * @return self|null The code, or null when the value is no licence code (`yes`, `no`, unversioned forms).
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public static function fromEdurep(string $value): ?self {
		$value = strtolower(trim($value));
		if ($value === 'pdm-10') {
			return self::PDM;
		}

		if (preg_match('/^(cc0|cc(?:-(?:by|sa|nc|nd))+)-(\d)(\d)$/', $value, $match) !== 1) {
			return null;
		}

		return self::tryFrom(value: strtoupper($match[1]) . '-' . $match[2] . '.' . $match[3]);
	}//end fromEdurep()

	/**
	 * The code a Creative Commons licence or public domain URL names.
	 *
	 * @param string $url The licence URL.
	 *
	 * @return self|null The code, or null for any other URL.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public static function fromUrl(string $url): ?self {
		$url = strtolower($url);
		if (preg_match('~creativecommons\.org/publicdomain/(zero|mark)/~', $url, $match) === 1) {
			return [
				'zero' => self::CC0,
				'mark' => self::PDM,
			][$match[1]];
		}

		if (preg_match('~creativecommons\.org/licenses/(by(?:-(?:sa|nc|nd))*)/(\d\.\d)~', $url, $match) !== 1) {
			return null;
		}

		return self::tryFrom(value: 'CC-' . strtoupper($match[1]) . '-' . $match[2]);
	}//end fromUrl()

	/**
	 * The value Edurep indexes for this code, used as a search filter.
	 *
	 * @return string The `lom.rights.copyrightandotherrestrictions` value.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function toEdurep(): string {
		return match ($this) {
			self::PDM => 'pdm-10',
			self::RIGHTS_RESERVED => 'no',
			self::UNKNOWN => 'yes',
			default => str_replace('.', '', strtolower($this->value)),
		};
	}//end toEdurep()
}//end enum
