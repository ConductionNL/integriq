<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use OCA\Integriq\Service\Sms\E164PhoneNumberNormaliser;
use OCA\Integriq\Service\Sms\PhoneNumberValidator;
use PHPUnit\Framework\TestCase;

/**
 * The normaliser answers exactly what PhoneNumberValidator::toE164() answers with its default calling code.
 *
 * @covers \OCA\Integriq\Service\Sms\E164PhoneNumberNormaliser
 * @uses   \OCA\Integriq\Service\Sms\PhoneNumberValidator
 */
class E164PhoneNumberNormaliserTest extends TestCase {
	/**
	 * Every shape gives the same result as the static validator.
	 *
	 * @return void
	 *
	 * @spec exclude refactoring guard, the normalisation rules are specified on PhoneNumberValidator
	 */
	public function testMatchesTheValidator(): void {
		$normaliser = new E164PhoneNumberNormaliser();
		foreach (['0612345678', '+31 6 1234 5678', '0031612345678', '31612345678', '(0)20-1234567', 'not a number', ''] as $raw) {
			$this->assertSame(PhoneNumberValidator::toE164(rawNumber: $raw), $normaliser->toE164(rawNumber: $raw), $raw);
		}

		$this->assertSame('+31612345678', $normaliser->toE164(rawNumber: '0612345678'));
		$this->assertNull($normaliser->toE164(rawNumber: 'not a number'));
	}
}
