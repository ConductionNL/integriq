<?php

/**
 * E.164 phone number normaliser over PhoneNumberValidator.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

/**
 * Applies {@see PhoneNumberValidator::toE164()} with its default calling code.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PhoneNumberValidator is a static helper by design.
 */
class E164PhoneNumberNormaliser implements PhoneNumberNormaliserInterface {
	/**
	 * Normalise one raw phone number to E.164.
	 *
	 * @param string $rawNumber The raw, possibly human-formatted phone number.
	 *
	 * @return string|null The E.164 number, or null when none can be derived.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function toE164(string $rawNumber): ?string {
		return PhoneNumberValidator::toE164(rawNumber: $rawNumber);
	}//end toE164()
}//end class
