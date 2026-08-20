<?php

/**
 * Unit tests for PdokGeocodingClientHttp.
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

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the HTTPS PDOK Locatieserver client.
 *
 * Uses Guzzle's MockHandler to assert on the outbound request shape and to
 * inject canned PDOK Locatieserver-style JSON responses without hitting the
 * real network.
 */
class PdokGeocodingClientHttpTest extends TestCase {

	/**
	 * Captured outbound requests, populated by the history middleware.
	 *
	 * @var array<int,array{request:Request}>
	 */
	private array $history = [];

	/**
	 * Build a client wired to a Guzzle MockHandler that returns the supplied
	 * responses (in order). The history middleware writes each outbound
	 * request into `$this->history` so the test can assert on it.
	 *
	 * @param array<int,Response> $responses Canned responses.
	 *
	 * @return PdokGeocodingClientHttp
	 */
	private function buildClient(array $responses): PdokGeocodingClientHttp {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new PdokGeocodingClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: new NullLogger(),
			baseUri: 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/'
		);
	}//end buildClient()

	/**
	 * Build a Locatieserver-shaped doc payload.
	 *
	 * @param string $id The doc id.
	 * @param string $label The display label.
	 *
	 * @return array<string,mixed>
	 */
	private function lauriergrachtDoc(string $id, string $label): array {
		return [
			'id' => $id,
			'weergavenaam' => $label,
			'straatnaam' => 'Lauriergracht',
			'huisnummer' => '37',
			'postcode' => '1016 RG',
			'woonplaatsnaam' => 'Amsterdam',
			'provincienaam' => 'Noord-Holland',
			'centroide_ll' => 'POINT(4.88525 52.37025)',
			'nummeraanduiding_id' => '0363200000406543',
			'adresseerbaarobject_id' => '0363010000406543',
		];
	}//end lauriergrachtDoc()

	/**
	 * @return void
	 */
	public function testFlavourIsHttp(): void {
		$client = $this->buildClient([]);

		$this->assertSame('http', $client->flavour());
	}//end testFlavourIsHttp()

	/**
	 * @return void
	 */
	public function testSuggestIssuesGetRequestAndNormalisesDocs(): void {
		$payload = [
			'response' => [
				'docs' => [
					$this->lauriergrachtDoc('adr-1', 'Lauriergracht 37, 1016 RG Amsterdam'),
				],
			],
		];

		$client = $this->buildClient(
			[new Response(200, ['Content-Type' => 'application/json'], json_encode($payload))]
		);

		$result = $client->suggest('lauriergracht', 5);

		// Assert response normalisation.
		$this->assertCount(1, $result);
		$this->assertSame('Lauriergracht 37, 1016 RG Amsterdam', $result[0]['displayName']);
		$this->assertSame('Lauriergracht', $result[0]['streetAddress']);
		$this->assertSame('1016 RG', $result[0]['postalCode']);
		$this->assertSame('Amsterdam', $result[0]['addressLocality']);
		$this->assertSame('adr-1', $result[0]['pdokId']);
		$this->assertSame('0363200000406543', $result[0]['bagAddressId']);
		$this->assertSame('Point', $result[0]['location']['type']);
		$this->assertSame([4.88525, 52.37025], $result[0]['location']['coordinates']);
		$this->assertSame('pdok', $result[0]['source']);

		// Assert outbound request.
		$this->assertCount(1, $this->history);
		$request = $this->history[0]['request'];
		$this->assertSame('GET', $request->getMethod());
		$this->assertStringStartsWith('https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest', (string)$request->getUri());
		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame('lauriergracht', $query['q']);
		$this->assertSame('5', $query['rows']);
	}//end testSuggestIssuesGetRequestAndNormalisesDocs()

	/**
	 * @return void
	 */
	public function testSuggestClampsRowsToSafeBounds(): void {
		$client = $this->buildClient(
			[new Response(200, [], json_encode(['response' => ['docs' => []]]))]
		);

		$client->suggest('q', 999);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('50', $query['rows']);
	}//end testSuggestClampsRowsToSafeBounds()

	/**
	 * @return void
	 */
	public function testLookupReturnsSingleNormalisedEntry(): void {
		$payload = [
			'response' => [
				'docs' => [
					$this->lauriergrachtDoc('adr-target', 'Lauriergracht 37'),
				],
			],
		];

		$client = $this->buildClient(
			[new Response(200, [], json_encode($payload))]
		);

		$result = $client->lookup('adr-target');

		$this->assertIsArray($result);
		$this->assertSame('adr-target', $result['pdokId']);
		$this->assertSame('Lauriergracht 37', $result['displayName']);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('adr-target', $query['id']);
	}//end testLookupReturnsSingleNormalisedEntry()

	/**
	 * @return void
	 */
	public function testLookupReturnsNullWhenNoDocs(): void {
		$client = $this->buildClient(
			[new Response(200, [], json_encode(['response' => ['docs' => []]]))]
		);

		$this->assertNull($client->lookup('missing'));
	}//end testLookupReturnsNullWhenNoDocs()

	/**
	 * @return void
	 */
	public function testReverseIssuesLatLngQuery(): void {
		$payload = [
			'response' => [
				'docs' => [
					$this->lauriergrachtDoc('adr-2', 'Lauriergracht'),
				],
			],
		];

		$client = $this->buildClient(
			[new Response(200, [], json_encode($payload))]
		);

		$result = $client->reverse(52.37025, 4.88525);

		$this->assertCount(1, $result);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('52.37025', $query['lat']);
		$this->assertSame('4.88525', $query['lon']);
	}//end testReverseIssuesLatLngQuery()

	/**
	 * @return void
	 */
	public function testTransportFailureReturnsEmptyResult(): void {
		// 500 with no JSON body triggers the decode-failure branch.
		$client = $this->buildClient(
			[new Response(500, [], 'Service Unavailable')]
		);

		$this->assertSame([], $client->suggest('q'));
	}//end testTransportFailureReturnsEmptyResult()

	/**
	 * @return void
	 */
	public function testInvalidCentroidLlIsDropped(): void {
		$payload = [
			'response' => [
				'docs' => [
					[
						'id' => 'adr-no-coords',
						'weergavenaam' => 'Geen coords',
						'centroide_ll' => 'INVALID',
					],
				],
			],
		];

		$client = $this->buildClient(
			[new Response(200, [], json_encode($payload))]
		);

		$result = $client->suggest('q');

		$this->assertArrayNotHasKey('location', $result[0]);
		$this->assertSame('adr-no-coords', $result[0]['pdokId']);
	}//end testInvalidCentroidLlIsDropped()

}//end class
