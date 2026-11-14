<?php

/**
 * Unit tests for the assurance floor on the trust table.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssuranceBelowFloorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the floor, the canonical level and the names a person reads.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 */
#[CoversClass(TrustLevelMapper::class)]
#[CoversClass(IdpAssuranceBelowFloorException::class)]
class TrustLevelFloorTest extends TestCase {

	/**
	 * The floor is substantial; low misses it, substantial and high meet it,
	 * and an unknown trust never does.
	 *
	 * @return void
	 */
	public function testTheFloorIsSubstantial(): void {
		$mapper = new TrustLevelMapper();

		$this->assertSame('substantial', TrustLevelMapper::FLOOR);
		$this->assertFalse($mapper->meetsFloor('low'));
		$this->assertTrue($mapper->meetsFloor('substantial'));
		$this->assertTrue($mapper->meetsFloor(' HIGH '));
		$this->assertFalse($mapper->meetsFloor('unheard-of'));
		$this->assertFalse($mapper->meetsFloor(''));
	}//end testTheFloorIsSubstantial()

	/**
	 * Each refused and accepted level maps to the side of the floor the decision puts it on.
	 *
	 * @return void
	 */
	public function testEachLevelLandsOnItsSideOfTheFloor(): void {
		$mapper = new TrustLevelMapper();
		$below = [['digid', 'Basis'], ['digid', 'Midden'], ['eherkenning', 'EH2'], ['eherkenning', 'EH2+'], ['eidas', 'low']];
		$above = [['digid', 'Substantieel'], ['digid', 'Hoog'], ['eherkenning', 'EH3'], ['eherkenning', 'EH4'], ['eidas', 'substantial'], ['eidas', 'high']];

		foreach ($below as [$provider, $level]) {
			$this->assertFalse($mapper->meetsFloor($mapper->map($provider, $level)), $provider . ' ' . $level);
		}

		foreach ($above as [$provider, $level]) {
			$this->assertTrue($mapper->meetsFloor($mapper->map($provider, $level)), $provider . ' ' . $level);
		}
	}//end testEachLevelLandsOnItsSideOfTheFloor()

	/**
	 * The canonical level is trimmed, lowercased and aliased, by the raw or the lowercased spelling.
	 *
	 * @return void
	 */
	public function testTheCanonicalLevelAppliesTheAlias(): void {
		$mapper = new TrustLevelMapper();

		$this->assertSame('midden', $mapper->canonicalLevel(' Midden '));
		$this->assertSame('substantieel', $mapper->canonicalLevel('urn:x:Sub', ['urn:x:Sub' => ' Substantieel ']));
		$this->assertSame('basis', $mapper->canonicalLevel('URN:X:B', ['urn:x:b' => 'basis']));
		$this->assertSame('substantial', $mapper->map('digid', 'urn:x:Sub', ['urn:x:Sub' => 'substantieel']));
	}//end testTheCanonicalLevelAppliesTheAlias()

	/**
	 * The page names levels by the schemes' own names, and the floor per provider.
	 *
	 * @return void
	 */
	public function testLevelsAndTheFloorHaveTheirSchemeNames(): void {
		$mapper = new TrustLevelMapper();

		$this->assertSame('DigiD Midden', $mapper->levelLabel('digid', 'midden'));
		$this->assertSame('DigiD Basis', $mapper->levelLabel('DigiD', ' BASIS '));
		$this->assertSame('EH2+', $mapper->levelLabel('eherkenning', 'urn:etoegang:core:assurance-class:loa2plus'));
		$this->assertSame('eIDAS low', $mapper->levelLabel('eidas', 'http://eidas.europa.eu/loa/low'));
		$this->assertSame('urn:unmapped', $mapper->levelLabel('digid', 'urn:unmapped'));

		$this->assertSame('DigiD Substantieel', $mapper->floorLabel('digid'));
		$this->assertSame('EH3', $mapper->floorLabel(' eHerkenning '));
		$this->assertSame('eIDAS substantial', $mapper->floorLabel('eidas'));
		$this->assertSame('substantial', $mapper->floorLabel('unknown'));
	}//end testLevelsAndTheFloorHaveTheirSchemeNames()

	/**
	 * The refusal carries the provider and level, and gains its way back without losing them.
	 *
	 * @return void
	 */
	public function testTheRefusalCarriesItsWayBack(): void {
		$refusal = new IdpAssuranceBelowFloorException(provider: 'digid', levelUsed: 'midden');
		$this->assertSame('', $refusal->getReturnUrl());
		$this->assertSame('The assurance level is below the floor of substantial.', $refusal->getMessage());

		$withUrl = $refusal->withReturnUrl('https://portal.example/cb?error=assurance_below_floor&relayState=r');

		$this->assertNotSame($refusal, $withUrl);
		$this->assertSame('digid', $withUrl->getProvider());
		$this->assertSame('midden', $withUrl->getLevelUsed());
		$this->assertSame('https://portal.example/cb?error=assurance_below_floor&relayState=r', $withUrl->getReturnUrl());
		$this->assertSame('', $refusal->getReturnUrl());
	}//end testTheRefusalCarriesItsWayBack()
}//end class
