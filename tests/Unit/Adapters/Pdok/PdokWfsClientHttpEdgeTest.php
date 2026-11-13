<?php

/**
 * Edge-case unit tests for PdokWfsClientHttp.
 *
 * Pins the default dataset URL, the bbox and count rules, the combined and
 * escaped OGC filter, and what a non-JSON GetFeature answer logs.
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
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dataset URL, query rules, filter and decode logging.
 *
 * @covers \OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp
 * @uses   \OCA\Integriq\Adapters\Pdok\PdokHttpGet
 * @uses   \OCA\Integriq\Adapters\Pdok\PdokGetRequest
 */
class PdokWfsClientHttpEdgeTest extends TestCase {

	/**
	 * Requests captured by the history middleware.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * Without a base URI the dataset goes into the default PDOK URL, URL-encoded.
	 *
	 * @return void
	 */
	public function testDefaultBaseUriCarriesTheEncodedDataset(): void {
		foreach ([null, ''] as $baseUri) {
			$this->client(baseUri: $baseUri)->getCapabilities(dataset: 'bag x');

			$uri = end($this->history)['request']->getUri();
			self::assertSame('https://service.pdok.nl/lv/bag%20x/wfs/v2_0', $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath());
		}
	}//end testDefaultBaseUriCarriesTheEncodedDataset()

	/**
	 * A bbox without exactly four numbers is left out; the count is at least 1.
	 *
	 * @return void
	 */
	public function testShortBboxIsOmittedAndCountIsAtLeastOne(): void {
		$this->client()->getFeature(dataset: 'bag', featureType: 'bag:pand', bbox: [1, 2, 3], count: 0);
		$query = $this->lastQuery();

		self::assertArrayNotHasKey('bbox', $query);
		self::assertSame('1', $query['count']);

		$this->client()->getFeature(dataset: 'bag', featureType: 'bag:pand', bbox: [1, 2, 3, 4, 5], count: -5);
		$query = $this->lastQuery();
		self::assertArrayNotHasKey('bbox', $query);
		self::assertSame('1', $query['count']);
	}//end testShortBboxIsOmittedAndCountIsAtLeastOne()

	/**
	 * The bbox values are cast to numbers and end with the requested CRS.
	 *
	 * @return void
	 */
	public function testBboxIsNumericAndEndsWithTheCrs(): void {
		$this->client()->getFeature(dataset: 'bag', featureType: 'bag:pand', bbox: ['120000', '480000.5', 121000, 481000], srsName: 'EPSG:4326');

		self::assertSame('120000,480000.5,121000,481000,EPSG:4326', $this->lastQuery()['bbox']);
		self::assertSame('EPSG:4326', $this->lastQuery()['srsName']);
	}//end testBboxIsNumericAndEndsWithTheCrs()

	/**
	 * Several filter fields are combined under fes:And, with names and values escaped.
	 *
	 * @return void
	 */
	public function testSeveralFilterFieldsAreAndedAndEscaped(): void {
		$this->client()->getFeature(dataset: 'bag', featureType: 'bag:pand', filterFields: ['postcode' => '1016 RG', 'naam' => 'A&B "<x>"']);

		$equals = static fn (string $name, string $value): string => '<fes:PropertyIsEqualTo xmlns:fes="http://www.opengis.net/fes/2.0">'
			. '<fes:ValueReference>' . $name . '</fes:ValueReference><fes:Literal>' . $value . '</fes:Literal></fes:PropertyIsEqualTo>';
		self::assertSame(
			'<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:And>'
				. $equals('postcode', '1016 RG') . $equals('naam', 'A&amp;B &quot;&lt;x&gt;&quot;')
				. '</fes:And></fes:Filter>',
			$this->lastQuery()['filter']
		);
	}//end testSeveralFilterFieldsAreAndedAndEscaped()

	/**
	 * A non-JSON GetFeature answer is logged with its dataset, type and size, and answered as an empty, unanswered collection.
	 *
	 * @return void
	 */
	public function testNonJsonAnswerIsLoggedAndUnanswered(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('pdok-wfs.http.decode', ['dataset' => 'bag', 'featureType' => 'bag:pand', 'bodySize' => 9]);

		$result = $this->client(body: '<xml/>!!!', logger: $logger)->getFeature(dataset: 'bag', featureType: 'bag:pand');

		self::assertSame(
			['type' => 'FeatureCollection', 'features' => [], 'totalFeatures' => 0, 'numberMatched' => 0, 'numberReturned' => 0, 'unanswered' => true],
			$result
		);
	}//end testNonJsonAnswerIsLoggedAndUnanswered()

	/**
	 * The query string of the last request.
	 *
	 * @return array<string, string>
	 */
	private function lastQuery(): array {
		parse_str(end($this->history)['request']->getUri()->getQuery(), $query);

		return $query;
	}//end lastQuery()

	/**
	 * A client answering one scripted body.
	 *
	 * @param string|null          $baseUri The base URI.
	 * @param string               $body    The response body.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return PdokWfsClientHttp
	 */
	private function client(?string $baseUri = 'https://wfs.example/{dataset}', string $body = '{"type":"FeatureCollection","features":[]}', ?LoggerInterface $logger = null): PdokWfsClientHttp {
		$stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
		$stack->push(Middleware::history($this->history));

		return new PdokWfsClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: $logger ?? new NullLogger(),
			baseUri: $baseUri
		);
	}//end client()
}//end class
