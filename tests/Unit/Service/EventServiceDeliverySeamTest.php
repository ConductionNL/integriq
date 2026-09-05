<?php

/**
 * EventService delivery-seam Unit Tests
 *
 * Tests the ADR-041 delivery seam on EventService: ingesting a sibling app's
 * DeliveryRequestedEvent as a provenance-carrying CloudEvent, and dispatching
 * the terminal DeliveryConcludedEvent from the message state machine —
 * delivered on success, abandoned when the retry budget is spent, and never
 * for ordinary CloudEvent traffic without provenance.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\DeliveryConcludedEvent;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the EventService delivery seam.
 *
 * @covers \OCA\Integriq\Service\EventService
 * @covers \OCA\Integriq\Event\DeliveryRequestedEvent
 * @covers \OCA\Integriq\Event\DeliveryConcludedEvent
 *
 * @uses \OCA\Integriq\Service\WebhookSignatureService
 * @uses \OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder
 */
class EventServiceDeliverySeamTest extends TestCase {

	/**
	 * The mocked OR ObjectService.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * The mocked event dispatcher.
	 *
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * The service under test.
	 *
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new EventService(
			$this->objectService,
			$this->createMock(IClientService::class),
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
			$this->eventDispatcher,
		);
	}//end setUp()

	/**
	 * Build the persisted data of a provenance-carrying event_message.
	 *
	 * @param int $attempts How many attempts the message carries.
	 * @param string|null $error The last error on the message.
	 *
	 * @return array<string, mixed>
	 */
	private function provenanceMessageData(int $attempts = 1, ?string $error = null): array {
		$attemptRows = [];
		for ($i = 0; $i < $attempts; $i++) {
			$attemptRows[] = ['at' => '2026-09-02T12:00:0' . $i . '+00:00', 'statusCode' => null, 'error' => null];
		}

		return [
			'event' => 'evt-1',
			'subscription' => 'sub-1',
			'status' => 'pending',
			'attempts' => $attemptRows,
			'error' => $error,
			'payload' => [
				'data' => [
					'delivery' => [
						'sourceApp' => 'dossiq',
						'subjectId' => 'case-1',
						'channel' => 'gemeenteblad',
						'correlationId' => 'corr-1',
					],
					'payload' => ['caseId' => 'case-1'],
				],
			],
		];
	}//end provenanceMessageData()

	/**
	 * Ingesting a delivery request persists a provenance-carrying CloudEvent
	 * and fans it out through processEvent.
	 *
	 * @return void
	 */
	public function testIngestDeliveryRequestPersistsProvenanceEvent(): void {
		$request = new DeliveryRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-1',
			subjectLabel: 'Kapvergunning',
			deliveryKind: 'besluit-publication',
			channel: 'gemeenteblad',
			payload: ['caseId' => 'case-1'],
			correlationId: 'corr-1',
		);

		$savedObject = null;
		$eventEntity = ObjectServiceMockBuilder::objectEntity($this, [], 'evt-1');
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$savedObject, $eventEntity) {
				$savedObject = $object;
				return $eventEntity;
			}
		);
		// No active subscriptions: processEvent matches nothing.
		$this->objectService->method('findAll')->willReturn(['results' => []]);

		$result = $this->service->ingestDeliveryRequest(request: $request);

		$this->assertSame('evt-1', $result['event']->getUuid());
		$this->assertSame([], $result['messages']);
		$this->assertNotNull($savedObject);
		$this->assertSame(EventService::DELIVERY_REQUESTED_TYPE, $savedObject['type']);
		$this->assertSame('/apps/dossiq/delivery', $savedObject['source']);
		$this->assertSame('case-1', $savedObject['subject']);
		$this->assertSame('dossiq', $savedObject['data']['delivery']['sourceApp']);
		$this->assertSame('corr-1', $savedObject['data']['delivery']['correlationId']);
		$this->assertSame(['caseId' => 'case-1'], $savedObject['data']['payload']);
	}//end testIngestDeliveryRequestPersistsProvenanceEvent()

	/**
	 * A successful delivery of a provenance-carrying message dispatches the
	 * delivered conclusion.
	 *
	 * @return void
	 */
	public function testRecordDeliverySuccessDispatchesConcluded(): void {
		$message = ObjectServiceMockBuilder::objectEntity($this, $this->provenanceMessageData(), 'msg-1');
		$this->objectService->method('saveObject')->willReturn($message);

		$dispatched = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched = $event;
			}
		);

		$method = new \ReflectionMethod(EventService::class, 'recordDeliverySuccess');
		$method->invoke($this->service, $message);

		$this->assertInstanceOf(DeliveryConcludedEvent::class, $dispatched);
		$this->assertSame('dossiq', $dispatched->getSourceApp());
		$this->assertSame('corr-1', $dispatched->getCorrelationId());
		$this->assertSame('case-1', $dispatched->getSubjectId());
		$this->assertSame('gemeenteblad', $dispatched->getChannel());
		$this->assertSame(DeliveryConcludedEvent::STATUS_DELIVERED, $dispatched->getStatus());
		$this->assertSame('evt-1', $dispatched->getEventId());
		$this->assertSame('msg-1', $dispatched->getMessageId());
		// recordDeliverySuccess appends the successful attempt.
		$this->assertSame(2, $dispatched->getAttempts());
		$this->assertNull($dispatched->getError());
	}//end testRecordDeliverySuccessDispatchesConcluded()

	/**
	 * Spending the retry budget dispatches the abandoned conclusion with the
	 * last error.
	 *
	 * @return void
	 */
	public function testRecordFailureTerminalDispatchesAbandoned(): void {
		$data = $this->provenanceMessageData();
		$data['retryCount'] = 0;
		$message = ObjectServiceMockBuilder::objectEntity($this, $data, 'msg-1');
		$this->objectService->method('saveObject')->willReturn($message);

		$dispatched = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched = $event;
			}
		);

		$method = new \ReflectionMethod(EventService::class, 'recordFailure');
		$method->invoke($this->service, $message, 'HTTP 503', 503, null, ['maxRetries' => 1]);

		$this->assertInstanceOf(DeliveryConcludedEvent::class, $dispatched);
		$this->assertSame(DeliveryConcludedEvent::STATUS_ABANDONED, $dispatched->getStatus());
		$this->assertSame('HTTP 503', $dispatched->getError());
		$this->assertSame('corr-1', $dispatched->getCorrelationId());
	}//end testRecordFailureTerminalDispatchesAbandoned()

	/**
	 * A non-terminal failure (retry budget remaining) dispatches nothing.
	 *
	 * @return void
	 */
	public function testRecordFailureNonTerminalDispatchesNothing(): void {
		$data = $this->provenanceMessageData();
		$data['retryCount'] = 0;
		$message = ObjectServiceMockBuilder::objectEntity($this, $data, 'msg-1');
		$this->objectService->method('saveObject')->willReturn($message);

		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$method = new \ReflectionMethod(EventService::class, 'recordFailure');
		$method->invoke($this->service, $message, 'HTTP 503', 503, null, ['maxRetries' => 5]);
	}//end testRecordFailureNonTerminalDispatchesNothing()

	/**
	 * Ordinary CloudEvent traffic — no provenance block — never produces a
	 * conclusion.
	 *
	 * @return void
	 */
	public function testNoConclusionWithoutProvenance(): void {
		$message = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'event' => 'evt-2',
				'status' => 'pending',
				'attempts' => [],
				'payload' => ['data' => ['id' => 'obj-1']],
			],
			'msg-2'
		);
		$this->objectService->method('saveObject')->willReturn($message);

		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$method = new \ReflectionMethod(EventService::class, 'recordDeliverySuccess');
		$method->invoke($this->service, $message);
	}//end testNoConclusionWithoutProvenance()
}//end class
