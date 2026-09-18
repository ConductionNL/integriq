<?php

/**
 * Integriq — BRP property-source binding tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource\Provider
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

namespace OCA\Integriq\Tests\Unit\PropertySource\Provider;

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\Provider\BrpPropertySource;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RFS-006: a binding reads through the gateway, never through a client of
 * its own, and a missing source is a configuration error.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class BrpPropertySourceTest extends TestCase {
	/**
	 * A gateway double restricted to the method the real class has.
	 *
	 * @return RegistrySourceGateway The double.
	 */
	private function gateway(): RegistrySourceGateway {
		return $this->getMockBuilder(RegistrySourceGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
	}//end gateway()

	/**
	 * The binding reads the seeded Haal Centraal source through the gateway.
	 *
	 * @return void
	 */
	public function testResolveReadsTheSeededSourceThroughTheGateway(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())
			->method('read')
			->with('brp', BrpPropertySource::SOURCE_SLUG, '/brp/999993653', [])
			->willReturn(['burgerservicenummer' => '999993653', 'naam' => 'De Vries']);

		$value = (new BrpPropertySource($gateway))->resolve('999993653');

		$this->assertSame('De Vries', $value['naam']);
	}//end testResolveReadsTheSeededSourceThroughTheGateway()

	/**
	 * A declared `source` in the property configuration overrides the default slug.
	 *
	 * @return void
	 */
	public function testADeclaredSourceOverridesTheDefaultSlug(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->once())
			->method('read')
			->with('brp', 'brp-acceptatie', $this->anything(), $this->anything())
			->willReturn(['burgerservicenummer' => '999993653']);

		(new BrpPropertySource($gateway))->resolve('999993653', ['source' => 'brp-acceptatie']);
	}//end testADeclaredSourceOverridesTheDefaultSlug()

	/**
	 * A binding whose source is not configured fails naming the source, and no
	 * call is made.
	 *
	 * @return void
	 */
	public function testAMissingSourceFailsBeforeAnyCall(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willThrowException(
			new MissingSourceConfigurationException('brp', BrpPropertySource::SOURCE_SLUG)
		);

		try {
			(new BrpPropertySource($gateway))->resolve('999993653');
			$this->fail('A binding without a source must not resolve.');
		} catch (MissingSourceConfigurationException $e) {
			$this->assertSame(BrpPropertySource::SOURCE_SLUG, $e->getSourceSlug());
			$this->assertStringContainsString(BrpPropertySource::SOURCE_SLUG, $e->getMessage());
		}
	}//end testAMissingSourceFailsBeforeAnyCall()

	/**
	 * A body without the keyed identifier is not a record.
	 *
	 * @return void
	 */
	public function testABodyWithoutTheIdentifierIsNotARecord(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturn(['foutmelding' => 'niet gevonden']);

		$this->expectException(SourceUnreachableException::class);

		(new BrpPropertySource($gateway))->resolve('999993653');
	}//end testABodyWithoutTheIdentifierIsNotARecord()

	/**
	 * describe() names the identifier the registry keys on.
	 *
	 * @return void
	 */
	public function testDescribeNamesTheIdentifierItKeysOn(): void {
		$described = (new BrpPropertySource($this->gateway()))->describe();

		$this->assertSame('brp', $described['id']);
		$this->assertSame('burgerservicenummer', $described['identifier']);
		$this->assertSame(BrpPropertySource::STALENESS_BUDGET, $described['stalenessBudget']);
	}//end testDescribeNamesTheIdentifierItKeysOn()
}//end class
