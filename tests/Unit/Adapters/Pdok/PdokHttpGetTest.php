<?php

/**
 * Unit tests for PdokHttpGet, the GET the PDOK WFS and WMS clients share.
 *
 * Pins the request it sends for a PdokGetRequest (method, uri with the
 * dataset interpolated, query, timeout, Accept header), the body it returns,
 * and what it logs and returns when the transport fails.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Pdok;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Pdok\PdokGetRequest;
use OCA\Integriq\Adapters\Pdok\PdokHttpGet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The shared PDOK GET.
 *
 * @covers \OCA\Integriq\Adapters\Pdok\PdokHttpGet
 * @covers \OCA\Integriq\Adapters\Pdok\PdokGetRequest
 */
class PdokHttpGetTest extends TestCase {

	/**
	 * The base URI with a dataset placeholder, as the clients configure it.
	 *
	 * @var string
	 */
	private const BASE = 'https://service.pdok.nl/{dataset}/wfs/v2_0';

	/**
	 * The dataset is URL-encoded into the base URI, and the query, timeout and
	 * Accept header of the request are sent as given; the body comes back as text.
	 *
	 * @return void
	 */
	public function testTheRequestIsSentAsDescribedAndTheBodyReturned(): void {
		$query = ['service' => 'WFS', 'request' => 'GetFeature', 'typeNames' => 'bag:pand'];

		$http = $this->createMock(originalClassName: ClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->with(
				'GET',
				'https://service.pdok.nl/lv%2Fbag%20v2/wfs/v2_0',
				['query' => $query, 'timeout' => 15, 'headers' => ['Accept' => 'application/json']]
			)
			->willReturn(new Response(status: 200, body: '{"features":[]}'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$get = new PdokHttpGet(httpClient: $http, logger: $logger, baseUri: self::BASE, failureEvent: 'pdok-wfs.http.transport');

		$this->assertSame(
			expected: '{"features":[]}',
			actual: $get->body(request: new PdokGetRequest(dataset: 'lv/bag v2', query: $query, expectedAccept: 'application/json'))
		);
	}//end testTheRequestIsSentAsDescribedAndTheBodyReturned()

	/**
	 * A base URI without the placeholder is used as it is.
	 *
	 * @return void
	 */
	public function testABaseUriWithoutPlaceholderIsUsedAsIs(): void {
		$http = $this->createMock(originalClassName: ClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->with('GET', 'https://example.test/wms', $this->anything())
			->willReturn(new Response(status: 200, body: 'png'));

		$get = new PdokHttpGet(
			httpClient: $http,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			baseUri: 'https://example.test/wms',
			failureEvent: 'pdok-wms.http.transport'
		);

		$this->assertSame(expected: 'png', actual: $get->body(request: new PdokGetRequest(dataset: 'ignored', query: [], expectedAccept: 'image/png')));
	}//end testABaseUriWithoutPlaceholderIsUsedAsIs()

	/**
	 * A transport failure is logged under the configured event with the uri,
	 * dataset, protocol request and message, and yields an empty body.
	 *
	 * @return void
	 */
	public function testATransportFailureIsLoggedAndYieldsAnEmptyBody(): void {
		$http = $this->createMock(originalClassName: ClientInterface::class);
		$http->method('request')->willThrowException(
			new ConnectException(message: 'Connection refused', request: new Request(method: 'GET', uri: 'https://service.pdok.nl'))
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'pdok-wms.http.transport',
				[
					'uri' => 'https://service.pdok.nl/kadaster/wfs/v2_0',
					'dataset' => 'kadaster',
					'request' => 'GetMap',
					'message' => 'Connection refused',
				]
			);

		$get = new PdokHttpGet(httpClient: $http, logger: $logger, baseUri: self::BASE, failureEvent: 'pdok-wms.http.transport');

		$this->assertSame(
			expected: '',
			actual: $get->body(request: new PdokGetRequest(dataset: 'kadaster', query: ['request' => 'GetMap'], expectedAccept: 'image/png'))
		);
	}//end testATransportFailureIsLoggedAndYieldsAnEmptyBody()

	/**
	 * Without a `request` parameter the log names none.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAProtocolRequestLogsNull(): void {
		$http = $this->createMock(originalClassName: ClientInterface::class);
		$http->method('request')->willThrowException(
			new ConnectException(message: 'timeout', request: new Request(method: 'GET', uri: 'https://service.pdok.nl'))
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'pdok-wfs.http.transport',
				$this->callback(callback: static fn (array $context): bool => array_key_exists('request', $context) === true && $context['request'] === null)
			);

		$get = new PdokHttpGet(httpClient: $http, logger: $logger, baseUri: self::BASE, failureEvent: 'pdok-wfs.http.transport');

		$this->assertSame(expected: '', actual: $get->body(request: new PdokGetRequest(dataset: 'x', query: [], expectedAccept: 'application/json')));
	}//end testAFailureWithoutAProtocolRequestLogsNull()

	/**
	 * Only transport failures are absorbed: any other error reaches the caller.
	 *
	 * @return void
	 */
	public function testANonTransportErrorIsNotAbsorbed(): void {
		$http = $this->createMock(originalClassName: ClientInterface::class);
		$http->method('request')->willThrowException(new RuntimeException(message: 'bug'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$get = new PdokHttpGet(httpClient: $http, logger: $logger, baseUri: self::BASE, failureEvent: 'pdok-wfs.http.transport');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'bug');
		$get->body(request: new PdokGetRequest(dataset: 'x', query: [], expectedAccept: 'application/json'));
	}//end testANonTransportErrorIsNotAbsorbed()
}//end class
