<?php

/**
 * Unit tests for PdokWfsClientHttp.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Pdok;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the HTTPS PDOK WFS client.
 */
class PdokWfsClientHttpTest extends TestCase {

	/**
	 * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
	 */
	private array $history = [];

	/**
	 * @param array<int,Response> $responses
	 *
	 * @return PdokWfsClientHttp
	 */
	private function buildClient(array $responses): PdokWfsClientHttp {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new PdokWfsClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: new NullLogger(),
			baseUri: 'https://service.pdok.nl/lv/{dataset}/wfs/v2_0'
		);
	}//end buildClient()

	/**
	 * @return void
	 */
	public function testFlavourIsHttp(): void {
		$this->assertSame('http', $this->buildClient([])->flavour());
	}//end testFlavourIsHttp()

	/**
	 * @return void
	 */
	public function testGetCapabilitiesIssuesGetRequest(): void {
		$xml = '<?xml version="1.0"?><WFS_Capabilities/>';
		$client = $this->buildClient([new Response(200, [], $xml)]);

		$result = $client->getCapabilities('bag');

		$this->assertSame($xml, $result);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('WFS', $query['service']);
		$this->assertSame('GetCapabilities', $query['request']);
		$this->assertSame('2.0.0', $query['version']);
	}//end testGetCapabilitiesIssuesGetRequest()

	/**
	 * @return void
	 */
	public function testDescribeFeatureTypeIncludesTypeName(): void {
		$client = $this->buildClient([new Response(200, [], '<xsd:schema/>')]);

		$client->describeFeatureType('bag', 'bag:verblijfsobject');

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('DescribeFeatureType', $query['request']);
		$this->assertSame('bag:verblijfsobject', $query['typeNames']);
	}//end testDescribeFeatureTypeIncludesTypeName()

	/**
	 * @return void
	 */
	public function testGetFeatureRequestsJsonAndDecodesCollection(): void {
		$payload = [
			'type' => 'FeatureCollection',
			'features' => [
				[
					'type' => 'Feature',
					'id' => 'verblijfsobject.0363010000406543',
					'geometry' => ['type' => 'Point', 'coordinates' => [4.88525, 52.37025]],
					'properties' => ['identificatie' => '0363010000406543'],
				],
			],
			'totalFeatures' => 1,
			'numberMatched' => 1,
			'numberReturned' => 1,
		];

		$client = $this->buildClient([new Response(200, [], json_encode($payload))]);

		$bbox = [120000.0, 480000.0, 130000.0, 490000.0];
		$result = $client->getFeature('bag', 'bag:verblijfsobject', $bbox, 5, 'EPSG:28992');

		$this->assertSame('FeatureCollection', $result['type']);
		$this->assertCount(1, $result['features']);
		$this->assertSame('verblijfsobject.0363010000406543', $result['features'][0]['id']);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('GetFeature', $query['request']);
		$this->assertSame('application/json', $query['outputFormat']);
		$this->assertSame('bag:verblijfsobject', $query['typeNames']);
		$this->assertSame('5', $query['count']);
		$this->assertSame('120000,480000,130000,490000,EPSG:28992', $query['bbox']);
	}//end testGetFeatureRequestsJsonAndDecodesCollection()

	/**
	 * @return void
	 */
	public function testGetFeatureWithoutBboxOmitsParameter(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['type' => 'FeatureCollection', 'features' => []]))]);

		$client->getFeature('bag', 'bag:verblijfsobject');

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertArrayNotHasKey('bbox', $query);
	}//end testGetFeatureWithoutBboxOmitsParameter()

	/**
	 * @return void
	 */
	public function testGetFeatureWithFilterFieldsBuildsOgcFilter(): void {
		$client = $this->buildClient([new Response(200, [], json_encode(['type' => 'FeatureCollection', 'features' => []]))]);

		$client->getFeature(
			'bag',
			'bag:verblijfsobject',
			null,
			10,
			'EPSG:28992',
			['postcode' => '1016RG']
		);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertArrayHasKey('filter', $query);
		$this->assertStringContainsString('PropertyIsEqualTo', $query['filter']);
		$this->assertStringContainsString('postcode', $query['filter']);
		$this->assertStringContainsString('1016RG', $query['filter']);
	}//end testGetFeatureWithFilterFieldsBuildsOgcFilter()

	/**
	 * @return void
	 */
	public function testGetFeatureReturnsEmptyCollectionOnDecodeFailure(): void {
		$client = $this->buildClient([new Response(200, [], 'not-json')]);

		$result = $client->getFeature('bag', 'bag:verblijfsobject');

		$this->assertSame('FeatureCollection', $result['type']);
		$this->assertSame([], $result['features']);
		$this->assertSame(0, $result['totalFeatures']);
	}//end testGetFeatureReturnsEmptyCollectionOnDecodeFailure()

}//end class
