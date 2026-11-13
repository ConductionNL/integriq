<?php
/**
 * Choosing the mailbox transport, and polling with a stored cursor and case pattern.
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

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\MockMailboxTransport;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Protocol selection (case and space insensitive, refused when unknown or
 * unusable on this host) and a poll that resumes from its cursor, passes the
 * case pattern on and only moves the cursor forward.
 *
 * @covers \OCA\Integriq\Service\Mail\MailboxSourceHandler
 * @uses   \OCA\Integriq\Service\Mail\ParsedMessage
 * @uses   \OCA\Integriq\Exception\MailboxTransportException
 */
class MailboxSourceHandlerTransportTest extends TestCase {

	/**
	 * @var ImapMailboxTransport&MockObject
	 */
	private ImapMailboxTransport&MockObject $imap;

	/**
	 * @var GraphMailboxTransport&MockObject
	 */
	private GraphMailboxTransport&MockObject $graph;

	/**
	 * @var MailIntakeService&MockObject
	 */
	private MailIntakeService&MockObject $intake;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The handler under test.
	 *
	 * @var MailboxSourceHandler
	 */
	private MailboxSourceHandler $handler;

	/**
	 * Build the handler over mocked transports and services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->imap    = $this->createMock(originalClassName: ImapMailboxTransport::class);
		$this->graph   = $this->createMock(originalClassName: GraphMailboxTransport::class);
		$this->intake  = $this->getMockBuilder(className: MailIntakeService::class)
			->disableOriginalConstructor()
			->onlyMethods(methods: ['findByMessageId', 'intake'])
			->getMock();
		$this->objects = $this->getMockBuilder(className: ObjectService::class)->disableOriginalConstructor()->getMock();

		$this->handler = new MailboxSourceHandler(
			objectService: $this->objects,
			intakeService: $this->intake,
			mockTransport: $this->createMock(originalClassName: MockMailboxTransport::class),
			imapTransport: $this->imap,
			graphTransport: $this->graph,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A message received at the given moment.
	 *
	 * @param string      $id         The message id.
	 * @param string|null $receivedAt The receipt moment.
	 *
	 * @return ParsedMessage
	 */
	private function message(string $id, ?string $receivedAt): ParsedMessage {
		return new ParsedMessage(
			messageId: $id,
			from: 'burger@example.org',
			to: [],
			subject: 'Vraag',
			receivedAt: $receivedAt,
			bodyText: '',
			bodyHtml: ''
		);
	}//end message()

	/**
	 * A mailbox source with the given configuration.
	 *
	 * @param mixed $configuration The configuration.
	 *
	 * @return ObjectEntity
	 */
	private function source(mixed $configuration): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('source-1');
		$entity->setObject(['type' => 'mailbox', 'configuration' => $configuration]);
		return $entity;
	}//end source()

	/**
	 * The protocol picks the IMAP or Graph binding, ignoring case and spaces.
	 *
	 * @return void
	 */
	public function testTheProtocolPicksTheBinding(): void {
		$this->imap->method('isUsable')->willReturn(true);
		$this->graph->method('isUsable')->willReturn(true);

		$this->assertSame(expected: $this->imap, actual: $this->handler->resolveTransport(configuration: ['protocol' => 'imap']));
		$this->assertSame(expected: $this->graph, actual: $this->handler->resolveTransport(configuration: ['protocol' => ' GRAPH ']));
	}//end testTheProtocolPicksTheBinding()

	/**
	 * A binding that cannot run on this host refuses the poll.
	 *
	 * @return void
	 */
	public function testAnUnusableBindingIsRefused(): void {
		$this->graph->method('isUsable')->willReturn(false);

		$this->expectException(exception: MailboxTransportException::class);
		$this->expectExceptionMessage(message: 'The graph binding cannot run on this host, so the mailbox was not polled.');
		$this->handler->resolveTransport(configuration: ['protocol' => 'graph']);
	}//end testAnUnusableBindingIsRefused()

	/**
	 * A configuration that is not an array has no protocol.
	 *
	 * @return void
	 */
	public function testANonArrayConfigurationHasNoProtocol(): void {
		$this->expectException(exception: MailboxTransportException::class);
		$this->expectExceptionMessage(message: 'Unknown mailbox protocol "": integriq speaks imap and graph.');
		$this->handler->poll(source: $this->source(configuration: 'broken'));
	}//end testANonArrayConfigurationHasNoProtocol()

	/**
	 * A poll resumes from the stored cursor, passes the case pattern on, skips
	 * known messages and stores the newest receipt moment as the new cursor.
	 *
	 * @return void
	 */
	public function testAPollResumesFromItsCursor(): void {
		$configuration = ['protocol' => 'imap', 'sinceCursor' => '2026-09-15T10:00:00+00:00', 'casePattern' => 'ZAAK-\d+'];
		$this->imap->method('isUsable')->willReturn(true);
		$this->imap->expects($this->once())->method('fetch')->with($configuration, '2026-09-15T10:00:00+00:00')->willReturn([
			$this->message(id: 'known', receivedAt: '2026-09-15T09:00:00+00:00'),
			$this->message(id: 'newer', receivedAt: '2026-09-15T12:00:00+00:00'),
			$this->message(id: 'older', receivedAt: '2026-09-15T11:00:00+00:00'),
			$this->message(id: 'undated', receivedAt: ' '),
		]);
		$this->intake->method('findByMessageId')->willReturnCallback(
			fn (string $sourceId, string $messageId): ?ObjectEntity => match ($messageId) {
				'known' => new ObjectEntity(),
				default => null,
			}
		);
		$taken = [];
		$this->intake->method('intake')->willReturnCallback(
			function (string $sourceId, ParsedMessage $message, ?string $pattern) use (&$taken): ObjectEntity {
				$taken[] = [$sourceId, $message->getMessageId(), $pattern];
				return new ObjectEntity();
			}
		);
		$this->objects->expects($this->once())->method('saveObject')->with(
			['type' => 'mailbox', 'configuration' => ['sinceCursor' => '2026-09-15T12:00:00+00:00'] + $configuration],
			MailIntakeService::REGISTER,
			'source',
			'source-1'
		);

		$result = $this->handler->poll(source: $this->source(configuration: $configuration));

		$this->assertSame(expected: ['created' => 3, 'skipped' => 1, 'cursor' => '2026-09-15T12:00:00+00:00'], actual: $result);
		$this->assertSame(
			expected: [['source-1', 'newer', 'ZAAK-\d+'], ['source-1', 'older', 'ZAAK-\d+'], ['source-1', 'undated', 'ZAAK-\d+']],
			actual: $taken
		);
	}//end testAPollResumesFromItsCursor()

	/**
	 * A poll that brings nothing newer leaves the stored cursor alone.
	 *
	 * @return void
	 */
	public function testANoNewsPollKeepsTheCursor(): void {
		$this->imap->method('isUsable')->willReturn(true);
		$this->imap->method('fetch')->willReturn([]);
		$this->objects->expects($this->never())->method('saveObject');

		$result = $this->handler->poll(source: $this->source(configuration: ['protocol' => 'imap', 'sinceCursor' => '2026-01-01']));

		$this->assertSame(expected: ['created' => 0, 'skipped' => 0, 'cursor' => '2026-01-01'], actual: $result);
	}//end testANoNewsPollKeepsTheCursor()
}//end class
