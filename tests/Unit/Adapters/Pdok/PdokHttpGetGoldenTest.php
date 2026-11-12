<?php

/**
 * The PDOK WFS and WMS clients issue their GET the same way and log a failure the same way.
 *
 * Pins the request each client sends (method, interpolated uri, query,
 * timeout and Accept header), the body it returns, and the error it logs and
 * the empty body it returns on a transport failure, so the shared GET can
 * move without changing either.
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
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWmsClientHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the PDOK clients' GET.
 */
class PdokHttpGetGoldenTest extends TestCase {

	/**
	 * Both clients, with the log event each writes on a transport failure and its protocol version.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function clients(): array {
		return [
			'wfs' => ['wfs', 'pdok-wfs.http.transport', '2.0.0'],
			'wms' => ['wms', 'pdok-wms.http.transport', '1.3.0'],
		];

	}//end clients()

	/**
	 * Build one client over a mocked HTTP client and logger.
	 *
	 * @param string          $kind   `wfs` or `wms`.
	 * @param ClientInterface $http   The HTTP client.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return PdokWfsClientHttp|PdokWmsClientHttp
	 */
	private function client(string $kind, ClientInterface $http, LoggerInterface $logger): PdokWfsClientHttp|PdokWmsClientHttp {
		if ($kind === 'wfs') {
			return new PdokWfsClientHttp(httpClient: $http, logger: $logger, baseUri: 'https://pdok.example/{dataset}/wfs');
		}

		return new PdokWmsClientHttp(httpClient: $http, logger: $logger, baseUri: 'https://pdok.example/{dataset}/wms');

	}//end client()

	/**
	 * A GET carries the uri, query, timeout and Accept header, and answers the body.
	 *
	 * @param string $kind    `wfs` or `wms`.
	 * @param string $event   The log event (not written here).
	 * @param string $version The protocol version the client asks for.
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testTheGetIsUnchanged(string $kind, string $event, string $version): void {
		$http = $this->createMock(ClientInterface::class);
		$http->expects($this->once())->method('request')->with(
			'GET',
			'https://pdok.example/a%20b/' . $kind,
			[
				'query' => ['service' => strtoupper($kind), 'request' => 'GetCapabilities', 'version' => $version],
				'timeout' => 15,
				'headers' => ['Accept' => 'application/xml'],
			]
		)->willReturn(new Response(200, [], '<caps/>'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->assertSame('<caps/>', $this->client(kind: $kind, http: $http, logger: $logger)->getCapabilities('a b'));

	}//end testTheGetIsUnchanged()

	/**
	 * A transport failure is logged with its uri, dataset, request and message, and answers ''.
	 *
	 * @param string $kind    `wfs` or `wms`.
	 * @param string $event   The log event.
	 * @param string $version The protocol version (not checked here).
	 *
	 * @return void
	 */
	#[DataProvider('clients')]
	public function testATransportFailureIsLoggedAndEmpty(string $kind, string $event, string $version): void {
		$http = $this->createMock(ClientInterface::class);
		$http->method('request')->willThrowException(new ConnectException('refused', new Request('GET', 'https://pdok.example')));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$event,
			[
				'uri' => 'https://pdok.example/bag/' . $kind,
				'dataset' => 'bag',
				'request' => 'GetCapabilities',
				'message' => 'refused',
			]
		);

		$this->assertSame('', $this->client(kind: $kind, http: $http, logger: $logger)->getCapabilities('bag'));

	}//end testATransportFailureIsLoggedAndEmpty()
}//end class
