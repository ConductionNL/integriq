<?php

/**
 * Unit tests for ObjecttypeRegistry refusals and version lists.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the registry refuses a non-object declaration and reads a version list.
 */
#[CoversClass(ObjecttypeRegistry::class)]
class ObjecttypeRegistryEdgeTest extends TestCase {

	/**
	 * A declaration that is not an object is refused with its reason and not loaded.
	 *
	 * @return void
	 */
	public function testANonObjectDeclarationIsRefused(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load(['melding', ['uuid' => 'aaa', 'name' => 'melding', 'register' => 'r', 'schema' => 's']]);

		$this->assertSame(
			[['declaration' => 'melding', 'reason' => 'an objecttype declaration must be an object']],
			$registry->refused()
		);
		$this->assertCount(1, $registry->all());
		$this->assertSame('an objecttype declaration must be an object', $registry->refusalFor(42));
	}//end testANonObjectDeclarationIsRefused()

	/**
	 * An unknown objecttype allows no version.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeAllowsNoVersion(): void {
		$registry = new ObjecttypeRegistry();

		$this->assertFalse($registry->allowsVersion(uuid: 'zzz', version: '1'));
	}//end testAnUnknownTypeAllowsNoVersion()

	/**
	 * A version list that is not a list counts as none, so every version is allowed.
	 *
	 * @return void
	 */
	public function testANonListVersionsFieldAllowsEveryVersion(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'n', 'register' => 'r', 'schema' => 's', 'versions' => '1']]);

		$this->assertSame([], $registry->find(uuid: 'aaa')['versions']);
		$this->assertTrue($registry->allowsVersion(uuid: 'aaa', version: '7'));
	}//end testANonListVersionsFieldAllowsEveryVersion()

	/**
	 * A version list is trimmed, drops blanks, and is closed to every other version.
	 *
	 * @return void
	 */
	public function testAVersionListIsCleanedAndClosed(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'n', 'register' => 'r', 'schema' => 's', 'versions' => [' 1 ', '', 2]]]);

		$this->assertSame(['1', '2'], $registry->find(uuid: 'aaa')['versions']);
		$this->assertTrue($registry->allowsVersion(uuid: 'aaa', version: ' 2 '));
		$this->assertFalse($registry->allowsVersion(uuid: 'aaa', version: '3'));
	}//end testAVersionListIsCleanedAndClosed()
}//end class
