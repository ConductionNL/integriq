<?php
/**
 * Handing a mail attachment to the document intake inbox, and every way that can decline.
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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The dispatcher builds the intake event reflectively from one payload array,
 * and declines with a log line when the inbox app is absent or its event
 * class does not fit; a parsed message can carry a warning in a copy.
 *
 * @covers \OCA\Integriq\Service\Mail\IntakeDocumentDispatcher
 * @covers \OCA\Integriq\Service\Mail\ParsedMessage
 */
class IntakeDocumentDispatcherTest extends TestCase {

	/**
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $events;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->events = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A dispatcher for the given event class.
	 *
	 * @param string $eventClass The intake event class.
	 *
	 * @return IntakeDocumentDispatcher
	 */
	private function dispatcher(string $eventClass): IntakeDocumentDispatcher {
		return new IntakeDocumentDispatcher(eventDispatcher: $this->events, logger: $this->logger, eventClass: $eventClass);
	}//end dispatcher()

	/**
	 * A fitting event class receives the payload and is dispatched.
	 *
	 * @return void
	 */
	public function testAFittingEventIsDispatchedWithThePayload(): void {
		$payload = ['channel' => 'mail', 'name' => 'brief.pdf'];
		$this->events->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(callback: static fn (Event $event): bool => $event instanceof IntakeFixturePayloadEvent && $event->payload === $payload)
		);

		$dispatcher = $this->dispatcher(eventClass: IntakeFixturePayloadEvent::class);
		$this->assertTrue(condition: $dispatcher->isAvailable());
		$this->assertTrue(condition: $dispatcher->dispatch(payload: $payload));
	}//end testAFittingEventIsDispatchedWithThePayload()

	/**
	 * Without the inbox app the attachment stays in integriq.
	 *
	 * @return void
	 */
	public function testAnAbsentInboxDeclines(): void {
		$this->events->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->once())->method('info')->with(
			'Integriq mail intake: no document intake inbox on this instance, the attachment stays in integriq.',
			['channel' => 'digitalpost']
		);

		$dispatcher = $this->dispatcher(eventClass: 'OCA\\Nowhere\\MissingEvent');
		$this->assertFalse(condition: $dispatcher->isAvailable());
		$this->assertFalse(condition: $dispatcher->dispatch(payload: ['channel' => 'digitalpost']));
	}//end testAnAbsentInboxDeclines()

	/**
	 * Classes that do not take exactly one payload argument, or are not events, are refused.
	 *
	 * @return void
	 */
	public function testUnfittingClassesAreRefused(): void {
		$this->events->expects($this->never())->method('dispatchTyped');
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		foreach ([IntakeFixtureTwoArgumentEvent::class, IntakeFixtureNoConstructor::class, IntakeFixtureNotAnEvent::class] as $class) {
			$this->assertFalse(condition: $this->dispatcher(eventClass: $class)->dispatch(payload: []));
		}

		$this->assertSame(
			expected: [
				['Integriq mail intake: the document intake event does not take a single payload array.', ['class' => IntakeFixtureTwoArgumentEvent::class]],
				['Integriq mail intake: the document intake event does not take a single payload array.', ['class' => IntakeFixtureNoConstructor::class]],
				['Integriq mail intake: the document intake class is not an event.', ['class' => IntakeFixtureNotAnEvent::class]],
			],
			actual: $warnings
		);
	}//end testUnfittingClassesAreRefused()

	/**
	 * An event whose constructor throws is reported, never thrown.
	 *
	 * @return void
	 */
	public function testAThrowingConstructorIsReported(): void {
		$this->events->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->once())->method('warning')->with(
			'Integriq mail intake: the document intake event could not be constructed.',
			['reason' => 'payload refused']
		);

		$this->assertFalse(condition: $this->dispatcher(eventClass: IntakeFixtureThrowingEvent::class)->dispatch(payload: []));
	}//end testAThrowingConstructorIsReported()

	/**
	 * A warning is carried by a copy; the original message keeps none.
	 *
	 * @return void
	 */
	public function testAParsedMessageCarriesAWarningInACopy(): void {
		$message = new ParsedMessage(
			messageId: '<m1@x>',
			from: 'a@b.nl',
			to: ['c@d.nl'],
			subject: 'Bezwaar',
			receivedAt: '2026-11-13T10:00:00+00:00',
			bodyText: 'tekst',
			bodyHtml: '<p>tekst</p>',
			attachments: [['name' => 'a.pdf', 'mime' => 'application/pdf', 'content' => 'abc']]
		);

		$copy = $message->withWarning(warning: 'Attachment too large');

		$this->assertNotSame(expected: $message, actual: $copy);
		$this->assertSame(expected: '', actual: $message->toObject()['warning']);
		$expected            = $message->toObject();
		$expected['warning'] = 'Attachment too large';
		$this->assertSame(expected: $expected, actual: $copy->toObject());
		$this->assertSame(expected: 3, actual: $copy->toObject()['attachments'][0]['size']);
	}//end testAParsedMessageCarriesAWarningInACopy()
}//end class

/**
 * A fitting intake event: one payload argument.
 */
class IntakeFixturePayloadEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The payload.
	 */
	public function __construct(public readonly array $payload) {
		parent::__construct();
	}
}//end class

/**
 * An event taking two arguments.
 */
class IntakeFixtureTwoArgumentEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The payload.
	 * @param string              $extra   Something else.
	 */
	public function __construct(array $payload, string $extra) {
		parent::__construct();
	}
}//end class

/**
 * A class without its own constructor.
 */
class IntakeFixtureNoConstructor {
}//end class

/**
 * One payload argument, but not an event.
 */
class IntakeFixtureNotAnEvent {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The payload.
	 */
	public function __construct(array $payload) {
	}
}//end class

/**
 * An event whose constructor refuses the payload.
 */
class IntakeFixtureThrowingEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @throws RuntimeException Always.
	 */
	public function __construct(array $payload) {
		throw new RuntimeException(message: 'payload refused');
	}
}//end class
