<?php

/**
 * Unit tests for the recipient decision on the delivery intake.
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
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\EventListener\DeliveryRequestedListener;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\RecipientResolver;
use OCA\Integriq\Outbound\RecipientState;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests that the decision rides the delivery request and lands on the record.
 *
 * @covers \OCA\Integriq\Service\EventService
 * @covers \OCA\Integriq\EventListener\DeliveryRequestedListener
 * @covers \OCA\Integriq\Event\DeliveryRequestedEvent
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
 */
class EventServiceRecipientSelectionTest extends TestCase {

	/**
	 * The opt-out fixture, for the send gate.
	 *
	 * @var OptOutFixture
	 */
	private OptOutFixture $fx;

	/**
	 * Every object the service saved, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$this->fx->baseUrl = 'https://gem.nl';
		$this->saved = [];

	}//end setUp()

	/**
	 * The record keeps all three parts, and the transport payload carries the resolved list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
	 */
	public function testTheRecordKeepsThreePartsAndThePayloadTheResolvedList(): void {
		$event = $this->request(payload: ['caseId' => 'case-1']);
		$this->listener(service: $this->service(gate: false))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertCount(1, $this->saved, 'only the delivery event is written: no standing list, party or subject');
		$data = $this->saved[0]['data'];
		$this->assertSame(
			['aanvrager@example.org', 'derde@example.org', 'gemachtigde@example.org'],
			array_column($data['payload']['recipients'], 'address')
		);
		$selection = $data['delivery']['recipientSelection'];
		$this->assertSame(['standing', 'added', 'suppressed'], array_keys($selection));
		$this->assertCount(3, $selection['standing']);
		$this->assertSame('gemachtigde@example.org', $selection['added'][0]['address']);
		$this->assertSame(RecipientState::SUPPRESSED, $selection['suppressed'][0]['state']);
		$this->assertSame('Geen belanghebbende meer', $selection['suppressed'][0]['reason']);
		$this->assertSame('behandelaar', $selection['suppressed'][0]['user']);

	}//end testTheRecordKeepsThreePartsAndThePayloadTheResolvedList()

	/**
	 * A suppression without a reason stops the send: refused, nothing written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003
	 */
	public function testASuppressionWithoutAReasonWritesNothing(): void {
		$decision = $this->decision();
		$decision['suppressed'][0]['reason'] = '';
		$event = $this->request(payload: ['caseId' => 'case-1'], decision: $decision);

		$this->listener(service: $this->service(gate: false))->handle($event);

		$this->assertSame(RecipientResolver::REFUSED_NO_REASON, $event->getRefusal()['code']);
		$this->assertSame([], $this->saved);

	}//end testASuppressionWithoutAReasonWritesNothing()

	/**
	 * A decision nobody resolved fails closed instead of sending to an undecided list.
	 *
	 * @return void
	 */
	public function testAnUnresolvedDecisionFailsClosed(): void {
		$this->expectException(RuntimeException::class);

		try {
			$this->service(gate: false)->ingestDeliveryRequest(request: $this->request(payload: ['caseId' => 'case-1']));
		} finally {
			$this->assertSame([], $this->saved);
		}

	}//end testAnUnresolvedDecisionFailsClosed()

	/**
	 * A request without a decision is ingested as before, with no recipient parts.
	 *
	 * @return void
	 */
	public function testARequestWithoutADecisionIsUnchanged(): void {
		$this->service(gate: false)->ingestDeliveryRequest(request: $this->request(payload: ['caseId' => 'case-1'], decision: []));

		$this->assertSame(['caseId' => 'case-1'], $this->saved[0]['data']['payload']);
		$this->assertArrayNotHasKey('recipientSelection', $this->saved[0]['data']['delivery']);

	}//end testARequestWithoutADecisionIsUnchanged()

	/**
	 * The outbound message record opened for a personal delivery carries the decision too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
	 */
	public function testTheMessageRecordCarriesTheDecision(): void {
		$recorder = $this->getMockBuilder(MessageRecorder::class)->disableOriginalConstructor()->getMock();
		$recorder->expects($this->once())->method('start')->with(
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(
				static fn (array $options): bool => array_column($options['recipientSelection']['suppressed'], 'address') === ['buur@example.org']
					&& array_key_exists('resolved', $options['recipientSelection']) === false
			)
		)->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'log-1'));

		$event = $this->request(payload: ['recipient' => 'aanvrager@example.org', 'recipientChannel' => 'email', 'category' => 'besluit']);
		$this->listener(service: $this->service(gate: true, recorder: $recorder))->handle($event);

		$this->assertNull($event->getRefusal());

	}//end testTheMessageRecordCarriesTheDecision()

	/**
	 * The listener over the service.
	 *
	 * @param EventService $service The service.
	 *
	 * @return DeliveryRequestedListener The listener.
	 */
	private function listener(EventService $service): DeliveryRequestedListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new DeliveryRequestedListener($service, new NullLogger(), new RecipientResolver($l10n));

	}//end listener()

	/**
	 * Three standing recipients, one addition, one suppression.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function decision(): array {
		return [
			'standing' => [
				['address' => 'aanvrager@example.org', 'required' => true, 'requiredBy' => 'Awb 3:41'],
				'buur@example.org',
				'derde@example.org',
			],
			'added' => ['gemachtigde@example.org'],
			'suppressed' => [['address' => 'buur@example.org', 'reason' => 'Geen belanghebbende meer']],
		];

	}//end decision()

	/**
	 * A delivery request from dossiq.
	 *
	 * @param array<string,mixed>      $payload  The payload.
	 * @param array<string,mixed>|null $decision The recipient decision; null for the default one.
	 *
	 * @return DeliveryRequestedEvent The request.
	 */
	private function request(array $payload, ?array $decision = null): DeliveryRequestedEvent {
		return new DeliveryRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-1',
			subjectLabel: 'Kapvergunning',
			deliveryKind: 'beschikking',
			channel: 'webhook',
			payload: $payload,
			correlationId: 'corr-1',
			userId: 'behandelaar',
			recipientDecision: ($decision ?? $this->decision()),
		);

	}//end request()

	/**
	 * The service over the doubles.
	 *
	 * @param bool                $gate     Whether the send gate is wired.
	 * @param MessageRecorder|null $recorder The recorder behind the gate.
	 *
	 * @return EventService The service.
	 */
	private function service(bool $gate, ?MessageRecorder $recorder = null): EventService {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'evt-1');
			}
		);
		$objectService->method('findAll')->willReturn(['results' => []]);
		$logger = new NullLogger();

		return new EventService(
			objectService: $objectService,
			clientService: $this->createMock(IClientService::class),
			logger: $logger,
			signatureService: new WebhookSignatureService($logger),
			synchronizationService: $this->createMock(SynchronizationService::class),
			jobService: $this->createMock(JobService::class),
			callService: $this->createMock(CallService::class),
			flowRunnerService: $this->createMock(FlowRunnerService::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			sendGate: ($gate === true ? $this->fx->gate(recorder: $recorder) : null),
		);

	}//end service()
}//end class
