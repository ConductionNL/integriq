<?php

/**
 * Unit tests for the delivery ingest asking the opt-out list.
 *
 * Through the real EventService, the real DeliveryRequestedListener and the
 * real opt-out services over in-memory tables. OpenRegister is a double.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\EventListener\DeliveryRequestedListener;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A delivery that names a person is asked first.
 */
class EventServiceDeliveryOptOutTest extends TestCase {

	/**
	 * The opt-out services over in-memory tables.
	 *
	 * @var OptOutFixture
	 */
	private OptOutFixture $fx;

	/**
	 * The object saved as the CloudEvent.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * How often the subscriptions were read, which is what routing does first.
	 *
	 * @var int
	 */
	private int $subscriptionReads = 0;

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$this->fx->baseUrl = 'https://gem.nl';
		$this->saved = null;
		$this->subscriptionReads = 0;

	}//end setUp()

	/**
	 * An opted-out recipient: stored with the decision, not routed, refusal on the event.
	 *
	 * @return void
	 */
	public function testAnOptedOutRecipientIsNotRouted(): void {
		$this->fx->registry()->add('jan@example.org');
		$event = $this->request(payload: ['recipient' => ['address' => 'jan@example.org', 'channel' => 'email'], 'category' => 'case-update', 'body' => 'Uw zaak']);

		(new DeliveryRequestedListener($this->service(withGate: true), new NullLogger()))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('opted-out', $event->getRefusal()['code']);
		$this->assertSame(0, $event->getMatchedSubscriptions());
		$this->assertSame(0, $this->subscriptionReads, 'not routed');
		$this->assertSame('opted-out', $this->saved['data']['delivery']['optOut']['code']);
		$this->assertCount(1, $this->fx->log->ofKind('suppressed'));

	}//end testAnOptedOutRecipientIsNotRouted()

	/**
	 * A besluit publication to an opted-out person is routed, without a link.
	 *
	 * @return void
	 */
	public function testAnExemptDeliveryIsRouted(): void {
		$this->fx->registry()->add('jan@example.org');
		$event = $this->request(payload: ['recipient' => 'jan@example.org', 'recipientChannel' => 'email', 'category' => 'besluit']);

		(new DeliveryRequestedListener($this->service(withGate: true), new NullLogger()))->handle($event);

		$this->assertNull($event->getRefusal());
		$this->assertSame(1, $this->subscriptionReads, 'routed');
		$this->assertArrayNotHasKey('unsubscribe', $this->saved['data']['payload']);
		$this->assertTrue($this->saved['data']['delivery']['optOut']['send']);

	}//end testAnExemptDeliveryIsRouted()

	/**
	 * An allowed delivery carries the link in the payload and the body.
	 *
	 * @return void
	 */
	public function testAnAllowedDeliveryCarriesTheLink(): void {
		$event = $this->request(payload: ['recipient' => 'piet@example.org', 'recipientChannel' => 'email', 'body' => 'Uw afspraak']);

		$this->service(withGate: true)->ingestDeliveryRequest(request: $event);

		$payload = $this->saved['data']['payload'];
		$this->assertStringStartsWith('https://gem.nl/index.php/apps/integriq/unsubscribe/v3.', $payload['unsubscribe']['url']);
		$this->assertSame('List-Unsubscribe=One-Click', $payload['unsubscribe']['headers']['List-Unsubscribe-Post']);
		$this->assertStringContainsString('Geen berichten meer ontvangen: https://gem.nl/', $payload['body']);

	}//end testAnAllowedDeliveryCarriesTheLink()

	/**
	 * A delivery that names no person is not asked, as before.
	 *
	 * @return void
	 */
	public function testADeliveryWithoutAPersonIsNotAsked(): void {
		$event = $this->request(payload: ['caseId' => 'case-1']);

		$this->service(withGate: true)->ingestDeliveryRequest(request: $event);

		$this->assertArrayNotHasKey('optOut', $this->saved['data']['delivery']);
		$this->assertSame(['caseId' => 'case-1'], $this->saved['data']['payload']);
		$this->assertSame([], $this->fx->log->rows);

	}//end testADeliveryWithoutAPersonIsNotAsked()

	/**
	 * Without the gate a personal non-exempt delivery is refused, never sent unasked.
	 *
	 * @return void
	 */
	public function testWithoutTheGateAPersonalDeliveryFailsClosed(): void {
		$result = $this->service(withGate: false)->ingestDeliveryRequest(
			request: $this->request(payload: ['recipient' => 'jan@example.org', 'category' => 'service'])
		);

		$this->assertSame('authority-unavailable', $result['refusal']['code']);
		$this->assertSame(0, $this->subscriptionReads);

	}//end testWithoutTheGateAPersonalDeliveryFailsClosed()

	/**
	 * A delivery request from dossiq.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return DeliveryRequestedEvent The request.
	 */
	private function request(array $payload): DeliveryRequestedEvent {
		return new DeliveryRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-1',
			subjectLabel: 'Kapvergunning',
			deliveryKind: 'notice',
			channel: 'webhook',
			payload: $payload,
			correlationId: 'corr-1',
		);

	}//end request()

	/**
	 * The service over the doubles.
	 *
	 * @param bool $withGate Whether the send gate is wired.
	 *
	 * @return EventService The service.
	 */
	private function service(bool $withGate): EventService {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'evt-1');
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				$this->subscriptionReads++;
				return ['results' => []];
			}
		);
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
			sendGate: ($withGate === true ? $this->fx->gate() : null),
		);

	}//end service()

}//end class
