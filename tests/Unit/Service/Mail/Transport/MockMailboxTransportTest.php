<?php

/**
 * Unit tests for MockMailboxTransport.
 *
 * The fixture-backed transport turns configured messages into ParsedMessage
 * values, hands raw messages to the parser, and honours the poll cursor.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail\Transport;

use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCA\Integriq\Service\Mail\Transport\MockMailboxTransport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Fixture messages through the mock transport; only the parser is a double.
 *
 * @covers \OCA\Integriq\Service\Mail\Transport\MockMailboxTransport
 * @uses   \OCA\Integriq\Service\Mail\ParsedMessage
 * @uses   \OCA\Integriq\Service\Mail\HtmlSanitizer
 */
class MockMailboxTransportTest extends TestCase {

	/**
	 * The parser double.
	 *
	 * @var MessageParser&MockObject
	 */
	private MessageParser&MockObject $parser;

	/**
	 * The subject.
	 *
	 * @var MockMailboxTransport
	 */
	private MockMailboxTransport $transport;

	/**
	 * Build the subject over a parser double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = $this->createMock(MessageParser::class);
		$this->transport = new MockMailboxTransport(messageParser: $this->parser);

	}//end setUp()

	/**
	 * The transport names itself "mock" and is always usable.
	 *
	 * @return void
	 */
	public function testProtocolIsMockAndAlwaysUsable(): void {
		$this->assertSame('mock', $this->transport->getProtocol());
		$this->assertTrue($this->transport->isUsable());

	}//end testProtocolIsMockAndAlwaysUsable()

	/**
	 * A structured fixture entry becomes a ParsedMessage field for field, with
	 * defaults for what it leaves out and the HTML body sanitised.
	 *
	 * @return void
	 */
	public function testStructuredEntryBecomesAParsedMessage(): void {
		$messages = $this->transport->fetch(
			[
				'fixture' => [
					'messages' => [
						[
							'messageId' => '<m1@example.test>',
							'from' => 'sender@example.test',
							'to' => 'inbox@example.test',
							'subject' => 'Factuur',
							'receivedAt' => '2026-01-02T10:00:00+00:00',
							'bodyText' => 'Zie bijlage',
							'bodyHtml' => '<p>Zie bijlage</p><script>alert(1)</script>',
							'attachments' => [
								['name' => 'f.pdf', 'mime' => 'application/pdf', 'content' => 'PDF!'],
								['content' => 'xy'],
								'not an attachment',
							],
						],
						'not an entry',
						[],
					],
				],
			],
			null
		);

		$this->assertCount(2, $messages);

		$first = $messages[0];
		$this->assertSame('<m1@example.test>', $first->getMessageId());
		$this->assertSame('sender@example.test', $first->getFrom());
		$this->assertSame(['inbox@example.test'], $first->getTo());
		$this->assertSame('Factuur', $first->getSubject());
		$this->assertSame('2026-01-02T10:00:00+00:00', $first->getReceivedAt());
		$this->assertSame('Zie bijlage', $first->getBodyText());
		$this->assertStringContainsString('<p>Zie bijlage</p>', $first->getBodyHtml());
		$this->assertStringNotContainsString('<script', $first->getBodyHtml());
		$this->assertSame(
			[
				['name' => 'f.pdf', 'mime' => 'application/pdf', 'size' => 4, 'content' => 'PDF!'],
				['name' => 'attachment', 'mime' => 'application/octet-stream', 'size' => 2, 'content' => 'xy'],
			],
			$first->getAttachments()
		);

		$second = $messages[1];
		$this->assertSame('fixture-2', $second->getMessageId());
		$this->assertSame([], $second->getTo());
		$this->assertNull($second->getReceivedAt());
		$this->assertSame('', $second->getBodyHtml());

	}//end testStructuredEntryBecomesAParsedMessage()

	/**
	 * A raw entry is handed to the parser under its filename (or the default)
	 * and the parser's result is returned.
	 *
	 * @return void
	 */
	public function testRawEntryIsHandedToTheParser(): void {
		$named = new ParsedMessage(messageId: 'raw-1', from: '', to: [], subject: 'Named', receivedAt: null, bodyText: '', bodyHtml: '', attachments: []);
		$unnamed = new ParsedMessage(messageId: 'raw-2', from: '', to: [], subject: 'Unnamed', receivedAt: null, bodyText: '', bodyHtml: '', attachments: []);
		$this->parser->expects($this->exactly(2))->method('parse')->willReturnMap(
			[
				['mail.eml', "Subject: Named\r\n\r\nbody", $named],
				['fixture.eml', "Subject: Unnamed\r\n\r\nbody", $unnamed],
			]
		);

		$messages = $this->transport->fetch(
			[
				'fixture' => [
					'messages' => [
						['filename' => 'mail.eml', 'raw' => "Subject: Named\r\n\r\nbody"],
						['raw' => "Subject: Unnamed\r\n\r\nbody"],
					],
				],
			],
			null
		);

		$this->assertSame([$named, $unnamed], $messages);

	}//end testRawEntryIsHandedToTheParser()

	/**
	 * With a cursor only messages received after it are returned; messages
	 * without a receipt time always pass; a blank cursor filters nothing.
	 *
	 * @return void
	 */
	public function testCursorKeepsOnlyLaterMessages(): void {
		$configuration = [
			'fixture' => [
				'messages' => [
					['messageId' => 'old', 'receivedAt' => '2026-01-01T00:00:00+00:00'],
					['messageId' => 'same', 'receivedAt' => '2026-01-05T00:00:00+00:00'],
					['messageId' => 'new', 'receivedAt' => '2026-01-10T00:00:00+00:00'],
					['messageId' => 'undated'],
				],
			],
		];

		$after = $this->transport->fetch($configuration, '2026-01-05T00:00:00+00:00');
		$blank = $this->transport->fetch($configuration, '  ');

		$this->assertSame(['new', 'undated'], array_map(static fn (ParsedMessage $m): string => $m->getMessageId(), $after));
		$this->assertCount(4, $blank);

	}//end testCursorKeepsOnlyLaterMessages()

	/**
	 * A missing fixture or a non-list of messages yields no messages.
	 *
	 * @return void
	 */
	public function testMissingOrMalformedFixtureYieldsNothing(): void {
		$this->assertSame([], $this->transport->fetch([], null));
		$this->assertSame([], $this->transport->fetch(['fixture' => ['messages' => 'none']], null));

	}//end testMissingOrMalformedFixtureYieldsNothing()
}//end class
