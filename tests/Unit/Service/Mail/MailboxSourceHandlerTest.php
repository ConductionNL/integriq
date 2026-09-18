<?php

/**
 * Unit tests for MailboxSourceHandler and IntakeDocumentDispatcher.
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

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\MockMailboxTransport;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A stand-in for filinq's intake event, so the hand-off can be watched
 * without filinq installed.
 */
class FakeIntakeDocumentReceivedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The intake payload.
	 */
	public function __construct(public readonly array $payload) {
		parent::__construct();

	}//end __construct()

}//end class

/**
 * Tests that a poll is idempotent and that an unusable protocol refuses.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class MailboxSourceHandlerTest extends TestCase {

	/**
	 * The intake service double.
	 *
	 * @var MailIntakeService|MockObject
	 */
	private $intakeService;

	/**
	 * Messages the intake service was asked to take in.
	 *
	 * @var array<int,string>
	 */
	private array $taken = [];

	/**
	 * Message ids the intake service already knows.
	 *
	 * @var array<int,string>
	 */
	private array $known = [];

	/**
	 * The handler under test.
	 *
	 * @var MailboxSourceHandler
	 */
	private MailboxSourceHandler $handler;

	/**
	 * Set up the handler with the real mock transport.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->taken = [];
		$this->known = [];

		$this->intakeService = $this->getMockBuilder(MailIntakeService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByMessageId', 'intake'])
			->getMock();
		$this->intakeService->method('findByMessageId')->willReturnCallback(
			function (string $sourceId, string $messageId): ?ObjectEntity {
				if (in_array($messageId, $this->known, true) === false) {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['messageId' => $messageId], $messageId);
			}
		);
		$this->intakeService->method('intake')->willReturnCallback(
			function (string $sourceId, ParsedMessage $message): ObjectEntity {
				$this->taken[] = $message->getMessageId();
				$this->known[] = $message->getMessageId();
				return ObjectServiceMockBuilder::objectEntity($this, $message->toObject(), $message->getMessageId());
			}
		);

		$messageParser = new MessageParser(new EmlParser(), new MsgParser());
		$this->handler = new MailboxSourceHandler(
			ObjectServiceMockBuilder::make($this),
			$this->intakeService,
			new MockMailboxTransport($messageParser),
			new ImapMailboxTransport($messageParser),
			new GraphMailboxTransport($this->createMock(IClientService::class)),
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Polling a mock mailbox twice leaves one message per fixture entry.
	 *
	 * @return void
	 */
	public function testPollingTwiceCreatesEachMessageOnce(): void {
		$source = $this->mailbox();

		$first = $this->handler->poll($source);
		$second = $this->handler->poll($source);

		$this->assertSame(3, $first['created']);
		$this->assertSame(0, $first['skipped']);
		$this->assertSame(0, $second['created']);
		$this->assertSame(3, $second['skipped']);
		$this->assertSame(['fixture-1', 'fixture-2', 'fixture-3'], $this->taken);

	}//end testPollingTwiceCreatesEachMessageOnce()

	/**
	 * The cursor moves to the newest message the poll saw.
	 *
	 * @return void
	 */
	public function testCursorAdvancesToTheNewestMessage(): void {
		$result = $this->handler->poll($this->mailbox());

		$this->assertSame('2026-09-15T12:00:00+02:00', $result['cursor']);

	}//end testCursorAdvancesToTheNewestMessage()

	/**
	 * A fixture entry carrying raw bytes goes through the real readers.
	 *
	 * @return void
	 */
	public function testRawFixtureEntryIsParsedByTheRealReader(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'mailbox',
				'configuration' => [
					'mock' => true,
					'fixture' => [
						'messages' => [
							[
								'raw' => "From: a@example.org\r\nSubject: Rauw\r\n"
									. "Message-ID: <raw-1@example.org>\r\n\r\nTekst\r\n",
							],
						],
					],
				],
			],
			'source-1'
		);

		$result = $this->handler->poll($source);

		$this->assertSame(1, $result['created']);
		$this->assertSame(['raw-1@example.org'], $this->taken);

	}//end testRawFixtureEntryIsParsedByTheRealReader()

	/**
	 * A protocol integriq does not speak refuses the poll out loud.
	 *
	 * @return void
	 */
	public function testUnknownProtocolRefusesThePoll(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'mailbox', 'configuration' => ['protocol' => 'pop3']],
			'source-2'
		);

		$this->expectException(MailboxTransportException::class);
		$this->handler->poll($source);

	}//end testUnknownProtocolRefusesThePoll()

	/**
	 * A mailbox in mock mode never reaches a real mail server, whatever
	 * protocol it also names.
	 *
	 * @return void
	 */
	public function testMockModeWinsOverTheProtocol(): void {
		$transport = $this->handler->resolveTransport(['mock' => true, 'protocol' => 'imap']);

		$this->assertSame('mock', $transport->getProtocol());

	}//end testMockModeWinsOverTheProtocol()

	/**
	 * Without filinq the hand-off says so instead of reporting a delivery.
	 *
	 * @return void
	 */
	public function testIntakeHandOffRefusesWhenNothingCanReceiveIt(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$handOff = new IntakeDocumentDispatcher(
			$dispatcher,
			$this->createMock(LoggerInterface::class),
			'OCA\\Filinq\\Event\\ThisClassIsNotInstalled',
		);

		$this->assertFalse($handOff->isAvailable());
		$this->assertFalse($handOff->dispatch(['channel' => 'mail']));

	}//end testIntakeHandOffRefusesWhenNothingCanReceiveIt()

	/**
	 * With a receiving class present the payload is dispatched as a typed event.
	 *
	 * @return void
	 */
	public function testIntakeHandOffDispatchesTheTypedEvent(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);

		$handOff = new IntakeDocumentDispatcher(
			$dispatcher,
			$this->createMock(LoggerInterface::class),
			FakeIntakeDocumentReceivedEvent::class,
		);

		$this->assertTrue($handOff->dispatch(['channel' => 'mail', 'name' => 'bewijs.txt']));
		$this->assertCount(1, $dispatched);
		$this->assertInstanceOf(FakeIntakeDocumentReceivedEvent::class, $dispatched[0]);
		$this->assertSame('bewijs.txt', $dispatched[0]->payload['name']);

	}//end testIntakeHandOffDispatchesTheTypedEvent()

	/**
	 * A mailbox source with three fixture messages.
	 *
	 * @return ObjectEntity The source.
	 */
	private function mailbox(): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'mailbox',
				'configuration' => [
					'mock' => true,
					'protocol' => 'imap',
					'folder' => 'INBOX',
					'fixture' => [
						'messages' => [
							[
								'messageId' => 'fixture-1',
								'from' => 'burger@example.org',
								'to' => ['zaken@gemeente.example'],
								'subject' => 'Vraag over ZAAK-2026-0042',
								'receivedAt' => '2026-09-15T10:00:00+02:00',
								'bodyText' => 'Eerste bericht.',
							],
							[
								'messageId' => 'fixture-2',
								'from' => 'burger@example.org',
								'subject' => 'Tweede vraag',
								'receivedAt' => '2026-09-15T11:00:00+02:00',
								'bodyText' => 'Tweede bericht.',
							],
							[
								'messageId' => 'fixture-3',
								'from' => 'ander@example.org',
								'subject' => 'Derde vraag',
								'receivedAt' => '2026-09-15T12:00:00+02:00',
								'bodyText' => 'Derde bericht.',
							],
						],
					],
				],
			],
			'source-1'
		);

	}//end mailbox()

}//end class
