<?php

/**
 * Unit tests for the product subscription hold on a push delivery.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
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
use OCA\Integriq\Service\ProductDeliveryGate;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A revoked product subscription stops the push; a spent quota defers it
 * without spending a retry; a subscription outside any product is not held.
 */
class EventServiceProductHoldTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var mixed
	 */
	private $objectService;

	/**
	 * The service with a gate answering a fixed hold.
	 *
	 * @param array<int, string> $posted The sinks posted to.
	 * @param array|null         $hold   What the gate answers.
	 *
	 * @return EventService The service.
	 */
	private function service(array &$posted, ?array $hold): EventService {
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
		$gate = $this->createMock(ProductDeliveryGate::class);
		$gate->method('hold')->willReturnCallback(
			static fn (array $subscriptionData): ?array => (($subscriptionData['productSubscription'] ?? '') === '' ? null : $hold)
		);

		$this->objectService = ObjectServiceMockBuilder::make($this);

		return new EventService(
			objectService: $this->objectService,
			clientService: $clientService,
			logger: $logger,
			signatureService: new WebhookSignatureService($logger),
			synchronizationService: $this->createMock(SynchronizationService::class),
			jobService: $this->createMock(JobService::class),
			callService: $this->createMock(CallService::class),
			flowRunnerService: $this->createMock(FlowRunnerService::class),
			productGate: $gate,
		);
	}//end service()

	/**
	 * A message to a push subscription riding on a product subscription, or not.
	 *
	 * @param string     $productSubscription The product subscription id, or ''.
	 * @param array|null $captured            The last saved message.
	 *
	 * @return mixed The message.
	 */
	private function message(string $productSubscription, ?array &$captured) {
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-uuid', 'retryCount' => 2, 'payload' => ['a' => 1]], 'msg-uuid');
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['style' => 'push', 'sink' => 'https://93.184.216.34/hook', 'productSubscription' => $productSubscription],
			'sub-uuid'
		);
		$this->objectService->method('find')->willReturn($subscription);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $message) {
				$captured = $object;
				return $message;
			}
		);

		return $message;
	}//end message()

	/**
	 * A revoked product subscription abandons the delivery and calls nothing.
	 *
	 * @return void
	 */
	public function testARevokedConsumerStopsReceiving(): void {
		$posted = [];
		$captured = null;
		$service = $this->service(posted: $posted, hold: ['reason' => 'not active', 'abandon' => true, 'retryAfter' => null]);

		$this->assertFalse($service->deliverMessage($this->message(productSubscription: 'ps-1', captured: $captured)));
		$this->assertSame([], $posted);
		$this->assertSame(['abandoned', 'not active'], [$captured['status'], $captured['error']]);
	}//end testARevokedConsumerStopsReceiving()

	/**
	 * Over quota the delivery waits for the window and keeps its retry count.
	 *
	 * @return void
	 */
	public function testOverQuotaWaitsWithoutSpendingARetry(): void {
		$posted = [];
		$captured = null;
		$service = $this->service(posted: $posted, hold: ['reason' => 'budget spent', 'abandon' => false, 'retryAfter' => 3600]);
		$before = time();

		$this->assertFalse($service->deliverMessage($this->message(productSubscription: 'ps-1', captured: $captured)));
		$this->assertSame([], $posted);
		$this->assertSame(['failed', 2, 3600, 'budget spent'], [$captured['status'], $captured['retryCount'], $captured['retryAfter'], $captured['error']]);
		$this->assertGreaterThanOrEqual($before + 3600, strtotime($captured['nextAttempt']));
		$this->assertLessThanOrEqual(time() + 3600, strtotime($captured['nextAttempt']));
	}//end testOverQuotaWaitsWithoutSpendingARetry()

	/**
	 * A subscription outside any product is delivered as before.
	 *
	 * @return void
	 */
	public function testASubscriptionOutsideAProductIsDelivered(): void {
		$posted = [];
		$captured = null;
		$service = $this->service(posted: $posted, hold: ['reason' => 'x', 'abandon' => true, 'retryAfter' => null]);

		$this->assertTrue($service->deliverMessage($this->message(productSubscription: '', captured: $captured)));
		$this->assertSame(['https://93.184.216.34/hook'], $posted);
	}//end testASubscriptionOutsideAProductIsDelivered()
}//end class
