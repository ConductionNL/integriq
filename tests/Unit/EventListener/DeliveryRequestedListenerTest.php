<?php

/**
 * DeliveryRequestedListener Unit Tests
 *
 * Tests the in-process half of the ADR-041 delivery seam: a sibling app's
 * DeliveryRequestedEvent is ingested into the CloudEvents pipeline and the
 * synchronous result slot is written back; an ingest failure leaves the event
 * unhandled so the consumer fail-closes.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
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

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\EventListener\DeliveryRequestedListener;
use OCA\Integriq\Outbound\RecipientResolver;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DeliveryRequestedListener.
 *
 * @covers \OCA\Integriq\EventListener\DeliveryRequestedListener
 * @covers \OCA\Integriq\Event\DeliveryRequestedEvent
 *
 * @uses \OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder
 */
class DeliveryRequestedListenerTest extends TestCase {

	/**
	 * The mocked EventService.
	 *
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * The listener under test.
	 *
	 * @var DeliveryRequestedListener
	 */
	private DeliveryRequestedListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->eventService = $this->getMockBuilder(EventService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->listener = new DeliveryRequestedListener(
			eventService: $this->eventService,
			logger: $this->createMock(LoggerInterface::class),
			recipientResolver: new RecipientResolver($this->createMock(IL10N::class))
		);
	}//end setUp()

	/**
	 * Build a delivery request event.
	 *
	 * @return DeliveryRequestedEvent
	 */
	private function request(): DeliveryRequestedEvent {
		return new DeliveryRequestedEvent(
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
	}//end request()

	/**
	 * A successful ingest writes handled + resultId + matched count back onto
	 * the event.
	 *
	 * @return void
	 */
	public function testHandledRequestCarriesResultSlot(): void {
		$eventEntity = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'nl.conduction.delivery.requested'], 'evt-1');
		$message = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'msg-1');
		$this->eventService->method('ingestDeliveryRequest')->willReturn(
			[
				'event' => $eventEntity,
				'messages' => [$message],
			]
		);

		$event = $this->request();
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('evt-1', $event->getResultId());
		$this->assertSame(1, $event->getMatchedSubscriptions());
	}//end testHandledRequestCarriesResultSlot()

	/**
	 * An ingest failure leaves the event unhandled — the consumer's
	 * fail-closed guard then records the refusal.
	 *
	 * @return void
	 */
	public function testIngestFailureLeavesEventUnhandled(): void {
		$this->eventService->method('ingestDeliveryRequest')
			->willThrowException(new \RuntimeException('register unavailable'));

		$event = $this->request();
		$this->listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getResultId());
	}//end testIngestFailureLeavesEventUnhandled()

	/**
	 * A suppression naming somebody outside the standing list fails closed:
	 * refused naming the recipient, handled, and never ingested.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
	 */
	public function testAnUnknownSuppressionIsRefusedAndNothingIsSent(): void {
		$this->eventService->expects($this->never())->method('ingestDeliveryRequest');

		$event = $this->requestWithDecision(
			[
				'standing' => ['aanvrager@example.org'],
				'suppressed' => [['address' => 'onbekend@example.org', 'reason' => 'Verhuisd']],
			]
		);
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getResultId());
		$this->assertSame(RecipientResolver::REFUSED_UNKNOWN, $event->getRefusal()['code']);
		$this->assertNull($event->getRecipientSelection());

	}//end testAnUnknownSuppressionIsRefusedAndNothingIsSent()

	/**
	 * A whole decision is resolved in integriq before the ingest, and the result slot reports it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
	 */
	public function testADecisionIsResolvedBeforeTheIngest(): void {
		$eventEntity = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'nl.conduction.delivery.requested'], 'evt-2');
		$this->eventService->expects($this->once())->method('ingestDeliveryRequest')->willReturnCallback(
			function (DeliveryRequestedEvent $request) use ($eventEntity): array {
				$this->assertSame(
					['aanvrager@example.org', 'gemachtigde@example.org'],
					array_column($request->getRecipientSelection()['resolved'], 'address')
				);
				return ['event' => $eventEntity, 'messages' => []];
			}
		);

		$event = $this->requestWithDecision(
			[
				'standing' => [['address' => 'aanvrager@example.org', 'required' => true, 'requiredBy' => 'Awb 3:41'], 'buur@example.org'],
				'added' => ['gemachtigde@example.org'],
				'suppressed' => [['address' => 'buur@example.org', 'reason' => 'Geen belanghebbende']],
			]
		);
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('evt-2', $event->getResultId());
		$this->assertNull($event->getRefusal());
		$this->assertSame('', $event->getRecipientSelection()['suppressed'][0]['user']);

	}//end testADecisionIsResolvedBeforeTheIngest()

	/**
	 * A delivery request carrying a recipient decision.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @return DeliveryRequestedEvent
	 */
	private function requestWithDecision(array $decision): DeliveryRequestedEvent {
		return new DeliveryRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-1',
			subjectLabel: 'Kapvergunning',
			deliveryKind: 'beschikking',
			channel: 'email',
			payload: ['caseId' => 'case-1'],
			correlationId: 'corr-2',
			recipientDecision: $decision,
		);

	}//end requestWithDecision()

	/**
	 * A non-delivery event is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresForeignEvents(): void {
		$this->eventService->expects($this->never())->method('ingestDeliveryRequest');
		$this->listener->handle(new class extends Event {
		});
	}//end testIgnoresForeignEvents()
}//end class
