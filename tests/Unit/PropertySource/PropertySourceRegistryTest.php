<?php

/**
 * Integriq — property-source registry tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\PropertySource;

use OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException;
use OCA\Integriq\PropertySource\PropertySourceProviderInterface;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RFS-001: one contract, keyed by provider id, first registration wins.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
class PropertySourceRegistryTest extends TestCase {
	/**
	 * Build a provider double answering to one id.
	 *
	 * @param string $id Provider id.
	 * @param string $label Label the description carries.
	 *
	 * @return PropertySourceProviderInterface The double.
	 */
	private function provider(string $id, string $label = 'x'): PropertySourceProviderInterface {
		$provider = $this->createMock(PropertySourceProviderInterface::class);
		$provider->method('id')->willReturn($id);
		$provider->method('describe')->willReturn(
			[
				'id' => $id,
				'label' => $label,
				'identifier' => 'identifier',
				'stalenessBudget' => 60,
				'listShaped' => false,
			]
		);

		return $provider;
	}//end provider()

	/**
	 * A declared provider answers a resolve.
	 *
	 * @return void
	 */
	public function testDeclaredProviderIsReturnedById(): void {
		$bag = $this->provider('bag');
		$registry = new PropertySourceRegistry([$bag]);

		$this->assertTrue($registry->has('bag'));
		$this->assertSame($bag, $registry->get('bag'));
	}//end testDeclaredProviderIsReturnedById()

	/**
	 * An unknown provider id fails naming itself, and invents nothing.
	 *
	 * @return void
	 */
	public function testUnknownProviderFailsNamingTheId(): void {
		$registry = new PropertySourceRegistry([$this->provider('bag')]);

		$this->expectException(UnknownPropertySourceException::class);
		$this->expectExceptionMessage('No property source provider is registered under the id "kadaster"');

		$registry->get('kadaster');
	}//end testUnknownProviderFailsNamingTheId()

	/**
	 * The failure names the ids that do exist, so the message is actionable.
	 *
	 * @return void
	 */
	public function testUnknownProviderFailureListsTheKnownIds(): void {
		$registry = new PropertySourceRegistry([$this->provider('bag'), $this->provider('brp')]);

		try {
			$registry->get('kadaster');
			$this->fail('An unknown provider id must not resolve.');
		} catch (UnknownPropertySourceException $e) {
			$this->assertSame('kadaster', $e->getProviderId());
			$this->assertStringContainsString('bag, brp', $e->getMessage());
		}
	}//end testUnknownProviderFailureListsTheKnownIds()

	/**
	 * A second registration under a taken id is ignored, as in the
	 * integration registry.
	 *
	 * @return void
	 */
	public function testFirstRegistrationWinsOnACollision(): void {
		$first = $this->provider('bag', 'first');
		$second = $this->provider('bag', 'second');
		$registry = new PropertySourceRegistry([$first]);

		$this->assertFalse($registry->register($second));
		$this->assertSame($first, $registry->get('bag'));
	}//end testFirstRegistrationWinsOnACollision()

	/**
	 * describe() reaches the caller as the registry's inventory.
	 *
	 * @return void
	 */
	public function testDescribeAllReportsEveryProvider(): void {
		$registry = new PropertySourceRegistry([$this->provider('bag'), $this->provider('brp')]);
		$described = $registry->describeAll();

		$this->assertCount(2, $described);
		$this->assertSame(['bag', 'brp'], array_column($described, 'id'));
		$this->assertSame(60, $described[0]['stalenessBudget']);
	}//end testDescribeAllReportsEveryProvider()
}//end class
