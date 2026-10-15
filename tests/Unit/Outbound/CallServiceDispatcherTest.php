<?php

/**
 * Call service dispatcher test: the endpoint of a replayed record (iq-f4).
 *
 * A stored call record keeps `url` and `method`, not `endpoint`, so the
 * dispatcher must turn the stored URL back into an endpoint under the source,
 * and refuse a URL it cannot trust.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Exception\CallDispatchException;
use OCA\Integriq\Outbound\Call\CallServiceDispatcher;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

class CallServiceDispatcherTest extends TestCase {

	/**
	 * The endpoints the engine was asked to call.
	 *
	 * @var array<int,string>
	 */
	private array $endpoints = [];

	/**
	 * Build the dispatcher over a source at `https://zaken.example.nl/api`.
	 *
	 * @return CallServiceDispatcher
	 */
	private function dispatcher(): CallServiceDispatcher {
		$this->endpoints = [];
		$objects = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://zaken.example.nl/api/'], 'source-1')
		);

		$callService = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->getMock();
		$callService->method('call')->willReturnCallback(
			function (...$args) {
				$this->endpoints[] = (string)($args['endpoint'] ?? $args[1]);
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'statusMessage' => 'OK', 'response' => ['body' => 'ok']], 'log-1');
			}
		);

		return new CallServiceDispatcher($callService, $objects);
	}//end dispatcher()

	/**
	 * An `endpoint` is used as it is; a stored `url` under the source's address becomes its endpoint.
	 *
	 * @return void
	 */
	public function testAStoredUrlUnderTheSourceBecomesTheEndpoint(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch('source-1', ['method' => 'GET', 'endpoint' => '/zaken']);
		$dispatcher->dispatch('source-1', ['method' => 'GET', 'url' => 'https://zaken.example.nl/api/zaken/1?x=2']);

		$this->assertSame(['/zaken', '/zaken/1?x=2'], $this->endpoints);
	}//end testAStoredUrlUnderTheSourceBecomesTheEndpoint()

	/**
	 * A URL outside the source's address, one cut inside a path segment, or one with a redacted part is refused.
	 *
	 * @return void
	 */
	public function testAUrlItCannotTrustIsRefused(): void {
		$dispatcher = $this->dispatcher();

		foreach (['https://other.example.nl/api/zaken', 'https://zaken.example.nl/apiary/zaken', 'https://zaken.example.nl/api/zaken?key=***REDACTED***'] as $url) {
			try {
				$dispatcher->dispatch('source-1', ['method' => 'GET', 'url' => $url]);
				$this->fail('Expected a refusal for ' . $url);
			} catch (CallDispatchException $exception) {
				$this->assertStringContainsString('cannot be replayed', $exception->getMessage());
			}
		}

		$this->assertSame([], $this->endpoints, 'nothing was called');
	}//end testAUrlItCannotTrustIsRefused()
}//end class
