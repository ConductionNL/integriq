<?php

/**
 * Unit tests for MsgParser and the compound file reader underneath it.
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

use OCA\Integriq\Exception\MessageParseException;
use OCA\Integriq\Service\Mail\CompoundFileReader;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Tests\Helpers\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Outlook `.msg` reader against a real compound file.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MsgParserTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var MsgParser
	 */
	private MsgParser $parser;

	/**
	 * Set up the parser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new MsgParser();

	}//end setUp()

	/**
	 * A saved Outlook message with two attachments reads back whole.
	 *
	 * @return void
	 */
	public function testSavedOutlookMessageWithTwoAttachments(): void {
		$message = $this->parser->parse($this->fixture());

		$this->assertSame('Vraag over ZAAK-2026-0042', $message->getSubject());
		$this->assertSame('burger@example.org', $message->getFrom());
		$this->assertSame(['zaken@gemeente.example'], $message->getTo());
		$this->assertSame('msg-1@example.org', $message->getMessageId());
		$this->assertStringContainsString('Goedemiddag', $message->getBodyText());

		$attachments = $message->getAttachments();
		$this->assertCount(2, $attachments);

		$byName = [];
		foreach ($attachments as $attachment) {
			$byName[$attachment['name']] = $attachment;
		}

		$this->assertArrayHasKey('bewijs.txt', $byName);
		$this->assertSame('bewijsstuk', $byName['bewijs.txt']['content']);
		$this->assertSame('text/plain', $byName['bewijs.txt']['mime']);

		$this->assertArrayHasKey('scan.bin', $byName);
		$this->assertSame(9000, $byName['scan.bin']['size']);
		$this->assertSame(str_repeat('S', 9000), $byName['scan.bin']['content']);

	}//end testSavedOutlookMessageWithTwoAttachments()

	/**
	 * The HTML body of a `.msg` is sanitized like any other body.
	 *
	 * @return void
	 */
	public function testHtmlBodyIsSanitized(): void {
		$message = $this->parser->parse($this->fixture());

		$this->assertStringContainsString('<p>Goedemiddag</p>', $message->getBodyHtml());
		$this->assertStringNotContainsString('<script', $message->getBodyHtml());

	}//end testHtmlBodyIsSanitized()

	/**
	 * The date comes out of the stored internet headers.
	 *
	 * @return void
	 */
	public function testReceivedAtComesFromTheStoredHeaders(): void {
		$message = $this->parser->parse($this->fixture());

		$this->assertNotNull($message->getReceivedAt());
		$this->assertStringStartsWith('2026-09-15T10:11:12', (string)$message->getReceivedAt());

	}//end testReceivedAtComesFromTheStoredHeaders()

	/**
	 * A small stream and a large one come back byte for byte, which is the
	 * mini stream path and the FAT path in one file.
	 *
	 * @return void
	 */
	public function testReaderHandlesBothTheMiniStreamAndTheFat(): void {
		$reader = new CompoundFileReader($this->fixture());

		$names = [];
		foreach ($reader->getEntries() as $id => $entry) {
			$names[(string)$entry['name']] = $id;
		}

		$this->assertArrayHasKey('__substg1.0_0037001F', $names);
		$this->assertArrayHasKey('__attach_version1.0_#00000001', $names);

		$subject = $reader->readStream($names['__substg1.0_0037001F']);
		$this->assertSame(
			'Vraag over ZAAK-2026-0042',
			(string)iconv('UTF-16LE', 'UTF-8', $subject)
		);

	}//end testReaderHandlesBothTheMiniStreamAndTheFat()

	/**
	 * Bytes that are not a compound file are refused, not guessed at.
	 *
	 * @return void
	 */
	public function testNonCompoundFileIsRefused(): void {
		$this->expectException(MessageParseException::class);

		$this->parser->parse('From: a@example.org' . "\r\n\r\n" . 'not a msg');

	}//end testNonCompoundFileIsRefused()

	/**
	 * Build the fixture `.msg`.
	 *
	 * @return string The compound file bytes.
	 */
	private function fixture(): string {
		$headers = "Date: Tue, 15 Sep 2026 10:11:12 +0200\r\n"
			. "Message-ID: <msg-1@example.org>\r\n"
			. "From: burger@example.org\r\n";

		return (new CompoundFileBuilder())
			->addStream('__substg1.0_0037001F', $this->utf16('Vraag over ZAAK-2026-0042'))
			->addStream('__substg1.0_1000001F', $this->utf16('Goedemiddag, mijn vraag gaat over ZAAK-2026-0042.'))
			->addStream('__substg1.0_1013001F', $this->utf16('<p>Goedemiddag</p><script>alert(1)</script>'))
			->addStream('__substg1.0_5D01001F', $this->utf16('burger@example.org'))
			->addStream('__substg1.0_0E04001F', $this->utf16('zaken@gemeente.example'))
			->addStream('__substg1.0_007D001F', $this->utf16($headers))
			->addStorage(
				'__attach_version1.0_#00000000',
				[
					'__substg1.0_3707001F' => $this->utf16('bewijs.txt'),
					'__substg1.0_370E001F' => $this->utf16('text/plain'),
					'__substg1.0_37010102' => 'bewijsstuk',
				]
			)
			->addStorage(
				'__attach_version1.0_#00000001',
				[
					'__substg1.0_3707001F' => $this->utf16('scan.bin'),
					'__substg1.0_37010102' => str_repeat('S', 9000),
				]
			)
			->build();

	}//end fixture()

	/**
	 * Encode a property value the way a unicode MAPI property holds it.
	 *
	 * @param string $value The value.
	 *
	 * @return string The UTF-16LE bytes.
	 */
	private function utf16(string $value): string {
		return (string)iconv('UTF-8', 'UTF-16LE', $value);

	}//end utf16()

}//end class
