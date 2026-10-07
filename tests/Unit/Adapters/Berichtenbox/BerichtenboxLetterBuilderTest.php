<?php

/**
 * Integriq — Berichtenbox letters are built to the official schema and refused when they break it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use DateTimeImmutable;
use DOMDocument;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxLetterBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-DPA-010.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
class BerichtenboxLetterBuilderTest extends TestCase {

	private const OIN = '00000001234567890000';

	/**
	 * A letter as integriq composes it.
	 *
	 * @param array<string,mixed> $override Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function message(array $override = []): array {
		return array_merge(
			[
				'recipient' => '999993653',
				'subject' => 'Besluit op uw aanvraag',
				'body' => "Beste heer De Vries,\n\nZie https://example.nl/zaak.\nMet vriendelijke groet",
				'attachments' => [],
				'caseRef' => 'Z-2026-002',
			],
			$override
		);
	}//end message()

	/**
	 * A PDF of the given size, base64 as dossiq sends it.
	 *
	 * @param int $bytes The size.
	 *
	 * @return array<string,string>
	 */
	private function pdf(int $bytes): array {
		return ['content' => base64_encode('%PDF-1.7' . str_repeat('x', $bytes - 8)), 'encoding' => 'base64'];
	}//end pdf()

	/**
	 * A valid letter validates against the vendored XSD, with every rule applied.
	 *
	 * @return void
	 */
	public function testALetterValidatesAgainstTheLogiusSchema(): void {
		$batch = (new BerichtenboxLetterBuilder())->build(
			message: $this->message(['attachments' => [$this->pdf(1000)]]),
			senderOin: self::OIN,
			berichtType: 'BESLUIT',
			now: new DateTimeImmutable('2026-10-07T22:00:00+02:00')
		);

		$document = new DOMDocument();
		$document->loadXML($batch->xml);
		$this->assertTrue($document->schemaValidate(BerichtenboxLetterBuilder::schemaPath()));
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $batch->berichtId);
		$this->assertStringContainsString('<r:AanmaakDatum>2026-10-07T20:00:00Z</r:AanmaakDatum>', $batch->xml);
		$this->assertStringContainsString('<b:SoortGebruiker>Burger</b:SoortGebruiker>', $batch->xml);
		$this->assertStringContainsString('<b:Referentie>Z-2026-002</b:Referentie>', $batch->xml);
		$this->assertStringContainsString('Beste heer De Vries,\r\n\r\nZie https://example.nl/zaak .\r\nMet', $batch->xml);
		$this->assertStringContainsString('<b:Omschrijving>Bijlage 1</b:Omschrijving>', $batch->xml);
		$this->assertStringContainsString('<b:Volgorde>1</b:Volgorde>', $batch->xml);
	}//end testALetterValidatesAgainstTheLogiusSchema()

	/**
	 * Fifty-one characters of subject are refused, not cut.
	 *
	 * @return void
	 */
	public function testASubjectThatIsTooLongIsRefusedNotCut(): void {
		$this->expectException(BerichtenboxException::class);
		$this->expectExceptionMessage('Onderwerp has 51 characters; the Berichtenbox takes at most 50');

		(new BerichtenboxLetterBuilder())->build($this->message(['subject' => str_repeat('a', 51)]), self::OIN, 'BESLUIT');
	}//end testASubjectThatIsTooLongIsRefusedNotCut()

	/**
	 * Two PDFs of 300 kB are over the 500 kB limit.
	 *
	 * @return void
	 */
	public function testAttachmentsOver500KbAreRefused(): void {
		$this->expectException(BerichtenboxException::class);
		$this->expectExceptionMessage('at most 500 kB before base64');

		(new BerichtenboxLetterBuilder())->build(
			$this->message(['attachments' => [$this->pdf(300 * 1024), $this->pdf(300 * 1024)]]),
			self::OIN,
			'BESLUIT'
		);
	}//end testAttachmentsOver500KbAreRefused()

	/**
	 * A third attachment is refused.
	 *
	 * @return void
	 */
	public function testAThirdAttachmentIsRefused(): void {
		$this->expectExceptionMessage('at most 2 attachments; this one has 3');

		(new BerichtenboxLetterBuilder())->build(
			$this->message(['attachments' => [$this->pdf(100), $this->pdf(100), $this->pdf(100)]]),
			self::OIN,
			'BESLUIT'
		);
	}//end testAThirdAttachmentIsRefused()

	/**
	 * Anything but a PDF is refused.
	 *
	 * @return void
	 */
	public function testANonPdfAttachmentIsRefused(): void {
		$this->expectExceptionMessage('Attachment 1 is not a PDF');

		(new BerichtenboxLetterBuilder())->build(
			$this->message(['attachments' => [['content' => base64_encode('<html>'), 'encoding' => 'base64']]]),
			self::OIN,
			'BESLUIT'
		);
	}//end testANonPdfAttachmentIsRefused()

	/**
	 * Text over 4000 characters, once line breaks are written out, is refused.
	 *
	 * @return void
	 */
	public function testTextOver4000CharactersIsRefused(): void {
		$this->expectExceptionMessage('BerichtTekst has');

		(new BerichtenboxLetterBuilder())->build($this->message(['body' => str_repeat("a\n", 1500)]), self::OIN, 'BESLUIT');
	}//end testTextOver4000CharactersIsRefused()

	/**
	 * A reference that does not fit is left out, the letter still goes.
	 *
	 * @return void
	 */
	public function testAReferenceThatDoesNotFitIsLeftOut(): void {
		$batch = (new BerichtenboxLetterBuilder())->build($this->message(['caseRef' => str_repeat('Z', 26)]), self::OIN, 'BESLUIT');

		$this->assertStringNotContainsString('Referentie', $batch->xml);
	}//end testAReferenceThatDoesNotFitIsLeftOut()

	/**
	 * A recipient that is not a BSN is refused before anything is built.
	 *
	 * @return void
	 */
	public function testARecipientThatIsNotABsnIsRefused(): void {
		$this->expectExceptionMessage('GebruikerID must be a BSN');

		(new BerichtenboxLetterBuilder())->build($this->message(['recipient' => 'jan@example.nl']), self::OIN, 'BESLUIT');
	}//end testARecipientThatIsNotABsnIsRefused()

	/**
	 * A BerichtType over the schema's 8 characters is refused.
	 *
	 * @return void
	 */
	public function testABerichtTypeOverEightCharactersIsRefused(): void {
		$this->expectExceptionMessage('BerichtType has 9 characters');

		(new BerichtenboxLetterBuilder())->build($this->message(), self::OIN, 'ABCDEFGHI');
	}//end testABerichtTypeOverEightCharactersIsRefused()

	/**
	 * A document the schema rejects is caught by the same check the builder runs.
	 *
	 * @return void
	 */
	public function testTheSchemaCheckRejectsAMalformedBatch(): void {
		$batch = (new BerichtenboxLetterBuilder())->build($this->message(), self::OIN, 'BESLUIT');

		$this->expectExceptionMessage('does not validate against the Logius schema');
		(new BerichtenboxLetterBuilder())->assertValid(str_replace('>Burger<', '>Bedrijf<', $batch->xml));
	}//end testTheSchemaCheckRejectsAMalformedBatch()
}//end class
