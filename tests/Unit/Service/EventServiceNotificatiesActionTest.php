<?php

/**
 * Unit tests for EventService's `action.kind = 'notificaties'` dispatch
 * branch (notificaties-api-subscriber REQ-010).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
 */
class EventServiceNotificatiesActionTest extends TestCase {

	/**
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $clientService;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * The `object` payload of the most recent `saveObject()` call (the
	 * `event_message` being persisted by the dispatch under test).
	 *
	 * @var array
	 */
	private array $capturedMessage = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$logger = $this->createMock(LoggerInterface::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->callService = $this->createMock(CallService::class);
		$this->capturedMessage = [];

		$this->service = new EventService(
			$this->objectService,
			$this->clientService,
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->callService,
			$this->createMock(FlowRunnerService::class),
		);

	}//end setUp()

	/**
	 * Configure `find`/`findAll`/`saveObject` for a single active subscription
	 * with the given `action` block, matching every incoming event.
	 * `saveObject()` calls are captured into `$this->capturedMessage`.
	 *
	 * @param array $action The subscription's `action` block.
	 * @param array $find Extra `schema => ObjectEntity` responses for `find()`
	 *                    (e.g. `source`) beyond the subscription/event themselves.
	 *
	 * @return ObjectEntity The event entity to pass to `processEvent()`.
	 */
	private function wireSubscription(array $action, array $find = []): ObjectEntity {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'style' => 'push', 'action' => $action],
			'sub-uuid'
		);

		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'com.nextcloud.openregister.object.updated',
				'subject' => 'uuid-1',
				'time' => '2026-07-15T09:00:00Z',
				'data' => [],
			],
			'event-uuid'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($find, $event) {
				if ($schema === 'event') {
					return ($find['event'] ?? $event);
				}

				return ($find[$schema] ?? null);
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->capturedMessage = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
			}
		);

		return $event;
	}//end wireSubscription()

	/**
	 * TC-11: action.kind=notificaties publishes the ZGW notification body
	 * instead of an HTTP webhook.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
	 */
	public function testActionKindNotificatiesPublishesBodyOn2xx(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://notificaties.example'], 'source-1');
		$event = $this->wireSubscription(
			['kind' => 'notificaties', 'sourceId' => 'source-1', 'kanaal' => 'zaken'],
			['source' => $source]
		);

		$capturedCall = null;
		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200], 'call-log-1');
		$this->callService->expects($this->once())
			->method('call')
			->willReturnCallback(
				function ($src, $endpoint, $method, $config = []) use (&$capturedCall, $callLog) {
					$capturedCall = ['source' => $src, 'endpoint' => $endpoint, 'method' => $method, 'config' => $config];
					return $callLog;
				}
			);
		$this->clientService->expects($this->never())->method('newClient');

		$this->service->processEvent($event);

		$this->assertSame('/notificaties', $capturedCall['endpoint']);
		$this->assertSame('POST', $capturedCall['method']);
		$this->assertSame('zaken', $capturedCall['config']['json']['kanaal']);
		$this->assertSame('delivered', $this->capturedMessage['status']);

	}//end testActionKindNotificatiesPublishesBodyOn2xx()

	/**
	 * TC-12: action.kind=notificaties failure follows the standard
	 * retry/backoff/abandon machine.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
	 */
	public function testActionKindNotificatiesFailureIncrementsRetryCount(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://notificaties.example'], 'source-1');
		$event = $this->wireSubscription(
			['kind' => 'notificaties', 'sourceId' => 'source-1', 'kanaal' => 'zaken'],
			['source' => $source]
		);

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 500], 'call-log-2');
		$this->callService->method('call')->willReturn($callLog);

		$this->service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(1, $this->capturedMessage['retryCount']);

	}//end testActionKindNotificatiesFailureIncrementsRetryCount()

	/**
	 * TC-13: an unresolvable sourceId is a retryable failure, not a hard error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
	 */
	public function testActionKindNotificatiesUnresolvableSourceIsRetryable(): void {
		$event = $this->wireSubscription(['kind' => 'notificaties', 'sourceId' => 'missing-uuid', 'kanaal' => 'zaken']);

		$this->callService->expects($this->never())->method('call');

		$this->service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame('source not found', $this->capturedMessage['error']);
		$this->assertSame(1, $this->capturedMessage['retryCount']);

	}//end testActionKindNotificatiesUnresolvableSourceIsRetryable()

	/**
	 * REQ-006: a notificaties action with no kanaal fails once, does not
	 * enter the retry loop (config error, retryCount stays 0).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-a-publish-action-missing-kanaal-is-a-configuration-error-not-a-transient-failure-req-006
	 */
	public function testActionKindNotificatiesMissingKanaalIsConfigError(): void {
		$event = $this->wireSubscription(['kind' => 'notificaties', 'sourceId' => 'source-1']);

		$this->callService->expects($this->never())->method('call');

		$this->service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(0, ($this->capturedMessage['retryCount'] ?? 0));

	}//end testActionKindNotificatiesMissingKanaalIsConfigError()
}//end class
