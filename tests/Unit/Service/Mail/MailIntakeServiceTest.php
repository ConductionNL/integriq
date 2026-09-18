<?php

/**
 * Unit tests for MailIntakeService, the typed offer and the intake hand-off.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use InvalidArgumentException;
use OCA\Integriq\Event\MessageReceivedEvent;
use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that intake offers every message and records what came back.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-received-message-is-offered-to-the-owning-app-as-a-typed-event-req-mail-003
 */
class MailIntakeServiceTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The event dispatcher double.
	 *
	 * @var IEventDispatcher|MockObject
	 */
	private $eventDispatcher;

	/**
	 * The filinq hand-off double.
	 *
	 * @var IntakeDocumentDispatcher|MockObject
	 */
	private $intakeDispatcher;

	/**
	 * Every payload the service saved, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Rows findAll() answers with.
	 *
	 * @var array<int,mixed>
	 */
	private array $existingRows = [];

	/**
	 * The service under test.
	 *
	 * @var MailIntakeService
	 */
	private MailIntakeService $service;

	/**
	 * Set up the service with recording doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->existingRows = [];
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				return ['results' => $this->existingRows, 'total' => count($this->existingRows)];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'message-uuid');
			}
		);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->intakeDispatcher = $this->getMockBuilder(IntakeDocumentDispatcher::class)
			->disableOriginalConstructor()
			->onlyMethods(['dispatch', 'isAvailable'])
			->getMock();
		$this->intakeDispatcher->method('isAvailable')->willReturn(true);

		$this->service = new MailIntakeService(
			$this->objectService,
			$this->eventDispatcher,
			new CaseReferenceDetector(),
			$this->intakeDispatcher,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A message with a case number is linked by the owning app.
	 *
	 * @return void
	 */
	public function testMessageWithAReferenceIsLinkedByTheOwningApp(): void {
		$this->listenWith(
			static function (MessageReceivedEvent $event): void {
				$event->setOutcome(MessageReceivedEvent::OUTCOME_LINKED, 'zaak/abc-123');
			}
		);

		$this->service->intake('source-1', $this->message('Vraag over ZAAK-2026-0042'));

		$final = end($this->saved);
		$this->assertSame(MailIntakeService::STATUS_LINKED, $final['status']);
		$this->assertSame('zaak/abc-123', $final['linkedObject']);
		$this->assertSame('ZAAK-2026-0042', $final['detectedReference']);

	}//end testMessageWithAReferenceIsLinkedByTheOwningApp()

	/**
	 * A message without a reference becomes a case.
	 *
	 * @return void
	 */
	public function testMessageWithoutAReferenceBecomesACase(): void {
		$received = null;
		$this->listenWith(
			static function (MessageReceivedEvent $event) use (&$received): void {
				$received = $event;
				$event->setOutcome(MessageReceivedEvent::OUTCOME_CREATED, 'zaak/new-1');
			}
		);

		$this->service->intake('source-1', $this->message('Een nieuwe vraag'));

		$this->assertNull($received?->getDetectedReference());
		$final = end($this->saved);
		$this->assertSame(MailIntakeService::STATUS_CASE_CREATED, $final['status']);
		$this->assertSame('zaak/new-1', $final['linkedObject']);

	}//end testMessageWithoutAReferenceBecomesACase()

	/**
	 * Nobody claims the message: it is unassigned and its attachment is
	 * offered to the document intake inbox.
	 *
	 * @return void
	 */
	public function testUnclaimedMessageIsUnassignedAndAttachmentsAreOffered(): void {
		$this->listenWith(static function (MessageReceivedEvent $event): void {
		});

		$handed = [];
		$this->intakeDispatcher->method('dispatch')->willReturnCallback(
			static function (array $payload) use (&$handed): bool {
				$handed[] = $payload;
				return true;
			}
		);

		$this->service->intake('source-1', $this->message('Geen kenmerk', true));

		$final = end($this->saved);
		$this->assertSame(MailIntakeService::STATUS_UNASSIGNED, $final['status']);
		$this->assertSame(1, $final['handedToIntake']);
		$this->assertCount(1, $handed);
		$this->assertSame('mail', $handed[0]['channel']);
		$this->assertSame('bewijs.txt', $handed[0]['name']);
		$this->assertSame('message-uuid', $handed[0]['sourceRef']);
		$this->assertSame('bewijsstuk', base64_decode($handed[0]['content'], true));

	}//end testUnclaimedMessageIsUnassignedAndAttachmentsAreOffered()

	/**
	 * A decline is not a claim: the message still needs somewhere to go.
	 *
	 * @return void
	 */
	public function testDeclinedMessageIsUnassigned(): void {
		$this->listenWith(
			static function (MessageReceivedEvent $event): void {
				$event->setOutcome(MessageReceivedEvent::OUTCOME_DECLINED);
			}
		);
		$this->intakeDispatcher->method('dispatch')->willReturn(true);

		$this->service->intake('source-1', $this->message('Niet van ons'));

		$final = end($this->saved);
		$this->assertSame(MailIntakeService::STATUS_UNASSIGNED, $final['status']);
		$this->assertSame('declined', $final['outcome']);

	}//end testDeclinedMessageIsUnassigned()

	/**
	 * When nothing takes the attachments they stay in integriq, and the
	 * message says so rather than claiming a hand-off.
	 *
	 * @return void
	 */
	public function testAttachmentsStayWhenNothingTakesThem(): void {
		$this->listenWith(static function (MessageReceivedEvent $event): void {
		});
		$this->intakeDispatcher->method('dispatch')->willReturn(false);

		$this->service->intake('source-1', $this->message('Geen kenmerk', true));

		$final = end($this->saved);
		$this->assertSame(0, $final['handedToIntake']);
		$this->assertSame(MailIntakeService::STATUS_UNASSIGNED, $final['status']);

	}//end testAttachmentsStayWhenNothingTakesThem()

	/**
	 * A message already stored for this source is not stored again.
	 *
	 * @return void
	 */
	public function testKnownMessageIsNotStoredTwice(): void {
		$this->existingRows = [
			ObjectServiceMockBuilder::objectEntity($this, ['messageId' => 'id-1'], 'existing-uuid'),
		];
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$entity = $this->service->intake('source-1', $this->message('Vraag over ZAAK-2026-0042'));

		$this->assertSame('existing-uuid', $entity->getUuid());
		$this->assertSame([], $this->saved);

	}//end testKnownMessageIsNotStoredTwice()

	/**
	 * The event refuses an outcome it does not know.
	 *
	 * @return void
	 */
	public function testEventRefusesAnUnknownOutcome(): void {
		$event = new MessageReceivedEvent([], null);

		$this->expectException(InvalidArgumentException::class);
		$event->setOutcome('filed-away', 'zaak/1');

	}//end testEventRefusesAnUnknownOutcome()

	/**
	 * A claiming outcome without a reference is refused, because "linked to
	 * nothing" reads as linked everywhere downstream.
	 *
	 * @return void
	 */
	public function testEventRefusesAClaimWithoutAReference(): void {
		$event = new MessageReceivedEvent([], null);

		$this->expectException(InvalidArgumentException::class);
		$event->setOutcome(MessageReceivedEvent::OUTCOME_LINKED, '   ');

	}//end testEventRefusesAClaimWithoutAReference()

	/**
	 * Wire a listener into the dispatcher double.
	 *
	 * @param callable $listener What the listener does with the event.
	 *
	 * @return void
	 */
	private function listenWith(callable $listener): void {
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				if (($event instanceof MessageReceivedEvent) === true) {
					$listener($event);
				}
			}
		);

	}//end listenWith()

	/**
	 * A parsed message for the tests.
	 *
	 * @param string $subject The subject line.
	 * @param bool $withAttachment Whether the message carries one attachment.
	 *
	 * @return ParsedMessage The message.
	 */
	private function message(string $subject, bool $withAttachment = false): ParsedMessage {
		$attachments = [];
		if ($withAttachment === true) {
			$attachments[] = [
				'name' => 'bewijs.txt',
				'mime' => 'text/plain',
				'size' => 10,
				'content' => 'bewijsstuk',
			];
		}

		return new ParsedMessage(
			'id-1',
			'burger@example.org',
			['zaken@gemeente.example'],
			$subject,
			'2026-09-15T10:11:12+02:00',
			'Tekst van het bericht.',
			'',
			$attachments,
		);

	}//end message()

}//end class
