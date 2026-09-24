<?php

/**
 * Unit tests for the trust table and the pseudonym.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the trust mapping and the pseudonymisation.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
 */
class TrustAndPseudonymTest extends TestCase {

	/**
	 * A salt long enough to compute under.
	 *
	 * @var string
	 */
	private const SALT_X = 'gemeente-x-salt-0123456789abcdef0123456789abcdef';

	/**
	 * A different organisation's salt.
	 *
	 * @var string
	 */
	private const SALT_Y = 'gemeente-y-salt-0123456789abcdef0123456789abcdef';

	/**
	 * The spec's table, read straight off it.
	 *
	 * @return void
	 */
	public function testTheTableMapsWhatTheSpecSaysItMaps(): void {
		$mapper = new TrustLevelMapper();

		$this->assertSame('low', $mapper->map('digid', 'Basis'));
		$this->assertSame('low', $mapper->map('digid', 'Midden'));
		$this->assertSame('substantial', $mapper->map('digid', 'Substantieel'));
		$this->assertSame('high', $mapper->map('digid', 'Hoog'));

		$this->assertSame('low', $mapper->map('eherkenning', 'EH2'));
		$this->assertSame('low', $mapper->map('eherkenning', 'EH2+'));
		$this->assertSame('substantial', $mapper->map('eherkenning', 'EH3'));
		$this->assertSame('high', $mapper->map('eherkenning', 'EH4'));

		$this->assertSame('substantial', $mapper->map('eherkenning', 'urn:etoegang:core:assurance-class:loa3'));
		$this->assertSame('high', $mapper->map('eidas', 'http://eidas.europa.eu/LoA/high'));
		$this->assertSame('low', $mapper->map('eidas', 'low'));

	}//end testTheTableMapsWhatTheSpecSaysItMaps()

	/**
	 * An unmapped level ends the login. It does not become `low`.
	 *
	 * @return void
	 */
	public function testAnUnknownLevelIsRefusedRatherThanLowered(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('is not mapped for provider "digid"');
		(new TrustLevelMapper())->map('digid', 'Zeer Hoog');

	}//end testAnUnknownLevelIsRefusedRatherThanLowered()

	/**
	 * A provider nobody configured is refused too.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		(new TrustLevelMapper())->map('itsme', 'high');

	}//end testAnUnknownProviderIsRefused()

	/**
	 * A tenant alias is how an unverifiable spelling reaches the table.
	 *
	 * @return void
	 */
	public function testATenantAliasMapsAnUnknownSpelling(): void {
		$trust = (new TrustLevelMapper())->map(
			'digid',
			'urn:oasis:names:tc:SAML:2.0:ac:classes:SmartcardPKI',
			['urn:oasis:names:tc:SAML:2.0:ac:classes:SmartcardPKI' => 'Hoog']
		);

		$this->assertSame('high', $trust);

	}//end testATenantAliasMapsAnUnknownSpelling()

	/**
	 * An unknown level ranks below `low`, so a comparison against one can
	 * never pass.
	 *
	 * @return void
	 */
	public function testAnUnknownTrustLevelSatisfiesNothing(): void {
		$mapper = new TrustLevelMapper();

		$this->assertTrue($mapper->satisfies('high', 'substantial'));
		$this->assertTrue($mapper->satisfies('substantial', 'substantial'));
		$this->assertFalse($mapper->satisfies('low', 'substantial'));
		$this->assertFalse($mapper->satisfies('whatever', 'low'));

	}//end testAnUnknownTrustLevelSatisfiesNothing()

	/**
	 * The same citizen in the same organisation gets the same pseudonym, and
	 * the BSN is nowhere in it.
	 *
	 * @return void
	 */
	public function testThePseudonymIsStableWithinOneOrganisation(): void {
		$service = new SubjectPseudonymService();

		$first = $service->pseudonymFor('123456782', 'gemeente-x', self::SALT_X);
		$second = $service->pseudonymFor('123456782', 'gemeente-x', self::SALT_X);

		$this->assertSame($first, $second);
		$this->assertStringNotContainsString('123456782', $first);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);

	}//end testThePseudonymIsStableWithinOneOrganisation()

	/**
	 * The same citizen in another organisation gets another pseudonym.
	 *
	 * @return void
	 */
	public function testThePseudonymDiffersAcrossOrganisations(): void {
		$service = new SubjectPseudonymService();

		$this->assertNotSame(
			$service->pseudonymFor('123456782', 'gemeente-x', self::SALT_X),
			$service->pseudonymFor('123456782', 'gemeente-y', self::SALT_Y)
		);

	}//end testThePseudonymDiffersAcrossOrganisations()

	/**
	 * An operator who reuses one salt across two organisations still gets two
	 * pseudonyms, because the organisation is part of the signed message and
	 * not only part of the key.
	 *
	 * @return void
	 */
	public function testOneSaltReusedAcrossOrganisationsStillSeparatesThem(): void {
		$service = new SubjectPseudonymService();

		$this->assertNotSame(
			$service->pseudonymFor('123456782', 'gemeente-x', self::SALT_X),
			$service->pseudonymFor('123456782', 'gemeente-y', self::SALT_X)
		);

	}//end testOneSaltReusedAcrossOrganisationsStillSeparatesThem()

	/**
	 * A salt too short to hide a nine-digit number is refused, not used.
	 *
	 * @return void
	 */
	public function testAShortSaltIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('shorter than 32 bytes');
		(new SubjectPseudonymService())->pseudonymFor('123456782', 'gemeente-x', 'kort');

	}//end testAShortSaltIsRefused()

	/**
	 * A polymorphic pseudonym passes through, and an empty one is refused
	 * rather than read as "no subject".
	 *
	 * @return void
	 */
	public function testThePolymorphicPseudonymPassesThroughOrIsRefused(): void {
		$service = new SubjectPseudonymService();

		$this->assertSame('pp-abc-123', $service->fromPolymorphic(' pp-abc-123 '));

		$this->expectException(IdpAssertionException::class);
		$service->fromPolymorphic('   ');

	}//end testThePolymorphicPseudonymPassesThroughOrIsRefused()

}//end class
