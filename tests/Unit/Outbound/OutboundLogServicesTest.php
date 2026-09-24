<?php

/**
 * Unit tests for the body reader, the retry, the forward and the
 * last-contact query.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use InvalidArgumentException;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\ChannelReportingCapabilities;
use OCA\Integriq\Outbound\ForwardService;
use OCA\Integriq\Outbound\LastContactQuery;
use OCA\Integriq\Outbound\MessageBodyReader;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundRetryService;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the acts on the log: read, retry, forward, ask.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
 */
class OutboundLogServicesTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The records the double holds, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * How many records the double has created.
	 *
	 * @var int
	 */
	private int $created = 0;

	/**
	 * The recorder under test's collaborator.
	 *
	 * @var MessageRecorder
	 */
	private MessageRecorder $recorder;

	/**
	 * Set up a double that behaves like storage, keyed by uuid.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->records = [];
		$this->created = 0;

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register = '', string $schema = '', ?string $uuid = null) {
				if ($uuid === null || $uuid === '') {
					$this->created++;
					$uuid = 'message-' . $this->created;
				}

				$this->records[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id) {
				return ObjectServiceMockBuilder::objectEntity($this, ($this->records[$id] ?? []), $id);
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->records as $uuid => $record) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $record, $uuid);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$this->recorder = new MessageRecorder(
			$this->objectService,
			new BodyRedactor(),
			new ChannelReportingCapabilities(),
		);

	}//end setUp()

	/**
	 * A handler without the read-body action sees that a letter went out and
	 * not what it said.
	 *
	 * @return void
	 */
	public function testAHandlerWithoutThePermissionCannotReadTheBody(): void {
		$uuid = $this->recordedMessage();
		$actionAuth = $this->actionAuth(false);
		$reader = new MessageBodyReader($this->recorder, $this->objectService, $actionAuth);

		$this->expectException(OCSForbiddenException::class);
		$reader->read($uuid, $this->user('burger'));

	}//end testAHandlerWithoutThePermissionCannotReadTheBody()

	/**
	 * Reading a body is itself recorded, with who read it.
	 *
	 * @return void
	 */
	public function testReadingABodyIsRecorded(): void {
		$uuid = $this->recordedMessage();
		$reader = new MessageBodyReader($this->recorder, $this->objectService, $this->actionAuth(true));

		$body = $reader->read($uuid, $this->user('archivaris'));

		$this->assertStringContainsString('Wij hebben uw brief ontvangen', $body['body']);
		$this->assertCount(1, $this->records[$uuid]['bodyReads']);
		$this->assertSame('archivaris', $this->records[$uuid]['bodyReads'][0]['by']);

	}//end testReadingABodyIsRecorded()

	/**
	 * A message that failed during an outage goes out afterwards, and the
	 * attempt is appended to the same record.
	 *
	 * @return void
	 */
	public function testARetryAppendsAnAttemptToTheSameRecord(): void {
		$uuid = $this->recordedMessage();
		$this->recorder->stepFailed($uuid, 'transport', 'connection refused');

		$service = new OutboundRetryService($this->recorder, $this->eventService(1));
		$result = $service->retry($uuid, 'beheerder');

		$this->assertTrue($result['succeeded']);
		$this->assertSame(['jan@example.org'], $result['retried']);
		$this->assertCount(1, $this->records[$uuid]['attempts']);
		$this->assertSame('beheerder', $this->records[$uuid]['attempts'][0]['by']);
		$this->assertSame(MessageRecorder::STATUS_SENT, $this->records[$uuid]['status']);

	}//end testARetryAppendsAnAttemptToTheSameRecord()

	/**
	 * A retry nothing is subscribed to is a refusal, not a success: accepted
	 * and routed is a different fact from accepted and unrouted.
	 *
	 * @return void
	 */
	public function testARetryNoRouteTakesIsNotASuccess(): void {
		$uuid = $this->recordedMessage();
		$this->recorder->stepFailed($uuid, 'transport', 'connection refused');

		$service = new OutboundRetryService($this->recorder, $this->eventService(0));
		$result = $service->retry($uuid, 'beheerder');

		$this->assertFalse($result['succeeded']);
		$this->assertStringContainsString('no delivery route', $result['detail']);
		$this->assertSame(MessageRecorder::STATUS_FAILED, $this->records[$uuid]['status']);

	}//end testARetryNoRouteTakesIsNotASuccess()

	/**
	 * A bulk retry reports every item, and skips none silently.
	 *
	 * @return void
	 */
	public function testABulkRetryReportsEachItem(): void {
		$failed = $this->recordedMessage();
		$this->recorder->stepFailed($failed, 'transport', 'connection refused');
		$sent = $this->recordedMessage();
		$this->recorder->handedOver($sent, 'jan@example.org');

		$service = new OutboundRetryService($this->recorder, $this->eventService(1));
		$result = $service->retryAll([$failed, $sent], 'beheerder');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame(1, $result['failed']);
		$this->assertCount(2, $result['items']);
		$this->assertStringContainsString('Nothing to retry', $result['items'][1]['detail']);

	}//end testABulkRetryReportsEachItem()

	/**
	 * A forward is its own record, linked from both ends, and the original is
	 * left as it was because it is the evidence.
	 *
	 * @return void
	 */
	public function testAForwardIsItsOwnLinkedRecord(): void {
		$uuid = $this->recordedMessage();
		$this->recorder->handedOver($uuid, 'jan@example.org');
		$originalBefore = $this->records[$uuid];

		$service = new ForwardService($this->recorder, $this->objectService);
		$forward = $service->forward(
			$uuid,
			[['address' => 'info@anderegemeente.example', 'name' => 'Andere gemeente']],
			'behandelaar',
			'Doorgezonden op grond van artikel 2:3 Awb.'
		);

		$forwardUuid = (string)$forward->getUuid();
		$this->assertNotSame($uuid, $forwardUuid);
		$this->assertSame($uuid, $this->records[$forwardUuid]['forwardedFrom']);
		$this->assertSame($forwardUuid, $this->records[$uuid]['forwardedTo'][0]['message']);
		$this->assertStringStartsWith('Fwd: ', $this->records[$forwardUuid]['subject']);
		$this->assertStringContainsString('artikel 2:3 Awb', $this->records[$forwardUuid]['body']);

		// Everything but the link is untouched on the original.
		$this->assertSame($originalBefore['recipients'], $this->records[$uuid]['recipients']);
		$this->assertSame($originalBefore['steps'], $this->records[$uuid]['steps']);
		$this->assertSame($originalBefore['body'], $this->records[$uuid]['body']);

	}//end testAForwardIsItsOwnLinkedRecord()

	/**
	 * A forward to nobody is refused rather than recorded as a forward.
	 *
	 * @return void
	 */
	public function testAForwardWithoutARecipientIsRefused(): void {
		$uuid = $this->recordedMessage();
		$service = new ForwardService($this->recorder, $this->objectService);

		$this->expectException(InvalidArgumentException::class);
		$service->forward($uuid, [], 'behandelaar');

	}//end testAForwardWithoutARecipientIsRefused()

	/**
	 * The last-contact query answers the newest message that actually left.
	 *
	 * @return void
	 */
	public function testLastContactAnswersTheNewestMessageThatLeft(): void {
		$older = $this->recordedMessage();
		$this->recorder->handedOver($older, 'jan@example.org');
		$this->records[$older]['recipients'][0]['handedOverAt'] = '2026-08-01T10:00:00+02:00';

		$newer = $this->recordedMessage();
		$this->recorder->handedOver($newer, 'jan@example.org');
		$this->records[$newer]['recipients'][0]['handedOverAt'] = '2026-09-10T10:00:00+02:00';

		$failed = $this->recordedMessage();
		$this->recorder->stepFailed($failed, 'transport', 'connection refused');
		$this->records[$failed]['recipients'][0]['handedOverAt'] = '2026-09-17T10:00:00+02:00';

		$query = new LastContactQuery($this->objectService);
		$answer = $query->lastContact('zaak/2026-0042', 'jan@example.org');

		$this->assertTrue($answer['contacted']);
		$this->assertSame('2026-09-10T10:00:00+02:00', $answer['at']);
		$this->assertSame($newer, $answer['message']);

	}//end testLastContactAnswersTheNewestMessageThatLeft()

	/**
	 * Never contacted answers never, and borrows no other date.
	 *
	 * @return void
	 */
	public function testNeverContactedAnswersNever(): void {
		$uuid = $this->recordedMessage();
		$this->recorder->handedOver($uuid, 'jan@example.org');

		$query = new LastContactQuery($this->objectService);
		$answer = $query->lastContact('zaak/2026-0042', 'niemand@example.org');

		$this->assertFalse($answer['contacted']);
		$this->assertNull($answer['at']);
		$this->assertNull($answer['message']);

	}//end testNeverContactedAnswersNever()

	/**
	 * Record one message with a single recipient.
	 *
	 * @return string The record uuid.
	 */
	private function recordedMessage(): string {
		$entity = $this->recorder->start(
			'zaak/2026-0042',
			'mail',
			'Ontvangstbevestiging',
			'Wij hebben uw brief ontvangen.',
			[['address' => 'jan@example.org', 'name' => 'Jan Burger', 'accountId' => 'jan']],
			['sourceApp' => 'dossiq']
		);

		return (string)$entity->getUuid();

	}//end recordedMessage()

	/**
	 * An action gate that allows or refuses.
	 *
	 * @param bool $allowed Whether the principal may read bodies.
	 *
	 * @return ActionAuthService|MockObject The gate.
	 */
	private function actionAuth(bool $allowed) {
		$actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();

		if ($allowed === false) {
			$actionAuth->method('requireAction')->willThrowException(
				new OCSForbiddenException("Action 'outbound.read-body' requires admin rights")
			);
		}

		return $actionAuth;

	}//end actionAuth()

	/**
	 * An event service whose ingest reports a number of matched routes.
	 *
	 * @param int $matched How many delivery routes pick the retry up.
	 *
	 * @return EventService|MockObject The service.
	 */
	private function eventService(int $matched) {
		$eventService = $this->getMockBuilder(EventService::class)
			->disableOriginalConstructor()
			->onlyMethods(['ingestDeliveryRequest'])
			->getMock();
		$eventService->method('ingestDeliveryRequest')->willReturnCallback(
			function (DeliveryRequestedEvent $request) use ($matched): array {
				return [
					'event' => ObjectServiceMockBuilder::objectEntity($this, [], 'event-uuid'),
					'messages' => array_fill(0, $matched, 'route'),
				];
			}
		);

		return $eventService;

	}//end eventService()

	/**
	 * A user.
	 *
	 * @param string $uid The account id.
	 *
	 * @return IUser|MockObject The user.
	 */
	private function user(string $uid) {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;

	}//end user()

}//end class
