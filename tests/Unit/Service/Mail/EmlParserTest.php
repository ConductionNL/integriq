<?php

/**
 * Unit tests for EmlParser.
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

use OCA\Integriq\Service\Mail\EmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RFC 5322 reader.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class EmlParserTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var EmlParser
	 */
	private EmlParser $parser;

	/**
	 * Set up the parser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new EmlParser();

	}//end setUp()

	/**
	 * A multipart message yields both bodies and its attachment.
	 *
	 * @return void
	 */
	public function testMultipartMessageYieldsBodiesAndAttachment(): void {
		$message = $this->parser->parse($this->multipartMessage());

		$this->assertSame('burger@example.org', $message->getFrom());
		$this->assertSame(['zaken@gemeente.example'], $message->getTo());
		$this->assertSame('Vraag over ZAAK-2026-0042', $message->getSubject());
		$this->assertSame('<abc-123@example.org>', '<' . $message->getMessageId() . '>');
		$this->assertStringContainsString('Goedemiddag', $message->getBodyText());
		$this->assertStringContainsString('<p>Goedemiddag</p>', $message->getBodyHtml());

		$attachments = $message->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertSame('bewijs.txt', $attachments[0]['name']);
		$this->assertSame('text/plain', $attachments[0]['mime']);
		$this->assertSame('bewijsstuk', $attachments[0]['content']);
		$this->assertSame(10, $attachments[0]['size']);

	}//end testMultipartMessageYieldsBodiesAndAttachment()

	/**
	 * The HTML body loses what would execute.
	 *
	 * @return void
	 */
	public function testHtmlBodyIsSanitized(): void {
		$message = $this->parser->parse($this->multipartMessage());

		$this->assertStringNotContainsString('alert(', $message->getBodyHtml());
		$this->assertStringNotContainsString('<script', $message->getBodyHtml());
		$this->assertStringNotContainsString('onclick', $message->getBodyHtml());

	}//end testHtmlBodyIsSanitized()

	/**
	 * An encoded-word subject comes back readable.
	 *
	 * @return void
	 */
	public function testEncodedWordSubjectIsDecoded(): void {
		$raw = "From: a@example.org\r\nTo: b@example.org\r\n"
			. "Subject: =?UTF-8?B?" . base64_encode('Bezwaar tegen besluit') . "?=\r\n"
			. "Message-ID: <enc-1@example.org>\r\n\r\nTekst\r\n";

		$message = $this->parser->parse($raw);

		$this->assertSame('Bezwaar tegen besluit', $message->getSubject());

	}//end testEncodedWordSubjectIsDecoded()

	/**
	 * A message without a Message-ID gets a stable one from its bytes.
	 *
	 * @return void
	 */
	public function testMessageWithoutIdGetsAContentHash(): void {
		$raw = "From: a@example.org\r\nSubject: Geen id\r\n\r\nTekst\r\n";

		$first = $this->parser->parse($raw);
		$second = $this->parser->parse($raw);

		$this->assertStringStartsWith('sha256:', $first->getMessageId());
		$this->assertSame($first->getMessageId(), $second->getMessageId());

	}//end testMessageWithoutIdGetsAContentHash()

	/**
	 * A quoted-printable body is decoded.
	 *
	 * @return void
	 */
	public function testQuotedPrintableBodyIsDecoded(): void {
		$raw = "From: a@example.org\r\nSubject: QP\r\n"
			. "Content-Type: text/plain; charset=UTF-8\r\n"
			. "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
			. "Beste mevrouw =C3=A9n meneer\r\n";

		$message = $this->parser->parse($raw);

		$this->assertStringContainsString('mevrouw én meneer', $message->getBodyText());

	}//end testQuotedPrintableBodyIsDecoded()

	/**
	 * A folded header is unfolded before it is read.
	 *
	 * @return void
	 */
	public function testFoldedHeaderIsUnfolded(): void {
		$raw = "From: a@example.org\r\nSubject: Vraag over\r\n ZAAK-2026-0042\r\n\r\nTekst\r\n";

		$message = $this->parser->parse($raw);

		$this->assertSame('Vraag over ZAAK-2026-0042', $message->getSubject());

	}//end testFoldedHeaderIsUnfolded()

	/**
	 * A fixture message with two bodies and one attachment.
	 *
	 * @return string The raw message.
	 */
	private function multipartMessage(): string {
		return "From: Jan Burger <burger@example.org>\r\n"
			. "To: zaken@gemeente.example\r\n"
			. "Subject: Vraag over ZAAK-2026-0042\r\n"
			. "Date: Tue, 15 Sep 2026 10:11:12 +0200\r\n"
			. "Message-ID: <abc-123@example.org>\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
			. "--outer\r\n"
			. "Content-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n"
			. "--inner\r\n"
			. "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
			. "Goedemiddag, mijn vraag gaat over ZAAK-2026-0042.\r\n"
			. "--inner\r\n"
			. "Content-Type: text/html; charset=UTF-8\r\n\r\n"
			. "<p>Goedemiddag</p><script>alert(1)</script><a href=\"#\" onclick=\"alert(2)\">link</a>\r\n"
			. "--inner--\r\n"
			. "--outer\r\n"
			. "Content-Type: text/plain; name=\"bewijs.txt\"\r\n"
			. "Content-Disposition: attachment; filename=\"bewijs.txt\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n"
			. base64_encode('bewijsstuk') . "\r\n"
			. "--outer--\r\n";

	}//end multipartMessage()

}//end class
