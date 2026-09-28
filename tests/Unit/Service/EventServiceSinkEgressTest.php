<?php

/**
 * Push delivery refuses a sink the egress guard refuses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/events-async-api-products/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integriq#2212: `deliverMessage()` posted to the stored sink with no host
 * check, so a sink written straight through the object API onto the metadata
 * address was called. The delivery engine is the egress point, so the guard
 * runs there, not only on the subscribe route. A refused sink is abandoned at
 * once: retrying a refusal only repeats it.
 *
 * The guard is the real one, fed IP literals, so no name is resolved.
 *
 * @spec openspec/changes/events-async-api-products/design.md
 */
class EventServiceSinkEgressTest extends TestCase {

	/**
	 * Build the service with an HTTP client that records every post.
	 *
	 * @param array<int, string> $posted Receives each posted URL, by reference.
	 *
	 * @return EventService
	 */
	private function service(array &$posted): EventService {
		$logger = $this->createMock(LoggerInterface::class);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('ok');
		$response->method('getHeader')->willReturn('');

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			static function (string $uri) use (&$posted, $response) {
				$posted[] = $uri;
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->objectService = ObjectServiceMockBuilder::make($this);

		return new EventService(
			$this->objectService,
			$clientService,
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
		);
	}//end service()

	/**
	 * The OpenRegister object service double of the current test.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * Wire a push subscription with the given sink and capture the saved message.
	 *
	 * @param string $sink The subscription's sink.
	 * @param array|null $captured Receives the saved message, by reference.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The message under delivery.
	 */
	private function pushTo(string $sink, ?array &$captured) {
		$message = ObjectServiceMockBuilder::objectEntity(
			$this,
			['subscription' => 'sub-uuid', 'retryCount' => 0, 'payload' => ['a' => 1]],
			'msg-uuid'
		);
		$subscription = ObjectServiceMockBuilder::objectEntity($this, ['style' => 'push', 'sink' => $sink], 'sub-uuid');

		$this->objectService->method('find')->willReturn($subscription);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $message) {
				$captured = $object;
				return $message;
			}
		);

		return $message;
	}//end pushTo()

	/**
	 * A metadata sink is never posted to, and the message is abandoned.
	 *
	 * @return void
	 */
	public function testAMetadataSinkIsNeverCalled(): void {
		$posted = [];
		$service = $this->service(posted: $posted);
		$captured = null;
		$message = $this->pushTo(sink: 'http://169.254.169.254/latest/meta-data/', captured: $captured);

		$this->assertFalse($service->deliverMessage($message));

		$this->assertSame([], $posted, 'the metadata address was called');
		$this->assertSame('abandoned', $captured['status'], 'a refused sink is not retried');
		$this->assertNull($captured['nextAttempt']);
		$this->assertStringContainsString('169.254.169.254', (string)$captured['error']);
	}//end testAMetadataSinkIsNeverCalled()

	/**
	 * A loopback sink is never posted to either.
	 *
	 * @return void
	 */
	public function testALoopbackSinkIsNeverCalled(): void {
		$posted = [];
		$service = $this->service(posted: $posted);
		$captured = null;
		$message = $this->pushTo(sink: 'https://127.0.0.1:9/never', captured: $captured);

		$this->assertFalse($service->deliverMessage($message));
		$this->assertSame([], $posted);
	}//end testALoopbackSinkIsNeverCalled()

	/**
	 * A public https sink is still delivered to.
	 *
	 * @return void
	 */
	public function testAPublicSinkIsDelivered(): void {
		$posted = [];
		$service = $this->service(posted: $posted);
		$captured = null;
		$message = $this->pushTo(sink: 'https://93.184.216.34/hook', captured: $captured);

		$this->assertTrue($service->deliverMessage($message));
		$this->assertSame(['https://93.184.216.34/hook'], $posted);
		$this->assertSame('delivered', $captured['status']);
	}//end testAPublicSinkIsDelivered()
}//end class
