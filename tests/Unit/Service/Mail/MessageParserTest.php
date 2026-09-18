<?php

/**
 * Unit tests for MessageParser and CaseReferenceDetector.
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

use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Service\Mail\ParsedMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests the sniffing façade and the reference detector.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MessageParserTest extends TestCase {

	/**
	 * The façade under test.
	 *
	 * @var MessageParser
	 */
	private MessageParser $parser;

	/**
	 * The detector under test.
	 *
	 * @var CaseReferenceDetector
	 */
	private CaseReferenceDetector $detector;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new MessageParser(new EmlParser(), new MsgParser());
		$this->detector = new CaseReferenceDetector();

	}//end setUp()

	/**
	 * The bytes decide which reader runs, not the file name.
	 *
	 * @return void
	 */
	public function testAnEmlNamedMsgIsStillReadAsEml(): void {
		$raw = "From: a@example.org\r\nSubject: Naamsverwarring\r\nMessage-ID: <x@example.org>\r\n\r\nTekst\r\n";

		$message = $this->parser->parse('opgeslagen.msg', $raw);

		$this->assertSame('Naamsverwarring', $message->getSubject());
		$this->assertNull($message->getWarning());

	}//end testAnEmlNamedMsgIsStillReadAsEml()

	/**
	 * A file that does not parse is kept whole with a warning, never dropped.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsKeptAsOneAttachment(): void {
		$raw = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . 'truncated';

		$message = $this->parser->parse('kapot.msg', $raw);

		$this->assertNotNull($message->getWarning());
		$this->assertCount(1, $message->getAttachments());
		$this->assertSame('kapot.msg', $message->getAttachments()[0]['name']);
		$this->assertSame($raw, $message->getAttachments()[0]['content']);

	}//end testAnUnreadableFileIsKeptAsOneAttachment()

	/**
	 * The subject wins over the body when both name a reference.
	 *
	 * @return void
	 */
	public function testSubjectReferenceWinsOverTheBody(): void {
		$message = new ParsedMessage(
			'id-1',
			'a@example.org',
			[],
			'Re: ZAAK-2026-0042',
			null,
			'Eerder ging het over ZAAK-2025-0001.',
			'',
		);

		$this->assertSame('ZAAK-2026-0042', $this->detector->detect($message));

	}//end testSubjectReferenceWinsOverTheBody()

	/**
	 * A reference only in the body is still found.
	 *
	 * @return void
	 */
	public function testBodyReferenceIsFound(): void {
		$message = new ParsedMessage('id-2', 'a@example.org', [], 'Geen kenmerk', null, 'Kenmerk BZW-2026-7.', '');

		$this->assertSame('BZW-2026-7', $this->detector->detect($message));

	}//end testBodyReferenceIsFound()

	/**
	 * A message that names nothing gets no reference.
	 *
	 * @return void
	 */
	public function testMessageWithoutAReferenceGetsNone(): void {
		$message = new ParsedMessage('id-3', 'a@example.org', [], 'Vraag', null, 'Kunt u mij helpen?', '');

		$this->assertNull($this->detector->detect($message));

	}//end testMessageWithoutAReferenceGetsNone()

	/**
	 * A source's own pattern is used when it compiles.
	 *
	 * @return void
	 */
	public function testConfiguredPatternIsUsed(): void {
		$message = new ParsedMessage('id-4', 'a@example.org', [], 'Dossier 12345', null, '', '');

		$this->assertSame('12345', $this->detector->detect($message, '/Dossier (\d{5})/'));

	}//end testConfiguredPatternIsUsed()

	/**
	 * A pattern that does not compile detects nothing rather than falling back
	 * to the default, which would link mail to a case nobody configured.
	 *
	 * @return void
	 */
	public function testBrokenPatternDetectsNothing(): void {
		$message = new ParsedMessage('id-5', 'a@example.org', [], 'ZAAK-2026-0042', null, '', '');

		$this->assertFalse($this->detector->isUsable('/(onafgesloten'));
		$this->assertNull($this->detector->detect($message, '/(onafgesloten'));

	}//end testBrokenPatternDetectsNothing()

}//end class
