<?php

/**
 * Characterisation tests for MsgParser's property, attachment and
 * recipient reading.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Tests\Helpers\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins which streams count as properties, how unicode properties decode,
 * the attachment name and mime fallbacks, and how recipients are split.
 */
class MsgParserPropertiesTest extends TestCase {

	/**
	 * Encode a unicode MAPI property value.
	 *
	 * @param string $value The value.
	 *
	 * @return string The UTF-16LE bytes.
	 */
	private function utf16(string $value): string {
		return (string)iconv('UTF-8', 'UTF-16LE', $value);

	}//end utf16()

	/**
	 * Only property streams with a full tag count; binary properties stay raw.
	 *
	 * @return void
	 */
	public function testOnlyFullyTaggedPropertyStreamsAreRead(): void {
		$raw = (new CompoundFileBuilder())
			->addStream('__substg1.0_0037', $this->utf16('short tag'))
			->addStream('other_0037001F', $this->utf16('wrong prefix'))
			->addStorage('__substg1.0_0037001F', ['x' => 'a storage, not a stream'])
			->addStream('__substg1.0_0e1d001f', $this->utf16('Normalised subject'))
			->addStream('__substg1.0_10000102', 'raw body bytes')
			->addStream('__substg1.0_0C1F001F', $this->utf16('sender@example.org'))
			->build();

		$message = (new MsgParser())->parse($raw);

		$this->assertSame('Normalised subject', $message->getSubject());
		$this->assertSame('raw body bytes', $message->getBodyText());
		$this->assertSame('sender@example.org', $message->getFrom());
		$this->assertSame([], $message->getTo());
		$this->assertSame([], $message->getAttachments());

	}//end testOnlyFullyTaggedPropertyStreamsAreRead()

	/**
	 * Attachments take the long file name, else the short one, else
	 * "attachment", and an unknown mime type; other storages are skipped.
	 *
	 * @return void
	 */
	public function testAttachmentNamesAndMimeTypesFallBack(): void {
		$raw = (new CompoundFileBuilder())
			->addStream('__substg1.0_0037001F', $this->utf16('S'))
			->addStorage('__attach_version1.0_#00000000', ['__substg1.0_3704001F' => $this->utf16('SHORT.TXT'), '__substg1.0_37010102' => 'abc'])
			->addStorage('__attach_version1.0_#00000001', ['__substg1.0_37010102' => ''])
			->addStorage('__attach_version1.0_#00000002', ['__substg1.0_3707001F' => $this->utf16('0'), '__substg1.0_370E001F' => $this->utf16('text/plain')])
			->addStorage('__recip_version1.0_#00000000', ['__substg1.0_3001001F' => $this->utf16('Recipient')])
			->build();

		$attachments = (new MsgParser())->parse($raw)->getAttachments();
		usort($attachments, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		$this->assertSame(
			[
				['name' => '0', 'mime' => 'text/plain', 'size' => 0, 'content' => ''],
				['name' => 'SHORT.TXT', 'mime' => 'application/octet-stream', 'size' => 3, 'content' => 'abc'],
				['name' => 'attachment', 'mime' => 'application/octet-stream', 'size' => 0, 'content' => ''],
			],
			$attachments
		);

	}//end testAttachmentNamesAndMimeTypesFallBack()

	/**
	 * The display-to property wins over the stored To header; entries are
	 * split on ; and , with the address inside angle brackets taken.
	 *
	 * @return void
	 */
	public function testRecipientsAreSplitFromDisplayToOrTheToHeader(): void {
		$headers = "To: header@example.org\r\nFrom: header-from@example.org\r\n";
		$build = fn (array $streams): string => array_reduce(
			array_keys($streams),
			static fn (CompoundFileBuilder $builder, string $name): CompoundFileBuilder => $builder->addStream($name, $streams[$name]),
			new CompoundFileBuilder()
		)->build();

		$withDisplayTo = $build(
			[
				'__substg1.0_007D001F' => $this->utf16($headers),
				'__substg1.0_0E04001F' => $this->utf16('Jan <jan@example.org>; ; piet@example.org,< >,Kees < kees@example.org >'),
			]
		);
		$message = (new MsgParser())->parse($withDisplayTo);
		$this->assertSame(['jan@example.org', 'piet@example.org', '', 'kees@example.org'], $message->getTo());
		$this->assertSame('header-from@example.org', $message->getFrom());

		$headersOnly = $build(['__substg1.0_007D001F' => $this->utf16($headers)]);
		$this->assertSame(['header@example.org'], (new MsgParser())->parse($headersOnly)->getTo());

	}//end testRecipientsAreSplitFromDisplayToOrTheToHeader()

}//end class
