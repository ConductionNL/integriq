<?php

/**
 * Unit tests for the trust-level listing and the pseudonym's input refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A provider's levels are listed sorted; a pseudonym needs a subject and an organisation.
 */
#[CoversClass(TrustLevelMapper::class)]
#[CoversClass(SubjectPseudonymService::class)]
#[UsesClass(IdpAssertionException::class)]
class TrustAndPseudonymEdgesTest extends TestCase {

	/**
	 * A salt long enough to pass the length check.
	 */
	private const SALT = 'gemeente-x-salt-0123456789abcdef0123456789abcdef';

	/**
	 * Each provider's level names come back sorted, whatever the provider's spelling.
	 *
	 * @return void
	 */
	public function testLevelsForListsTheProvidersLevelsSorted(): void {
		$mapper = new TrustLevelMapper();

		$this->assertSame(['basis', 'hoog', 'midden', 'substantieel'], $mapper->levelsFor(provider: ' DigiD '));
		$this->assertSame(
			[
				'high',
				'http://eidas.europa.eu/loa/high',
				'http://eidas.europa.eu/loa/low',
				'http://eidas.europa.eu/loa/substantial',
				'low',
				'substantial',
			],
			$mapper->levelsFor(provider: 'eidas')
		);
		$this->assertCount(8, $mapper->levelsFor(provider: 'eherkenning'));

	}//end testLevelsForListsTheProvidersLevelsSorted()

	/**
	 * An unknown provider lists no levels.
	 *
	 * @return void
	 */
	public function testLevelsForAnUnknownProviderIsEmpty(): void {
		$this->assertSame([], (new TrustLevelMapper())->levelsFor(provider: 'itsme'));

	}//end testLevelsForAnUnknownProviderIsEmpty()

	/**
	 * Blank subjects or organisations.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function blankInputs(): array {
		return [
			'empty subject' => ['', 'gemeente-x'],
			'whitespace subject' => ['   ', 'gemeente-x'],
			'empty organisation' => ['999993653', ''],
			'whitespace organisation' => ['999993653', "\t "],
		];

	}//end blankInputs()

	/**
	 * A blank subject or organisation is refused before the salt is looked at.
	 *
	 * @param string $bsn          The subject.
	 * @param string $organisation The organisation.
	 *
	 * @return void
	 */
	#[DataProvider('blankInputs')]
	public function testABlankSubjectOrOrganisationIsRefused(string $bsn, string $organisation): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('A pseudonym needs both a subject and an organisation, so no envelope is issued.');

		(new SubjectPseudonymService())->pseudonymFor(bsn: $bsn, organisation: $organisation, salt: '');

	}//end testABlankSubjectOrOrganisationIsRefused()

	/**
	 * Surrounding whitespace and organisation case do not change the pseudonym.
	 *
	 * @return void
	 */
	public function testThePseudonymIgnoresWhitespaceAndOrganisationCase(): void {
		$service = new SubjectPseudonymService();

		$expected = hash_hmac('sha256', "gemeente-x\0999993653", self::SALT);
		$this->assertSame($expected, $service->pseudonymFor(bsn: ' 999993653 ', organisation: ' Gemeente-X ', salt: self::SALT));

	}//end testThePseudonymIgnoresWhitespaceAndOrganisationCase()
}//end class
