<?php

/**
 * Contract test: the BAG object read against the answers the PDOK clients ship and PDOK gives.
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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientMock;
use OCA\Integriq\PropertySource\Provider\BagObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Two kinds of double, both real classes: the mock flavours integriq ships
 * (the contract), and the HTTP flavours over a Guzzle handler that answers
 * what PDOK answered on 14 Oct 2026 (the shapes).
 *
 * @covers \OCA\Integriq\PropertySource\Provider\BagObjectReader
 * @uses \OCA\Integriq\Adapters\Pdok\PdokGeocodingClient
 * @uses \OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp
 * @uses \OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock
 * @uses \OCA\Integriq\Adapters\Pdok\PdokWfsClient
 * @uses \OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp
 * @uses \OCA\Integriq\Adapters\Pdok\PdokWfsClientMock
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
 */
class BagObjectReaderTest extends TestCase {
	/**
	 * Requests the HTTP clients sent.
	 *
	 * @var array<int,array{request:Request}>
	 */
	private array $history = [];

	/**
	 * A reader over the mock flavours integriq ships.
	 *
	 * @return BagObjectReader
	 */
	private function shippedReader(): BagObjectReader {
		return new BagObjectReader(new PdokWfsClientMock(), new PdokGeocodingClientMock(), new NullLogger());
	}//end shippedReader()

	/**
	 * A reader over the HTTP flavours, each answering from its own queue.
	 *
	 * @param array<int,mixed> $wfsAnswers Responses or exceptions for the WFS.
	 * @param array<int,mixed> $locatieserverAnswers Responses or exceptions for the Locatieserver.
	 *
	 * @return BagObjectReader
	 */
	private function httpReader(array $wfsAnswers, array $locatieserverAnswers): BagObjectReader {
		$this->history = [];

		$wfsStack = HandlerStack::create(new MockHandler($wfsAnswers));
		$wfsStack->push(Middleware::history($this->history));
		$geoStack = HandlerStack::create(new MockHandler($locatieserverAnswers));
		$geoStack->push(Middleware::history($this->history));

		return new BagObjectReader(
			new PdokWfsClientHttp(new Client(['handler' => $wfsStack]), new NullLogger()),
			new PdokGeocodingClientHttp(new Client(['handler' => $geoStack]), new NullLogger()),
			new NullLogger()
		);
	}//end httpReader()

	/**
	 * A JSON response.
	 *
	 * @param array<string,mixed> $body The body.
	 *
	 * @return Response
	 */
	private static function json(array $body): Response {
		return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($body));
	}//end json()

	/**
	 * The verblijfsobject PDOK's BAG WFS answered for 0363010000758545.
	 *
	 * @return array<string,mixed>
	 */
	private static function pdokVerblijfsobject(): array {
		return [
			'type' => 'FeatureCollection',
			'name' => 'verblijfsobject',
			'features' => [
				[
					'type' => 'Feature',
					'id' => 'verblijfsobject.3553c306-7899-4576-bcf7-93b73425f9c8',
					'properties' => [
						'identificatie' => '0363010000758545',
						'status' => 'Verblijfsobject in gebruik',
						'gebruiksdoel' => 'bijeenkomstfunctie',
						'openbare_ruimte' => 'Nieuwezijds Voorburgwal',
						'huisnummer' => 147,
						'postcode' => '1012RJ',
						'woonplaats' => 'Amsterdam',
						'pandidentificatie' => '0363100012167579',
					],
					'geometry' => ['type' => 'Point', 'coordinates' => [121202.0, 487370.0]],
				],
			],
		];
	}//end pdokVerblijfsobject()

	/**
	 * The shipped WFS mock answers its canned verblijfsobject, and the reader finds it.
	 *
	 * @return void
	 */
	public function testShippedMockVerblijfsobjectIsFoundAndMarkedSimulated(): void {
		$answer = $this->shippedReader()->read('verblijfsobject', '0363010000406543');

		$this->assertTrue($answer['available']);
		$this->assertTrue($answer['found']);
		$this->assertTrue($answer['simulated']);
		$this->assertSame('0363010000406543', $answer['object']['identificatie']);
		$this->assertSame('Verblijfsobject in gebruik', $answer['object']['attributes']['status']);
	}//end testShippedMockVerblijfsobjectIsFoundAndMarkedSimulated()

	/**
	 * The shipped Locatieserver mock answers its canned address, and the reader finds its nummeraanduiding.
	 *
	 * @return void
	 */
	public function testShippedMockNummeraanduidingIsFound(): void {
		$answer = $this->shippedReader()->read('nummeraanduiding', '0363200000406543');

		$this->assertTrue($answer['available']);
		$this->assertTrue($answer['found']);
		$this->assertTrue($answer['simulated']);
		$this->assertSame('Lauriergracht 37, 1016 RG Amsterdam', $answer['object']['attributes']['displayName']);
	}//end testShippedMockNummeraanduidingIsFound()

	/**
	 * The shipped mocks answer one canned object for any question. Asked about
	 * another object, that is an answer about something else, not a find.
	 *
	 * @return void
	 */
	public function testShippedMockAnswerAboutAnotherObjectIsUnclear(): void {
		$pand = $this->shippedReader()->read('pand', '0363100012180043');
		$address = $this->shippedReader()->read('nummeraanduiding', '0363200000218908');

		foreach ([$pand, $address] as $answer) {
			$this->assertFalse($answer['available']);
			$this->assertFalse($answer['found']);
			$this->assertSame(BagObjectReader::REASON_UNCLEAR, $answer['reason']);
		}
	}//end testShippedMockAnswerAboutAnotherObjectIsUnclear()

	/**
	 * A verblijfsobject PDOK holds is found, through a filter on identificatie.
	 *
	 * @return void
	 */
	public function testPdokVerblijfsobjectIsFoundThroughAnIdentificatieFilter(): void {
		$answer = $this->httpReader([self::json(self::pdokVerblijfsobject())], [])->read('verblijfsobject', '0363010000758545');

		$this->assertTrue($answer['available']);
		$this->assertTrue($answer['found']);
		$this->assertFalse($answer['simulated']);
		$this->assertSame('0363100012167579', $answer['object']['attributes']['pandidentificatie']);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('bag:verblijfsobject', $query['typeNames']);
		$this->assertStringContainsString('<fes:ValueReference>identificatie</fes:ValueReference>', (string)$query['filter']);
		$this->assertStringContainsString('<fes:Literal>0363010000758545</fes:Literal>', (string)$query['filter']);
	}//end testPdokVerblijfsobjectIsFoundThroughAnIdentificatieFilter()

	/**
	 * An empty collection from PDOK is the BAG saying it holds no such pand.
	 *
	 * @return void
	 */
	public function testAnEmptyPdokCollectionIsNotFound(): void {
		$empty = ['type' => 'FeatureCollection', 'name' => 'pand', 'features' => []];
		$answer = $this->httpReader([self::json($empty)], [])->read('pand', '0363109999999999');

		$this->assertTrue($answer['available']);
		$this->assertFalse($answer['found']);
		$this->assertNull($answer['object']);
	}//end testAnEmptyPdokCollectionIsNotFound()

	/**
	 * A WFS that does not answer is not an empty register.
	 *
	 * @return void
	 */
	public function testAWfsThatDoesNotAnswerIsUnreachableNotNotFound(): void {
		$down = new ConnectException('timed out', new Request('GET', 'https://service.pdok.nl/lv/bag/wfs/v2_0'));
		$garbled = new Response(502, [], '<html>Bad gateway</html>');

		foreach ([$down, $garbled] as $failure) {
			$answer = $this->httpReader([$failure], [])->read('pand', '0363100012167579');

			$this->assertFalse($answer['available']);
			$this->assertFalse($answer['found']);
			$this->assertSame(BagObjectReader::REASON_UNREACHABLE, $answer['reason']);
		}
	}//end testAWfsThatDoesNotAnswerIsUnreachableNotNotFound()

	/**
	 * A nummeraanduiding is asked of the Locatieserver by its field, and found.
	 *
	 * @return void
	 */
	public function testPdokNummeraanduidingIsFound(): void {
		$doc = [
			'id' => 'adr-563f90756e46d1c554b4ab00ac61b932',
			'weergavenaam' => 'Nieuwezijds Voorburgwal 147, 1012RJ Amsterdam',
			'nummeraanduiding_id' => '0363200000218908',
			'adresseerbaarobject_id' => '0363010000758545',
		];
		$answer = $this->httpReader([], [self::json(['response' => ['numFound' => 1, 'docs' => [$doc]]])])
			->read('nummeraanduiding', '0363200000218908');

		$this->assertTrue($answer['available']);
		$this->assertTrue($answer['found']);
		$this->assertSame('0363010000758545', $answer['object']['attributes']['bagBuildingId']);

		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame('nummeraanduiding_id:0363200000218908', $query['q']);
	}//end testPdokNummeraanduidingIsFound()

	/**
	 * Zero docs from the Locatieserver is not found; no answer is unreachable.
	 *
	 * @return void
	 */
	public function testLocatieserverNotFoundAndNoAnswerAreTwoThings(): void {
		$none = $this->httpReader([], [self::json(['response' => ['numFound' => 0, 'docs' => []]])])
			->read('nummeraanduiding', '0363200000999999');
		$this->assertTrue($none['available']);
		$this->assertFalse($none['found']);

		$down = new ConnectException('refused', new Request('GET', 'https://api.pdok.nl/'));
		$unreachable = $this->httpReader([], [$down])->read('nummeraanduiding', '0363200000999999');
		$this->assertFalse($unreachable['available']);
		$this->assertSame(BagObjectReader::REASON_UNREACHABLE, $unreachable['reason']);
	}//end testLocatieserverNotFoundAndNoAnswerAreTwoThings()

	/**
	 * An identificatie of the wrong shape or type, or an unknown type, is not asked.
	 *
	 * @return void
	 */
	public function testAMalformedQuestionIsNotAsked(): void {
		$reader = $this->httpReader([], []);

		$this->assertSame(BagObjectReader::REASON_INVALID_IDENTIFIER, $reader->read('pand', '12345')['reason']);
		// A nummeraanduiding id asked as a pand.
		$this->assertSame(BagObjectReader::REASON_INVALID_IDENTIFIER, $reader->read('pand', '0363200000218908')['reason']);
		$this->assertSame(BagObjectReader::REASON_UNKNOWN_TYPE, $reader->read('ligplaats', '0363030000000001')['reason']);
		$this->assertSame([], $this->history);
	}//end testAMalformedQuestionIsNotAsked()

	/**
	 * A client that throws is an unanswered read, never "not found".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
	 */
	public function testAClientThatThrowsIsUnreachable(): void {
		$wfs = new class extends PdokWfsClient {
			public function getCapabilities(string $dataset): string {
				throw new RuntimeException('down');
			}

			public function describeFeatureType(string $dataset, string $featureType): string {
				throw new RuntimeException('down');
			}

			public function getFeature(string $dataset, string $featureType, ?array $bbox = null, int $count = 10, string $srsName = 'EPSG:28992', array $filterFields = []): array {
				throw new RuntimeException('down');
			}

			public function flavour(): string {
				return 'http';
			}
		};
		$geocoding = new class extends PdokGeocodingClient {
			public function suggest(string $query, int $rows = 10): array {
				throw new RuntimeException('down');
			}

			public function find(string $query, int $rows = 10): ?array {
				throw new RuntimeException('down');
			}

			public function lookup(string $pdokId): ?array {
				throw new RuntimeException('down');
			}

			public function reverse(float $latitude, float $longitude): array {
				throw new RuntimeException('down');
			}

			public function flavour(): string {
				return 'http';
			}
		};
		$reader = new BagObjectReader($wfs, $geocoding, new NullLogger());

		foreach ([['pand', '0363100012167579'], ['verblijfsobject', '0363010000406543'], ['nummeraanduiding', '0363200000406543']] as [$type, $id]) {
			$answer = $reader->read($type, $id);

			$this->assertFalse($answer['available'], $type);
			$this->assertFalse($answer['found'], $type);
			$this->assertSame(BagObjectReader::REASON_UNREACHABLE, $answer['reason'], $type);
			$this->assertFalse($answer['simulated'], $type);
		}
	}//end testAClientThatThrowsIsUnreachable()

	/**
	 * The shipped mock's find answers its canned address.
	 *
	 * @return void
	 */
	public function testTheShippedMockFindAnswersItsCannedAddress(): void {
		$found = (new PdokGeocodingClientMock())->find('nummeraanduiding_id:0363200000406543');

		$this->assertCount(1, $found);
		$this->assertArrayHasKey('bagAddressId', $found[0]);
	}//end testTheShippedMockFindAnswersItsCannedAddress()

}//end class
