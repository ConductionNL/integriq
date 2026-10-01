<?php

/**
 * Unit tests for EndpointCorsPolicy.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * REQ-EP-014: the headers an endpoint's own CORS policy answers.
 */
class EndpointCorsPolicyTest extends TestCase {

	/**
	 * The headers for one endpoint on an instance with the given overwrite.cli.url.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param string               $cliUrl       The instance's overwrite.cli.url.
	 *
	 * @return array<string, string>|null
	 */
	private function headers(array $endpointData, string $cliUrl='https://raad.example.nl'): ?array {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default='') => ($key === 'overwrite.cli.url' ? $cliUrl : $default)
		);
		$endpoint = new ObjectEntity();
		$endpoint->setObject($endpointData);

		return (new EndpointCorsPolicy($config))->headersFor($endpoint);
	}//end headers()

	/**
	 * No policy, no headers: the caller keeps integriq's default.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutAPolicyAnswersNone(): void {
		$this->assertNull($this->headers(['method' => 'GET']));
		$this->assertNull($this->headers(['cors' => []]));
		$this->assertNull((new EndpointCorsPolicy($this->createMock(IConfig::class)))->headersFor(null));
	}//end testAnEndpointWithoutAPolicyAnswersNone()

	/**
	 * `self` is the instance's own origin: scheme, host and port of
	 * overwrite.cli.url, without its path (an Origin never has one).
	 *
	 * @return void
	 */
	public function testSelfIsTheInstancesOwnOrigin(): void {
		$cors = ['allowedOrigin' => 'self', 'allowedMethods' => ['get', 'options']];
		$this->assertSame(
			[
				'Access-Control-Allow-Origin' => 'https://raad.example.nl:8443',
				'Access-Control-Allow-Methods' => 'GET, OPTIONS',
				'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With',
				'Access-Control-Allow-Credentials' => 'false',
				'Vary' => 'Origin',
			],
			$this->headers(['cors' => $cors], 'https://raad.example.nl:8443/nextcloud/index.php')
		);
	}//end testSelfIsTheInstancesOwnOrigin()

	/**
	 * Without overwrite.cli.url `self` falls back to the wildcard, as
	 * decidiq's OriController::applyCorsHeaders() does.
	 *
	 * @return void
	 */
	public function testSelfWithoutAnInstanceUrlIsTheWildcard(): void {
		$headers = $this->headers(['cors' => ['allowedOrigin' => 'self']], '');
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
	}//end testSelfWithoutAnInstanceUrlIsTheWildcard()

	/**
	 * A named origin or the wildcard is answered as written.
	 *
	 * @return void
	 */
	public function testANamedOriginIsAnsweredAsWritten(): void {
		$headers = $this->headers(['cors' => ['allowedOrigin' => 'https://portaal.example.nl', 'allowedHeaders' => ['Accept']]]);
		$this->assertSame('https://portaal.example.nl', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('Accept', $headers['Access-Control-Allow-Headers']);

		$this->assertSame('*', $this->headers(['cors' => ['allowedOrigin' => '*']])['Access-Control-Allow-Origin']);
	}//end testANamedOriginIsAnsweredAsWritten()
}//end class
