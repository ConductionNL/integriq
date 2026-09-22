<?php

/**
 * Unit tests for EventService's `action.kind = 'broker'` dispatch branch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;
use OCA\Integriq\Broker\BrokerTransportInterface;
use OCA\Integriq\Broker\BrokerTransportRegistry;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the broker action dispatch.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
 */
class EventServiceBrokerActionTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $clientService;

	/**
	 * The `object` payload of the most recent `saveObject()` call.
	 *
	 * @var array
	 */
	private array $capturedMessage = [];

	/**
	 * The publication the transport was asked to send, when it was.
	 *
	 * @var BrokerPublication|null
	 */
	private ?BrokerPublication $capturedPublication = null;

	/**
	 * The configuration the transport was handed, when it was.
	 *
	 * @var array
	 */
	private array $capturedConfiguration = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->clientService = $this->createMock(IClientService::class);
		$this->capturedMessage = [];
		$this->capturedPublication = null;
		$this->capturedConfiguration = [];

	}//end setUp()

	/**
	 * A transport answering with a fixed result, recording what it was asked
	 * to publish.
	 *
	 * `onlyMethods` is not needed here because the double stands for an
	 * interface, so it can only carry methods the interface declares.
	 *
	 * @param string $brokerId The id it answers to.
	 * @param BrokerResult $result What it answers.
	 *
	 * @return BrokerTransportInterface The transport.
	 */
	private function transport(string $brokerId, BrokerResult $result): BrokerTransportInterface {
		$transport = $this->createMock(BrokerTransportInterface::class);
		$transport->method('getId')->willReturn($brokerId);
		$transport->method('describe')->willReturn(['id' => $brokerId]);
		$transport->method('publish')->willReturnCallback(
			function (BrokerPublication $publication, array $configuration) use ($result) {
				$this->capturedPublication = $publication;
				$this->capturedConfiguration = $configuration;
				return $result;
			}
		);

		return $transport;
	}//end transport()

	/**
	 * Build the service over a registry, or over none.
	 *
	 * @param BrokerTransportRegistry|null $registry The registry, or null for a deployment with no broker wiring.
	 *
	 * @return EventService The service.
	 */
	private function service(?BrokerTransportRegistry $registry): EventService {
		$logger = $this->createMock(LoggerInterface::class);

		return new EventService(
			$this->objectService,
			$this->clientService,
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
			null,
			null,
			null,
			null,
			null,
			$registry,
		);
	}//end service()

	/**
	 * Wire one active push subscription carrying the given action and
	 * protocol settings, matching every event.
	 *
	 * @param array $action The subscription's `action` block.
	 * @param array $protocolSettings The subscription's `protocolSettings` block.
	 *
	 * @return ObjectEntity The event to pass to `processEvent()`.
	 */
	private function wireSubscription(array $action, array $protocolSettings = []): ObjectEntity {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'status' => 'active',
				'style' => 'push',
				'action' => $action,
				'protocolSettings' => $protocolSettings,
			],
			'sub-uuid'
		);

		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'nl.conduction.zaak.created',
				'source' => '/integriq/zaken',
				'subject' => 'ZAAK-2026-0042',
				'time' => '2026-09-22T09:00:00Z',
				'data' => ['zaak' => 'ZAAK-2026-0042'],
			],
			'event-uuid'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($subscription, $event) {
				if ($schema === 'event') {
					return $event;
				}

				if ($schema === 'event_subscription') {
					return $subscription;
				}

				return null;
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
	 * REQ-013: a matched event is published through the named transport, and
	 * the message is delivered.
	 *
	 * @return void
	 */
	public function testAMatchedEventIsPublishedThroughTheNamedBroker(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new BrokerTransportRegistry(
			logger: $logger,
			transports: [$this->transport('rabbitmq', BrokerResult::published('rabbitmq', 'ref-1'))]
		);

		$event = $this->wireSubscription(
			['kind' => 'broker', 'brokerId' => 'rabbitmq', 'topic' => 'zaken', 'routingKey' => 'zaak.created'],
			['broker' => ['baseUrl' => 'https://rabbit.example.nl', 'password' => 'p']]
		);

		$this->clientService->expects($this->never())->method('newClient');

		$this->service(registry: $registry)->processEvent($event);

		$this->assertSame('delivered', $this->capturedMessage['status']);
		$this->assertNotNull($this->capturedPublication);
		$this->assertSame('zaken', $this->capturedPublication->getTopic());
		$this->assertSame('zaak.created', $this->capturedPublication->getRoutingKey());
		$this->assertSame('https://rabbit.example.nl', $this->capturedConfiguration['baseUrl']);

	}//end testAMatchedEventIsPublishedThroughTheNamedBroker()

	/**
	 * REQ-013: a brokerId nothing answers to is a configuration error. It
	 * fails once and does not enter the retry loop, because a missing
	 * transport will not appear on a retry.
	 *
	 * @return void
	 */
	public function testAnUnknownBrokerIdDoesNotRetry(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new BrokerTransportRegistry(
			logger: $logger,
			transports: [$this->transport('rabbitmq', BrokerResult::published('rabbitmq'))]
		);

		$event = $this->wireSubscription(['kind' => 'broker', 'brokerId' => 'nothing-answers-to-this']);

		$this->service(registry: $registry)->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(0, ($this->capturedMessage['retryCount'] ?? 0));
		$this->assertNull($this->capturedMessage['nextAttempt']);
		$this->assertStringContainsString('nothing-answers-to-this', (string)$this->capturedMessage['error']);
		$this->assertNull($this->capturedPublication);

	}//end testAnUnknownBrokerIdDoesNotRetry()

	/**
	 * REQ-014: a refused publish is a failed delivery that retries, with the
	 * same bookkeeping a refused webhook gets.
	 *
	 * @return void
	 */
	public function testARefusedPublishRetriesLikeAnyFailedDelivery(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new BrokerTransportRegistry(
			logger: $logger,
			transports: [
				$this->transport(
					'rabbitmq',
					BrokerResult::refused('rabbitmq', 'RabbitMQ accepted the message and routed it to no queue.', 200)
				),
			]
		);

		$event = $this->wireSubscription(['kind' => 'broker', 'brokerId' => 'rabbitmq', 'topic' => 'zaken']);

		$this->service(registry: $registry)->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(1, $this->capturedMessage['retryCount']);
		$this->assertNotNull($this->capturedMessage['nextAttempt']);
		$this->assertCount(1, $this->capturedMessage['attempts']);
		$this->assertStringContainsString('routed it to no queue', (string)$this->capturedMessage['error']);

	}//end testARefusedPublishRetriesLikeAnyFailedDelivery()

	/**
	 * REQ-013: an action naming no brokerId is a configuration error.
	 *
	 * @return void
	 */
	public function testAnAbsentBrokerIdIsAConfigurationError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new BrokerTransportRegistry(logger: $logger, transports: []);

		$event = $this->wireSubscription(['kind' => 'broker', 'topic' => 'zaken']);

		$this->service(registry: $registry)->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(0, ($this->capturedMessage['retryCount'] ?? 0));
		$this->assertStringContainsString('action.brokerId is required', (string)$this->capturedMessage['error']);

	}//end testAnAbsentBrokerIdIsAConfigurationError()

	/**
	 * A deployment with no registry wired reports a configuration error
	 * rather than a delivery. Nothing is published and nothing is marked
	 * delivered.
	 *
	 * @return void
	 */
	public function testNoRegistryMeansNoDelivery(): void {
		$event = $this->wireSubscription(['kind' => 'broker', 'brokerId' => 'rabbitmq']);

		$this->service(registry: null)->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertStringContainsString('No broker transport registry', (string)$this->capturedMessage['error']);
		$this->assertNull($this->capturedPublication);

	}//end testNoRegistryMeansNoDelivery()

	/**
	 * The broker credentials are read off `protocolSettings`, which is the
	 * block stripped from every rendered read, and never off the action.
	 *
	 * @return void
	 */
	public function testBrokerCredentialsComeOffProtocolSettings(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new BrokerTransportRegistry(
			logger: $logger,
			transports: [$this->transport('kafka-rest', BrokerResult::published('kafka-rest'))]
		);

		$event = $this->wireSubscription(
			['kind' => 'broker', 'brokerId' => 'kafka-rest', 'topic' => 'zaken', 'orderingKey' => 'ZAAK-2026-0042'],
			['broker' => ['baseUrl' => 'https://kafka.example.nl', 'token' => 'secret-token'], 'headers' => ['X-Tenant' => 'gemeente-x']]
		);

		$this->service(registry: $registry)->processEvent($event);

		$this->assertSame('secret-token', $this->capturedConfiguration['token']);
		$this->assertSame('ZAAK-2026-0042', $this->capturedPublication->getOrderingKey());
		$this->assertSame(['X-Tenant' => 'gemeente-x'], $this->capturedPublication->getHeaders());

	}//end testBrokerCredentialsComeOffProtocolSettings()

}//end class
