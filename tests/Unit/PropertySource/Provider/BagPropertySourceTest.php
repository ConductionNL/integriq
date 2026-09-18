<?php

/**
 * Integriq — BAG property-source binding tests.
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

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\Provider\BagPropertySource;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RFS-006: the BAG binding reuses the PDOK connector and opens no
 * connection of its own.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class BagPropertySourceTest extends TestCase {
	/**
	 * A double of the shipped PDOK connector, restricted to methods it really has.
	 *
	 * @return PdokGeocodingClient The double.
	 */
	private function geocoding(): PdokGeocodingClient {
		return $this->getMockBuilder(PdokGeocodingClient::class)
			->disableOriginalConstructor()
			->onlyMethods(['suggest', 'lookup', 'reverse', 'flavour'])
			->getMock();
	}//end geocoding()

	/**
	 * The binding resolves through the PDOK connector, in its canonical shape.
	 *
	 * @return void
	 */
	public function testResolveGoesThroughThePdokConnector(): void {
		$geocoding = $this->geocoding();
		$geocoding->expects($this->once())
			->method('lookup')
			->with('adres-0363200000218908')
			->willReturn(['street' => 'Kerkstraat', 'houseNumber' => '1']);

		$value = (new BagPropertySource($geocoding))->resolve('adres-0363200000218908');

		$this->assertSame('Kerkstraat', $value['street']);
	}//end testResolveGoesThroughThePdokConnector()

	/**
	 * Suggestions are normalised to identifier and label.
	 *
	 * @return void
	 */
	public function testSuggestNormalisesToIdentifierAndLabel(): void {
		$geocoding = $this->geocoding();
		$geocoding->method('suggest')->willReturn(
			[
				['id' => 'adres-1', 'weergavenaam' => 'Kerkstraat 1, Amsterdam'],
				['weergavenaam' => 'no identifier, dropped'],
			]
		);

		$suggestions = (new BagPropertySource($geocoding))->suggest('kerkstr');

		$this->assertCount(1, $suggestions);
		$this->assertSame('adres-1', $suggestions[0]['identifier']);
		$this->assertSame('Kerkstraat 1, Amsterdam', $suggestions[0]['label']);
	}//end testSuggestNormalisesToIdentifierAndLabel()

	/**
	 * An id PDOK does not know is an unreachable value, never an empty address
	 * that reads like an answer.
	 *
	 * @return void
	 */
	public function testAnUnknownIdIsNotAnEmptyAddress(): void {
		$geocoding = $this->geocoding();
		$geocoding->method('lookup')->willReturn(null);

		$this->expectException(SourceUnreachableException::class);

		(new BagPropertySource($geocoding))->resolve('adres-nope');
	}//end testAnUnknownIdIsNotAnEmptyAddress()

	/**
	 * describe() names the identifier the binding keys on and its budget.
	 *
	 * @return void
	 */
	public function testDescribeNamesTheIdentifierAndTheBudget(): void {
		$described = (new BagPropertySource($this->geocoding()))->describe();

		$this->assertSame('bag', $described['id']);
		$this->assertSame('pdokId', $described['identifier']);
		$this->assertSame(BagPropertySource::STALENESS_BUDGET, $described['stalenessBudget']);
		$this->assertFalse($described['listShaped']);
	}//end testDescribeNamesTheIdentifierAndTheBudget()
}//end class
