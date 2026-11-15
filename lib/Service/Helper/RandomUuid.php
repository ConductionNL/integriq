<?php

/**
 * Integriq random uuid.
 *
 * A fresh RFC 4122 version-4 uuid from 16 random bytes, lower-case and
 * hyphenated: the id the bank feed, card feed, EUDI and case-system mock
 * services mint for a new record.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

/**
 * Mints version-4 uuids.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md
 */
final class RandomUuid {

	/**
	 * A fresh RFC 4122 version-4 uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md
	 */
	public static function version4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end version4()
}//end class
