<?php

/**
 * Golden tests for PdokWfsClientHttp::getFeature(): the exact query, the
 * bbox and filter rules, and the unanswered collection with its log line.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Pdok
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

namespace OCA\Integriq\Tests\Unit\Adapters\Pdok;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the GetFeature request and answer.
 */
class PdokWfsGetFeatureGoldenTest extends TestCase {

	/**
	 * Requests sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $history = [];

	/**
	 * A client answering with these responses.
	 *
	 * @param list<Response>       $responses The responses.
	 * @param LoggerInterface|null $logger    The logger.
	 *
	 * @return PdokWfsClientHttp
	 */
	private function client(array $responses, ?LoggerInterface $logger = null): PdokWfsClientHttp {
		$stack = HandlerStack::create(new MockHandler($responses));
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new PdokWfsClientHttp(
			httpClient: new Client(['handler' => $stack]),
			logger: $logger ?? $this->createMock(LoggerInterface::class),
			baseUri: 'https://pdok.test/{dataset}/wfs'
		);

	}//end client()

	/**
	 * The full query of a call with bbox and two filter fields, in order.
	 *
	 * @return void
	 */
	public function testFullQueryIsUnchanged(): void {
		$client = $this->client([new Response(200, [], '{"type":"FeatureCollection","features":[]}')]);

		$result = $client->getFeature('bag x', 'bag:pand', ['1', 2.5, 3, '4.0'], 0, 'EPSG:4326', ['a' => 'x&y', 'b' => '"q"']);

		$this->assertSame(['type' => 'FeatureCollection', 'features' => []], $result);
		$request = $this->history[0]['request'];
		$this->assertSame('/bag%20x/wfs', $request->getUri()->getPath());
		$this->assertSame('application/json', $request->getHeaderLine('Accept'));
		parse_str($request->getUri()->getQuery(), $query);
		$this->assertSame(
			[
				'service' => 'WFS',
				'request' => 'GetFeature',
				'version' => '2.0.0',
				'typeNames' => 'bag:pand',
				'outputFormat' => 'application/json',
				'count' => '1',
				'srsName' => 'EPSG:4326',
				'bbox' => '1,2.5,3,4,EPSG:4326',
				'filter' => '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:And>'
					. '<fes:PropertyIsEqualTo xmlns:fes="http://www.opengis.net/fes/2.0"><fes:ValueReference>a</fes:ValueReference><fes:Literal>x&amp;y</fes:Literal></fes:PropertyIsEqualTo>'
					. '<fes:PropertyIsEqualTo xmlns:fes="http://www.opengis.net/fes/2.0"><fes:ValueReference>b</fes:ValueReference><fes:Literal>&quot;q&quot;</fes:Literal></fes:PropertyIsEqualTo>'
					. '</fes:And></fes:Filter>',
			],
			$query
		);

	}//end testFullQueryIsUnchanged()

	/**
	 * A bbox that is not four numbers is left out.
	 *
	 * @return void
	 */
	public function testShortBboxIsLeftOut(): void {
		$client = $this->client([new Response(200, [], '[]')]);

		$this->assertSame([], $client->getFeature('bag', 'bag:pand', [1, 2, 3]));
		parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
		$this->assertSame(['service', 'request', 'version', 'typeNames', 'outputFormat', 'count', 'srsName'], array_keys($query));
		$this->assertSame(['10', 'EPSG:28992'], [$query['count'], $query['srsName']]);

	}//end testShortBboxIsLeftOut()

	/**
	 * An answer that is no JSON array is an unanswered empty collection, logged with its size.
	 *
	 * @return void
	 */
	public function testUndecodableAnswerIsUnanswered(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'pdok-wfs.http.decode',
			['dataset' => 'bag', 'featureType' => 'bag:pand', 'bodySize' => 5]
		);
		$client = $this->client([new Response(200, [], '"str"')], $logger);

		$this->assertSame(
			['type' => 'FeatureCollection', 'features' => [], 'totalFeatures' => 0, 'numberMatched' => 0, 'numberReturned' => 0, 'unanswered' => true],
			$client->getFeature('bag', 'bag:pand')
		);

	}//end testUndecodableAnswerIsUnanswered()
}//end class
