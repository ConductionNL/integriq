<?php

/**
 * Unit tests: a proxied endpoint answers without the upstream's secret headers or address.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointProxiedAnswer;
use PHPUnit\Framework\TestCase;

/**
 * What an inbound caller of a proxied endpoint may receive of the upstream's answer.
 */
class EndpointProxiedAnswerTest extends TestCase {

	/**
	 * The upstream answer as CallService hands it back.
	 *
	 * @return array<string,mixed>
	 */
	private function upstream(): array {
		return [
			'statusCode' => 200,
			'statusMessage' => 'OK',
			'responseTime' => 12.5,
			'size' => 11,
			'remoteIp' => '10.0.4.17',
			'headers' => [
				'Content-Type' => ['application/json'],
				'content-disposition' => ['attachment; filename="besluit.pdf"'],
				'Link' => ['<https://upstream.example.invalid/items?page=2>; rel="next"'],
				'X-Total-Count' => ['42'],
				'ETag' => ['"abc"'],
				'Set-Cookie' => ['session=abc123secret'],
				'Authorization' => ['Bearer upstream-token'],
				'WWW-Authenticate' => ['Bearer realm="upstream"'],
				'Server' => ['nginx/1.25.3'],
				'X-Powered-By' => ['PHP/8.3.1'],
				'X-Upstream-Internal' => ['node-7'],
			],
			'body' => '{"ok":true}',
			'encoding' => 'UTF-8',
		];
	}//end upstream()

	/**
	 * Content and pagination headers pass; secrets, challenges and server internals do not.
	 *
	 * @return void
	 */
	public function testOnlyContentAndPaginationHeadersPass(): void {
		$answer = (new EndpointProxiedAnswer())->forCaller(response: $this->upstream());

		$this->assertSame(
			[
				'Content-Type' => ['application/json'],
				'content-disposition' => ['attachment; filename="besluit.pdf"'],
				'Link' => ['<https://upstream.example.invalid/items?page=2>; rel="next"'],
				'X-Total-Count' => ['42'],
				'ETag' => ['"abc"'],
			],
			$answer['headers']
		);
	}//end testOnlyContentAndPaginationHeadersPass()

	/**
	 * The upstream's address is not handed out; the body and status stay as they were.
	 *
	 * @return void
	 */
	public function testTheUpstreamAddressIsDroppedAndTheRestKept(): void {
		$answer = (new EndpointProxiedAnswer())->forCaller(response: $this->upstream());

		$this->assertArrayNotHasKey('remoteIp', $answer);
		$expected = $this->upstream();
		unset($expected['remoteIp'], $expected['headers']);
		unset($answer['headers']);
		$this->assertSame($expected, $answer);
	}//end testTheUpstreamAddressIsDroppedAndTheRestKept()

	/**
	 * An answer without headers passes unchanged apart from the address.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutHeadersIsLeftAlone(): void {
		$this->assertSame(['statusCode' => 204], (new EndpointProxiedAnswer())->forCaller(response: ['statusCode' => 204]));
	}//end testAnAnswerWithoutHeadersIsLeftAlone()
}//end class
