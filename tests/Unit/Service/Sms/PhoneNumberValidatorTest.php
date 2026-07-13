<?php

/**
 * Unit tests for PhoneNumberValidator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/notifynl-sms-channel/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Sms;

use OCA\OpenConnector\Service\Sms\PhoneNumberValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure E.164 phone number normaliser/validator.
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-e164-phone-validation-with-nl-default-region
 */
class PhoneNumberValidatorTest extends TestCase
{

    /**
     * A national NL trunk-format number is normalised to E.164 with the default calling code.
     *
     * @return void
     */
    public function testNationalNlNumberIsNormalisedToE164(): void
    {
        $this->assertSame('+31612345678', PhoneNumberValidator::toE164(rawNumber: '0612345678'));

    }//end testNationalNlNumberIsNormalisedToE164()

    /**
     * An already-E.164 number is returned unchanged.
     *
     * @return void
     */
    public function testAlreadyE164NumberIsUnchanged(): void
    {
        $this->assertSame('+31612345678', PhoneNumberValidator::toE164(rawNumber: '+31612345678'));

    }//end testAlreadyE164NumberIsUnchanged()

    /**
     * An international `00`-prefixed number is normalised to `+`.
     *
     * @return void
     */
    public function testInternationalPrefixNumberIsNormalised(): void
    {
        $this->assertSame('+31612345678', PhoneNumberValidator::toE164(rawNumber: '0031612345678'));

    }//end testInternationalPrefixNumberIsNormalised()

    /**
     * A bare-digit number with no leading 0/+/00 is assumed to already carry the calling code.
     *
     * @return void
     */
    public function testBareDigitsAreAssumedToCarryCallingCode(): void
    {
        $this->assertSame('+31612345678', PhoneNumberValidator::toE164(rawNumber: '31612345678'));

    }//end testBareDigitsAreAssumedToCarryCallingCode()

    /**
     * Human formatting (spaces, hyphens, dots, parentheses) is stripped before normalisation.
     *
     * @return void
     */
    public function testHumanFormattingIsStripped(): void
    {
        $this->assertSame('+31612345678', PhoneNumberValidator::toE164(rawNumber: '+31 (0)6 1234-5678'));

    }//end testHumanFormattingIsStripped()

    /**
     * A non-default calling code can be supplied explicitly.
     *
     * @return void
     */
    public function testExplicitCallingCodeOverridesDefault(): void
    {
        $this->assertSame('+3212345678', PhoneNumberValidator::toE164(rawNumber: '012345678', callingCode: '32'));

    }//end testExplicitCallingCodeOverridesDefault()

    /**
     * An empty string is rejected.
     *
     * @return void
     */
    public function testEmptyStringIsRejected(): void
    {
        $this->assertNull(PhoneNumberValidator::toE164(rawNumber: ''));

    }//end testEmptyStringIsRejected()

    /**
     * A string with no digits at all is rejected.
     *
     * @return void
     */
    public function testNonNumericStringIsRejected(): void
    {
        $this->assertNull(PhoneNumberValidator::toE164(rawNumber: 'not-a-number'));

    }//end testNonNumericStringIsRejected()

    /**
     * A candidate that is too short to be a plausible E.164 number is rejected.
     *
     * @return void
     */
    public function testTooShortNumberIsRejected(): void
    {
        $this->assertNull(PhoneNumberValidator::toE164(rawNumber: '+311'));

    }//end testTooShortNumberIsRejected()

    /**
     * A leading-zero E.164 candidate (invalid per the standard) is rejected.
     *
     * @return void
     */
    public function testLeadingZeroAfterPlusIsRejected(): void
    {
        $this->assertNull(PhoneNumberValidator::toE164(rawNumber: '+0612345678'));

    }//end testLeadingZeroAfterPlusIsRejected()

    /**
     * isValidE164 accepts a well-formed candidate directly.
     *
     * @return void
     */
    public function testIsValidE164AcceptsWellFormedCandidate(): void
    {
        $this->assertTrue(PhoneNumberValidator::isValidE164(candidate: '+31612345678'));

    }//end testIsValidE164AcceptsWellFormedCandidate()

    /**
     * isValidE164 rejects a candidate without a leading `+`.
     *
     * @return void
     */
    public function testIsValidE164RejectsMissingPlus(): void
    {
        $this->assertFalse(PhoneNumberValidator::isValidE164(candidate: '31612345678'));

    }//end testIsValidE164RejectsMissingPlus()
}//end class
