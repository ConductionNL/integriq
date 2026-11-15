<?php

/**
 * Characterisation tests for PdokGeocodingClientHttp: request options, log lines and normalised shape.
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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The request options, the log lines and the exact normalised shape of the Locatieserver client.
 */
class PdokGeocodingClientHttpShapeTest extends TestCase {

	private const BASE = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/';

	/**
	 * Captured outbound requests.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $history = [];

	/**
	 * Log lines written, as [level, message, context].
	 *
	 * @var array<int,array{0:mixed,1:string,2:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * A client answering the given responses and recording requests and log lines.
	 *
	 * @param array<int,mixed> $responses Canned responses or exceptions.
	 *
	 * @return PdokGeocodingClientHttp
	 */
	private function client(array $responses): PdokGeocodingClientHttp {
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));
		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int,mixed> $logged Receives the lines.
			 */
			public function __construct(private array &$logged) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logged[] = [$level, (string)$message, $context];
			}
		};

		return new PdokGeocodingClientHttp(httpClient: new Client(['handler' => $stack]), logger: $logger, baseUri: self::BASE);
	}//end client()

	/**
	 * Every request is a GET with a JSON Accept header and the default timeout.
	 *
	 * @return void
	 */
	public function testEveryRequestAsksForJsonWithinTheTimeout(): void {
		$this->client([new Response(200, [], '{"response":{"docs":[]}}')])->reverse(52.1, 4.2);

		$this->assertCount(1, $this->history);
		/** @var Request $request */
		$request = $this->history[0]['request'];
		$this->assertSame('GET', $request->getMethod());
		$this->assertSame('application/json', $request->getHeaderLine('Accept'));
		$this->assertSame(PdokGeocodingClientHttp::DEFAULT_TIMEOUT, $this->history[0]['options']['timeout']);
		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame(['lat' => '52.1', 'lon' => '4.2', 'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,provincienaam,centroide_ll', 'rows' => '10'], $query);
		$this->assertSame(self::BASE . 'reverse', (string)$request->getUri()->withQuery(''));
		$this->assertSame([], $this->logged);
	}//end testEveryRequestAsksForJsonWithinTheTimeout()

	/**
	 * A transport failure is logged as an error with the uri, query and message, and answers nothing.
	 *
	 * @return void
	 */
	public function testATransportFailureIsLoggedWithItsRequest(): void {
		$client = $this->client([new ConnectException('refused', new Request('GET', self::BASE . 'suggest'))]);

		$this->assertNull($client->find('Lauriergracht', 3));
		$this->assertSame(
			[['error', 'pdok-geocoding.http.transport', ['uri' => self::BASE . 'suggest', 'query' => [
				'q' => 'Lauriergracht',
				'rows' => 3,
				'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,provincienaam,centroide_ll,nummeraanduiding_id,adresseerbaarobject_id',
			], 'message' => 'refused']]],
			$this->logged
		);
	}//end testATransportFailureIsLoggedWithItsRequest()

	/**
	 * A body that is not a JSON object is logged as a warning with its size, and answers nothing.
	 *
	 * @return void
	 */
	public function testAnUndecodableBodyIsLoggedWithItsSize(): void {
		$client = $this->client([new Response(200, [], '"text"')]);

		$this->assertSame([], $client->suggest('x'));
		$this->assertSame([['warning', 'pdok-geocoding.http.decode', ['uri' => self::BASE . 'suggest', 'bodySize' => 6]]], $this->logged);
	}//end testAnUndecodableBodyIsLoggedWithItsSize()

	/**
	 * Entries that are not documents are skipped; a document without ids or a point keeps nulls and no location.
	 *
	 * @return void
	 */
	public function testTheNormalisedShapeOfASparseDocument(): void {
		$body = json_encode(['response' => ['docs' => ['skip', ['huisnummer' => 7, 'centroide_ll' => 'POINT(x y)'], ['id' => 12, 'nummeraanduiding_id' => 34, 'adresseerbaarobject_id' => 56, 'centroide_ll' => 'POINT(4.5 52.25)']]]]);

		foreach (['suggest', 'reverse', 'find'] as $method) {
			$client = $this->client([new Response(200, [], $body)]);
			$result = match ($method) {
				'suggest' => $client->suggest('x'),
				'reverse' => $client->reverse(1.0, 2.0),
				default => $client->find('x'),
			};

			$this->assertSame(
				[
					[
						'displayName' => '', 'streetAddress' => '', 'houseNumber' => '7', 'postalCode' => '', 'addressLocality' => '', 'addressRegion' => '',
						'bagAddressId' => null, 'bagBuildingId' => null, 'pdokId' => null, 'source' => 'pdok',
					],
					[
						'displayName' => '', 'streetAddress' => '', 'houseNumber' => '', 'postalCode' => '', 'addressLocality' => '', 'addressRegion' => '',
						'bagAddressId' => '34', 'bagBuildingId' => '56', 'pdokId' => '12', 'source' => 'pdok',
						'location' => ['type' => 'Point', 'coordinates' => [4.5, 52.25]],
					],
				],
				$result,
				$method
			);
		}
	}//end testTheNormalisedShapeOfASparseDocument()
}//end class
