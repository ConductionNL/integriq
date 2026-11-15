<?php

/**
 * Characterisation tests for MsgParser::parse()'s fallbacks: subject,
 * sender, message id and the header-derived fields.
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
 * Pins, field by field, which property or header a parsed message takes.
 */
class MsgParserFallbacksTest extends TestCase {

	/**
	 * A compound file of unicode properties.
	 *
	 * @param array<string,string> $properties Property tag (id + 001F) => text.
	 *
	 * @return string The bytes.
	 */
	private function msg(array $properties): string {
		$builder = new CompoundFileBuilder();
		foreach ($properties as $tag => $text) {
			$builder->addStream('__substg1.0_' . $tag, (string)iconv('UTF-8', 'UTF-16LE', $text));
		}

		return $builder->build();

	}//end msg()

	/**
	 * Every field read from its own property wins over its fallback.
	 *
	 * @return void
	 */
	public function testOwnPropertiesWin(): void {
		$message = (new MsgParser())->parse(
			$this->msg(
				[
					'0037001F' => 'Subject',
					'0E1D001F' => 'Normalised',
					'5D01001F' => 'sender@example.org',
					'0C1F001F' => 'name@example.org',
					'1035001F' => ' <prop-id@example.org> ',
					'007D001F' => "Message-ID: <hdr@example.org>\r\nFrom: hdr-from@example.org\r\nTo: hdr-to@example.org\r\nDate: Tue, 15 Sep 2026 10:11:12 +0200\r\n",
					'1000001F' => 'Body',
				]
			)
		);

		$this->assertSame(
			['prop-id@example.org', 'sender@example.org', ['hdr-to@example.org'], 'Subject', 'Body'],
			[$message->getMessageId(), $message->getFrom(), $message->getTo(), $message->getSubject(), $message->getBodyText()]
		);
		$this->assertNotNull($message->getReceivedAt());

	}//end testOwnPropertiesWin()

	/**
	 * Without their own properties the fields fall back in order: the
	 * normalised subject, the sender name, then the headers.
	 *
	 * @return void
	 */
	public function testFieldsFallBackInOrder(): void {
		$parser = new MsgParser();

		$second = $parser->parse($this->msg(['0E1D001F' => 'Normalised', '0C1F001F' => 'name@example.org', '007D001F' => "Message-ID: <hdr@example.org>\r\nFrom: hdr-from@example.org\r\n"]));
		$this->assertSame(['hdr@example.org', 'name@example.org', 'Normalised'], [$second->getMessageId(), $second->getFrom(), $second->getSubject()]);

		$third = $parser->parse($this->msg(['007D001F' => "From: hdr-from@example.org\r\n", '1000001F' => 'x']));
		$this->assertSame('hdr-from@example.org', $third->getFrom());
		$this->assertStringStartsWith('sha256:', $third->getMessageId());
		$this->assertSame('', $third->getSubject());
		$this->assertNull($third->getReceivedAt());

		$bytes = $this->msg(['1000001F' => 'only a body']);
		$this->assertSame('sha256:' . hash('sha256', $bytes), $parser->parse($bytes)->getMessageId());
		$this->assertSame('', $parser->parse($bytes)->getFrom());

	}//end testFieldsFallBackInOrder()

}//end class
