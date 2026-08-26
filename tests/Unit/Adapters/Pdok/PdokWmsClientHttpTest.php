<?php

/**
 * Unit tests for PdokWmsClientHttp.
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
use OCA\Integriq\Adapters\Pdok\PdokWmsClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the HTTPS PDOK WMS client.
 */
class PdokWmsClientHttpTest extends TestCase {

	/**
	 * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
	 */
	private array $history = [];

	/**
	 * @param array<int,Response> $responses
	 *
	 * @return PdokWmsClientHttp
	 */
	private function buildClient(array $responses): PdokWmsClientHttp {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new PdokWmsClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: new NullLogger(),
			baseUri: 'https://service.pdok.nl/lv/{dataset}/wms/v2_0'
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
	public function testGetCapabilitiesIssuesGetCapabilitiesRequest(): void {
		$xml = '<?xml version="1.0"?><WMS_Capabilities/>';
		$client = $this->buildClient([new Response(200, [], $xml)]);

		$result = $client->getCapabilities('bag');

		$this->assertSame($xml, $result);
		$this->assertCount(1, $this->history);

		$request = $this->history[0]['request'];
		$this->assertSame('GET', $request->getMethod());
		$this->assertStringContainsString('/lv/bag/wms/v2_0', (string)$request->getUri());

		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame('WMS', $query['service']);
		$this->assertSame('GetCapabilities', $query['request']);
		$this->assertSame('1.3.0', $query['version']);
	}//end testGetCapabilitiesIssuesGetCapabilitiesRequest()

	/**
	 * @return void
	 */
	public function testGetMapIssuesGetMapRequestWithBbox(): void {
		$png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 8);
		$client = $this->buildClient([new Response(200, ['Content-Type' => 'image/png'], $png)]);

		$bbox = [120000.0, 480000.0, 130000.0, 490000.0];
		$result = $client->getMap('bag', 'pand', $bbox, 'EPSG:28992', 1024, 768, 'image/png');

		$this->assertSame($png, $result);

		$request = $this->history[0]['request'];
		parse_str($request->getUri()->getQuery(), $query);

		$this->assertSame('GetMap', $query['request']);
		$this->assertSame('pand', $query['layers']);
		$this->assertSame('EPSG:28992', $query['crs']);
		$this->assertSame('120000,480000,130000,490000', $query['bbox']);
		$this->assertSame('1024', $query['width']);
		$this->assertSame('768', $query['height']);
		$this->assertSame('image/png', $query['format']);
		$this->assertSame('true', $query['transparent']);
	}//end testGetMapIssuesGetMapRequestWithBbox()

	/**
	 * @return void
	 */
	public function testTransportFailureReturnsEmptyString(): void {
		$client = $this->buildClient([new Response(500, [], 'oops')]);

		// A non-2xx will throw a Guzzle exception inside the client; the
		// client catches it and returns an empty string.
		$result = $client->getCapabilities('bag');

		$this->assertSame('', $result);
	}//end testTransportFailureReturnsEmptyString()

	/**
	 * @return void
	 */
	public function testGetMapInterpolatesDatasetSlugSafely(): void {
		$client = $this->buildClient([new Response(200, [], 'png')]);
		$client->getMap('special slug', 'layer', [0.0, 0.0, 1.0, 1.0]);

		$uri = (string)$this->history[0]['request']->getUri();

		$this->assertStringContainsString('/lv/special%20slug/wms/v2_0', $uri);
	}//end testGetMapInterpolatesDatasetSlugSafely()

}//end class
