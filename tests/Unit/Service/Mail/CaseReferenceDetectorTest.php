<?php

/**
 * Unit tests for CaseReferenceDetector's text and haystack handling.
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
 * @spec openspec/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCA\Integriq\Service\Mail\ParsedMessage;
use PHPUnit\Framework\TestCase;

/**
 * The detector as every channel reads it: plain text, empty haystacks, and
 * the pattern rules.
 *
 * @covers \OCA\Integriq\Service\Mail\CaseReferenceDetector
 * @uses   \OCA\Integriq\Service\Mail\ParsedMessage
 */
class CaseReferenceDetectorTest extends TestCase {

	/**
	 * The detector under test.
	 *
	 * @var CaseReferenceDetector
	 */
	private CaseReferenceDetector $detector;

	/**
	 * Set up the detector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->detector = new CaseReferenceDetector();

	}//end setUp()

	/**
	 * A message with the given subject and body.
	 *
	 * @param string $subject The subject.
	 * @param string $body The plain-text body.
	 *
	 * @return ParsedMessage The message.
	 */
	private function message(string $subject, string $body): ParsedMessage {
		return new ParsedMessage('<m@example.org>', 'a@example.org', [], $subject, null, $body, '');

	}//end message()

	/**
	 * Plain text yields the default-shaped reference, trimmed.
	 *
	 * @return void
	 */
	public function testDetectInTextFindsTheDefaultShape(): void {
		$this->assertSame('ZAAK-2026-0042', $this->detector->detectInText('Graag reactie op ZAAK-2026-0042, dank.'));
		$this->assertSame('ZAAK-2026-0042', $this->detector->detectInText('Zie ZAAK-2026-0042', '   '));

	}//end testDetectInTextFindsTheDefaultShape()

	/**
	 * Empty text or text without a reference yields null.
	 *
	 * @return void
	 */
	public function testDetectInTextWithoutAReferenceIsNull(): void {
		$this->assertNull($this->detector->detectInText(''));
		$this->assertNull($this->detector->detectInText('zaak-2026-0042 in kleine letters'));

	}//end testDetectInTextWithoutAReferenceIsNull()

	/**
	 * A configured pattern is trimmed and its first group wins; without a
	 * group the whole match is the reference.
	 *
	 * @return void
	 */
	public function testDetectInTextHonoursAConfiguredPattern(): void {
		$this->assertSame('12345', $this->detector->detectInText('Dossier 12345 bijgewerkt', '  /Dossier (\d{5})/  '));
		$this->assertSame('D-77', $this->detector->detectInText('nummer D-77 ontvangen', '/D-\d+/'));

	}//end testDetectInTextHonoursAConfiguredPattern()

	/**
	 * A pattern that does not compile answers null instead of falling back
	 * to the default, even when the text holds a default-shaped reference.
	 *
	 * @return void
	 */
	public function testDetectInTextWithABrokenPatternIsNull(): void {
		$this->assertNull($this->detector->detectInText('ZAAK-2026-0042', '/(onafgesloten'));
		$this->assertNull($this->detector->detect($this->message('ZAAK-2026-0042', ''), 'geen-regex'));

	}//end testDetectInTextWithABrokenPatternIsNull()

	/**
	 * A match whose captured group is blank does not count; the next haystack
	 * is read instead.
	 *
	 * @return void
	 */
	public function testABlankCaptureFallsThroughToTheBody(): void {
		$message = $this->message('Ref:   einde', 'Ref:X-9 einde');

		$this->assertSame('X-9', $this->detector->detect($message, '/Ref:(\s*|X-\d)\s*einde/'));

	}//end testABlankCaptureFallsThroughToTheBody()

	/**
	 * An empty subject is skipped and the body is read.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectIsSkippedForTheBody(): void {
		$message = $this->message('', 'Over BZW-2026-7');

		$this->assertSame('BZW-2026-7', $this->detector->detect($message));

	}//end testAnEmptySubjectIsSkippedForTheBody()

	/**
	 * The subject wins over the body.
	 *
	 * @return void
	 */
	public function testTheSubjectWinsOverTheBody(): void {
		$message = $this->message('Re: ZAAK-2026-0001', 'Eerder: ZAAK-2025-0999');

		$this->assertSame('ZAAK-2026-0001', $this->detector->detect($message));

	}//end testTheSubjectWinsOverTheBody()

	/**
	 * Usability mirrors whether the pattern compiles; blank means the default.
	 *
	 * @return void
	 */
	public function testIsUsableMirrorsCompilation(): void {
		$this->assertTrue($this->detector->isUsable(''));
		$this->assertTrue($this->detector->isUsable('/ok/'));
		$this->assertFalse($this->detector->isUsable('not-a-regex'));

	}//end testIsUsableMirrorsCompilation()
}//end class
