<?php

/**
 * Unit tests for the PDOK *ClientMock dormant implementations.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Adapters\Pdok;

use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClient;
use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\OpenConnector\Adapters\Pdok\PdokWfsClient;
use OCA\OpenConnector\Adapters\Pdok\PdokWfsClientMock;
use OCA\OpenConnector\Adapters\Pdok\PdokWmsClient;
use OCA\OpenConnector\Adapters\Pdok\PdokWmsClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canned responses for the dormant PDOK adapter family.
 */
class PdokClientMockTest extends TestCase {

	/**
	 * @return void
	 */
	public function testGeocodingMockExtendsAbstract(): void {
		$mock = new PdokGeocodingClientMock();

		$this->assertInstanceOf(PdokGeocodingClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testGeocodingMockExtendsAbstract()

	/**
	 * @return void
	 */
	public function testGeocodingMockSuggestReturnsLauriergracht(): void {
		$mock = new PdokGeocodingClientMock();
		$result = $mock->suggest('anything', 5);

		$this->assertCount(1, $result);
		$this->assertSame('Lauriergracht 37, 1016 RG Amsterdam', $result[0]['displayName']);
		$this->assertSame('1016 RG', $result[0]['postalCode']);
		$this->assertSame([4.88525, 52.37025], $result[0]['location']['coordinates']);
	}//end testGeocodingMockSuggestReturnsLauriergracht()

	/**
	 * @return void
	 */
	public function testGeocodingMockLookupReturnsLauriergracht(): void {
		$mock = new PdokGeocodingClientMock();

		$this->assertSame(
			'adr-mock-lauriergracht-37',
			$mock->lookup('whatever')['pdokId']
		);
	}//end testGeocodingMockLookupReturnsLauriergracht()

	/**
	 * @return void
	 */
	public function testWmsMockExtendsAbstract(): void {
		$mock = new PdokWmsClientMock();

		$this->assertInstanceOf(PdokWmsClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testWmsMockExtendsAbstract()

	/**
	 * @return void
	 */
	public function testWmsMockCapabilitiesIsValidXml(): void {
		$mock = new PdokWmsClientMock();
		$xml = $mock->getCapabilities('bag');

		$this->assertStringStartsWith('<?xml', $xml);
		$this->assertStringContainsString('<WMS_Capabilities', $xml);
		$this->assertStringContainsString('bag', $xml);

		// Parse to ensure it's well-formed.
		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);

		$this->assertNotFalse($doc);
	}//end testWmsMockCapabilitiesIsValidXml()

	/**
	 * @return void
	 */
	public function testWmsMockGetMapReturnsValidPng(): void {
		$mock = new PdokWmsClientMock();
		$bytes = $mock->getMap('bag', 'pand', [0.0, 0.0, 1.0, 1.0]);

		// First 8 bytes must match the canonical PNG signature.
		$this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
	}//end testWmsMockGetMapReturnsValidPng()

	/**
	 * @return void
	 */
	public function testWfsMockExtendsAbstract(): void {
		$mock = new PdokWfsClientMock();

		$this->assertInstanceOf(PdokWfsClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testWfsMockExtendsAbstract()

	/**
	 * @return void
	 */
	public function testWfsMockCapabilitiesIsValidXml(): void {
		$mock = new PdokWfsClientMock();
		$xml = $mock->getCapabilities('bag');

		$this->assertStringContainsString('<WFS_Capabilities', $xml);

		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);

		$this->assertNotFalse($doc);
	}//end testWfsMockCapabilitiesIsValidXml()

	/**
	 * @return void
	 */
	public function testWfsMockGetFeatureReturnsFeatureCollection(): void {
		$mock = new PdokWfsClientMock();
		$result = $mock->getFeature('bag', 'bag:verblijfsobject');

		$this->assertSame('FeatureCollection', $result['type']);
		$this->assertCount(1, $result['features']);
		$this->assertSame('Lauriergracht', $result['features'][0]['properties']['openbareruimte']);
		$this->assertSame([4.88525, 52.37025], $result['features'][0]['geometry']['coordinates']);
	}//end testWfsMockGetFeatureReturnsFeatureCollection()

	/**
	 * @return void
	 */
	public function testWfsMockDescribeFeatureTypeReturnsValidXml(): void {
		$mock = new PdokWfsClientMock();
		$xml = $mock->describeFeatureType('bag', 'bag:verblijfsobject');

		$this->assertStringContainsString('<xsd:schema', $xml);
		$this->assertStringContainsString('bag:verblijfsobject', $xml);
	}//end testWfsMockDescribeFeatureTypeReturnsValidXml()

}//end class
