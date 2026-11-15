<?php

/**
 * Edge-case unit tests for PdokGeocodingClientHttp.
 *
 * Pins the base URI resolution, the answers for unusable `docs` members,
 * skipped non-array documents and signed centroid coordinates.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Pdok;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Base URI, unusable answers and coordinate parsing.
 *
 * @covers \OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp
 */
class PdokGeocodingClientHttpEdgeTest extends TestCase {

	/**
	 * Requests captured by the history middleware.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * Without a base URI, or with an empty one, the public locatieserver is used.
	 *
	 * @return void
	 */
	public function testDefaultBaseUriWhenNoneOrEmpty(): void {
		foreach ([null, ''] as $baseUri) {
			$this->client(responses: [$this->json(body: ['response' => ['docs' => []]])], baseUri: $baseUri)->suggest(query: 'x');

			self::assertSame(
				'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest',
				$this->requestedPath(),
				var_export($baseUri, true)
			);
		}
	}//end testDefaultBaseUriWhenNoneOrEmpty()

	/**
	 * A custom base URI gets exactly one trailing slash before the path.
	 *
	 * @return void
	 */
	public function testCustomBaseUriIsNormalisedToOneSlash(): void {
		foreach (['https://geo.example/ls', 'https://geo.example/ls//'] as $baseUri) {
			$this->client(responses: [$this->json(body: ['response' => ['docs' => []]])], baseUri: $baseUri)->lookup(pdokId: 'adr-1');

			self::assertSame('https://geo.example/ls/lookup', $this->requestedPath(), $baseUri);
		}
	}//end testCustomBaseUriIsNormalisedToOneSlash()

	/**
	 * find() answers null when the answer has no docs list, or docs that are not a list.
	 *
	 * @return void
	 */
	public function testFindIsNullWithoutADocsList(): void {
		self::assertNull($this->client(responses: [$this->json(body: ['response' => []])])->find(query: 'x'));
		self::assertNull($this->client(responses: [$this->json(body: ['response' => ['docs' => 'none']])])->find(query: 'x'));
		self::assertNull($this->client(responses: [$this->json(body: [])])->find(query: 'x'));
	}//end testFindIsNullWithoutADocsList()

	/**
	 * Documents that are not arrays are skipped; the rest keep their order.
	 *
	 * @return void
	 */
	public function testNonArrayDocumentsAreSkipped(): void {
		$result = $this->client(
			responses: [$this->json(body: ['response' => ['docs' => ['junk', ['id' => 'a', 'weergavenaam' => 'A'], 7, ['id' => 'b', 'weergavenaam' => 'B']]]])]
		)->suggest(query: 'x');

		self::assertSame(['A', 'B'], array_column($result, 'displayName'));
		self::assertSame(['a', 'b'], array_column($result, 'pdokId'));
	}//end testNonArrayDocumentsAreSkipped()

	/**
	 * lookup() answers null when the first document is not an array, even if a later one is.
	 *
	 * @return void
	 */
	public function testLookupIsNullWhenTheFirstDocIsNotAnArray(): void {
		$client = $this->client(responses: [$this->json(body: ['response' => ['docs' => ['junk', ['id' => 'a']]]])]);

		self::assertNull($client->lookup(pdokId: 'a'));
	}//end testLookupIsNullWhenTheFirstDocIsNotAnArray()

	/**
	 * Signed and integer centroid coordinates parse to floats in lon, lat order.
	 *
	 * @return void
	 */
	public function testSignedAndIntegerCentroidCoordinates(): void {
		$result = $this->client(
			responses: [
				$this->json(
					body: [
						'response' => [
							'docs' => [
								['id' => 'n', 'centroide_ll' => 'POINT(-4.5 +52)'],
								['id' => 'l', 'centroide_ll' => 'point(5 53.25)'],
							],
						],
					]
				),
			]
		)->reverse(latitude: 52.0, longitude: 5.0);

		self::assertSame(['type' => 'Point', 'coordinates' => [-4.5, 52.0]], $result[0]['location']);
		self::assertSame(['type' => 'Point', 'coordinates' => [5.0, 53.25]], $result[1]['location']);
	}//end testSignedAndIntegerCentroidCoordinates()

	/**
	 * Numeric identifiers come back as strings; absent ones as null.
	 *
	 * @return void
	 */
	public function testIdentifiersAreStringsOrNull(): void {
		$result = $this->client(
			responses: [$this->json(body: ['response' => ['docs' => [['id' => 12, 'nummeraanduiding_id' => 363200000406543, 'huisnummer' => 37]]]])]
		)->find(query: 'x');

		self::assertSame('12', $result[0]['pdokId']);
		self::assertSame('363200000406543', $result[0]['bagAddressId']);
		self::assertNull($result[0]['bagBuildingId']);
		self::assertSame('37', $result[0]['houseNumber']);
		self::assertArrayNotHasKey('location', $result[0]);
	}//end testIdentifiersAreStringsOrNull()

	/**
	 * The scheme, host and path of the last request.
	 *
	 * @return string
	 */
	private function requestedPath(): string {
		$uri = end($this->history)['request']->getUri();

		return $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath();
	}//end requestedPath()

	/**
	 * A JSON response.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return Response
	 */
	private function json(array $body): Response {
		return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($body));
	}//end json()

	/**
	 * A client over scripted responses.
	 *
	 * @param array<int, Response> $responses The responses.
	 * @param string|null          $baseUri   The base URI.
	 *
	 * @return PdokGeocodingClientHttp
	 */
	private function client(array $responses, ?string $baseUri = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/'): PdokGeocodingClientHttp {
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));

		return new PdokGeocodingClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: new NullLogger(),
			baseUri: $baseUri
		);
	}//end client()
}//end class
