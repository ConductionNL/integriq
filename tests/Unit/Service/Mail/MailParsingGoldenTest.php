<?php

/**
 * Golden test pinning how EmlParser walks MIME parts and how both mail parsers read headers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use OCA\Integriq\Service\Mail\CompoundFileReader;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Tests\Helpers\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins the whole parsed shape of a nested multipart message, and the header unfolding and
 * date reading of the `.eml` and `.msg` parsers.
 */
class MailParsingGoldenTest extends TestCase {

	/**
	 * Nested multiparts, inline bodies, attachments by disposition and by name, an empty
	 * content type, a multipart without a boundary, and the header rules.
	 *
	 * @return void
	 */
	public function testEmlPartsAndHeaders(): void {
		$eml = "From: =?UTF-8?B?SsO2cmc=?= <j@example.org>\nTo: a@example.org, \"B\" <b@example.org>\nSubject: Hi\n"
			. "Date: Tue, 15 Sep 2026 10:11:12 +0200\nDate: Wed, 16 Sep 2026 10:11:12 +0200\nMessage-ID: <m-1@example.org>\n"
			. "Content-Type: multipart/mixed;\n boundary=\"OUT\"\n\n"
			. "--OUT\nContent-Type: multipart/alternative; boundary=IN\n\n"
			. "--IN\nContent-Type: text/plain\n\nplain one\n"
			. "--IN\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n" . base64_encode('<p>html</p><script>x</script>') . "\n--IN--\n"
			. "--OUT\nContent-Type: text/plain; name=\"notes.txt\"\n\nnamed text\n"
			. "--OUT\nContent-Type: image/png\nContent-Disposition: ATTACHMENT; filename=\"pic.png\"\nContent-Transfer-Encoding: base64\n\n" . base64_encode("\x89PNG") . "\n"
			. "--OUT\nContent-Type: ;\nContent-Disposition: attachment\n\nraw bytes\n"
			. "--OUT\nContent-Type: multipart/related\n\nno boundary here\n"
			. "--OUT\nContent-Type: text/plain\nContent-Transfer-Encoding: quoted-printable\n\nsecond =3D plain\n--OUT--\n";

		$message = (new EmlParser())->parse($eml);

		$this->assertSame('m-1@example.org', $message->getMessageId());
		$this->assertSame('j@example.org', $message->getFrom());
		$this->assertSame(['a@example.org', 'b@example.org'], $message->getTo());
		$this->assertSame('Hi', $message->getSubject());
		$this->assertSame('2026-09-15T10:11:12+02:00', $message->getReceivedAt());
		$this->assertSame("plain one\nsecond = plain\n", $message->getBodyText());
		$this->assertSame('<p>html</p>', $message->getBodyHtml());
		$this->assertSame(
			[
				['name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 11, 'content' => "named text\n"],
				['name' => 'pic.png', 'mime' => 'image/png', 'size' => 4, 'content' => "\x89PNG"],
				['name' => 'attachment', 'mime' => 'application/octet-stream', 'size' => 10, 'content' => "raw bytes\n"],
			],
			$message->getAttachments()
		);

		$plain = (new EmlParser())->parse("Subject: no body type\n\njust text");
		$this->assertSame('just text', $plain->getBodyText());
		$this->assertSame([], $plain->getAttachments());
		$this->assertNull($plain->getReceivedAt());

	}//end testEmlPartsAndHeaders()

	/**
	 * The `.msg` transport headers: CRLF, folding, first occurrence wins, lines without a colon
	 * and blank lines are skipped, an unreadable or blank date is null.
	 *
	 * @return void
	 */
	public function testMsgTransportHeaders(): void {
		$parse = function (string $headers): array {
			$bytes = (new CompoundFileBuilder())
				->addStream('__substg1.0_0037001F', (string)iconv('UTF-8', 'UTF-16LE', 'S'))
				->addStream('__substg1.0_007D001F', (string)iconv('UTF-8', 'UTF-16LE', $headers))
				->build();
			$message = (new MsgParser())->parse($bytes);

			return [$message->getMessageId(), $message->getReceivedAt()];
		};

		$this->assertSame(
			['x-1@example.org', '2026-09-15T10:11:12+02:00'],
			$parse("Date: Tue, 15 Sep\r\n 2026 10:11:12 +0200\r\nDate: Wed, 16 Sep 2026 10:11:12 +0200\r\nnocolon\r\n\r\nMessage-ID: <x-1@example.org>\r\n")
		);

		[$messageId, $receivedAt] = $parse("Date: not a date\r\n");
		$this->assertStringStartsWith('sha256:', $messageId);
		$this->assertNull($receivedAt);

		[$messageId, $receivedAt] = $parse("  \r\n ");
		$this->assertStringStartsWith('sha256:', $messageId);
		$this->assertNull($receivedAt);

	}//end testMsgTransportHeaders()
	/**
	 * A FAT spread over two sectors reads the same whether the second FAT sector is listed in
	 * the header or reached through a DIFAT sector.
	 *
	 * @return void
	 */
	public function testFatReadFromHeaderAndFromDifatChain(): void {
		$content = '';
		for ($index = 0; $index < 9000; $index++) {
			$content .= sprintf('%08d', $index);
		}

		$bytes = (new CompoundFileBuilder())->addStream('big', $content)->build();
		$this->assertSame($content, $this->readNamed(bytes: $bytes, name: 'big'));

		$fatSectors = unpack('V2', substr($bytes, 76, 8));
		$this->assertSame(2, (int)unpack('V', substr($bytes, 44, 4))[1], 'the fixture needs two FAT sectors');
		$this->assertNotSame(0xFFFFFFFF, $fatSectors[2]);

		$difatId = (intdiv(strlen($bytes), 512) - 1);
		$difat = pack('V', $fatSectors[2]) . str_repeat(pack('V', 0xFFFFFFFF), 126) . pack('V', 0xFFFFFFFE);
		$moved = substr_replace($bytes, pack('V', $difatId), 68, 4);
		$moved = substr_replace($moved, pack('V', 1), 72, 4);
		$moved = substr_replace($moved, pack('V', 0xFFFFFFFF), 80, 4) . $difat;

		$this->assertSame($content, $this->readNamed(bytes: $moved, name: 'big'));

		$broken = substr_replace($moved, pack('V', 0xFFFFFFFE), 68, 4);
		$this->assertNotSame($content, $this->readNamed(bytes: $broken, name: 'big'));

	}//end testFatReadFromHeaderAndFromDifatChain()

	/**
	 * Read one named stream from a compound file.
	 *
	 * @param string $bytes The compound file.
	 * @param string $name  The stream name.
	 *
	 * @return string The stream bytes.
	 */
	private function readNamed(string $bytes, string $name): string {
		$reader = new CompoundFileReader($bytes);
		foreach ($reader->getEntries() as $id => $entry) {
			if ($entry['name'] === $name) {
				return $reader->readStream($id);
			}
		}

		return '';

	}//end readNamed()
}//end class
