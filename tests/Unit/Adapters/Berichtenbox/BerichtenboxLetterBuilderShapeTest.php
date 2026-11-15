<?php

/**
 * Integriq — the exact shape of a Berichtenbox letter, and the order in which its rules refuse.
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
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxLetterBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-DPA-010: the whole document, element by element, with fixed ids and time.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
class BerichtenboxLetterBuilderShapeTest extends TestCase {

	private const OIN = '00000001234567890000';

	private const BATCH_ID = '11111111-2222-3333-4444-555555555555';

	private const BERICHT_ID = '66666666-7777-8888-9999-000000000000';

	/**
	 * Build a letter with fixed ids and a fixed creation time.
	 *
	 * @param array<string,mixed> $override Fields of the message to change.
	 * @param string              $type     The BerichtType.
	 *
	 * @return string The batch XML.
	 */
	private function build(array $override = [], string $type = 'BESLUIT'): string {
		$message = array_merge(
			[
				'recipient' => ' 999993653 ',
				'subject' => 'Besluit',
				'body' => "Regel een\r\nZie https://example.nl/zaak.  En\rklaar\n",
				'caseRef' => 'Z-1',
				'attachments' => [],
			],
			$override
		);

		return (new BerichtenboxLetterBuilder())->build(
			$message,
			self::OIN,
			$type,
			new DateTimeImmutable('2026-03-04T05:06:07+02:00'),
			self::BATCH_ID,
			self::BERICHT_ID
		)->xml;
	}//end build()

	/**
	 * Every element in its place: batch information, letter information, the
	 * reference, and both attachments numbered after non-array entries are dropped.
	 *
	 * @return void
	 */
	public function testTheWholeDocumentIsBuiltInSchemaOrder(): void {
		$xml = $this->build(
			[
				'attachments' => [
					'skip',
					['content' => base64_encode('%PDF-1.7 a'), 'encoding' => 'base64', 'description' => ' Besluit '],
					['content' => '%PDF-1.4 b', 'filename' => 'brief.pdf'],
				],
			]
		);

		$expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<r:Berichten xmlns:r="http://schemas.rdw.nl/GEB/BerichtVerwerkService/Types/2009/01" xmlns:b="http://schemas.rdw.nl/GEB/BerichtenProsessor/Bericht/Types/2009/01">'
			. '<r:BatchInformatie><r:BatchID>' . self::BATCH_ID . '</r:BatchID><r:AanmaakDatum>2026-03-04T03:06:07Z</r:AanmaakDatum>'
			. '<r:BerichtLeverancierID>' . self::OIN . '</r:BerichtLeverancierID></r:BatchInformatie>'
			. '<b:Bericht><b:BerichtInformatie><b:BatchID>' . self::BATCH_ID . '</b:BatchID><b:BerichtID>' . self::BERICHT_ID . '</b:BerichtID>'
			. '<b:BerichtType>BESLUIT</b:BerichtType><b:Onderwerp>Besluit</b:Onderwerp>'
			. '<b:BerichtTekst>Regel een\r\nZie https://example.nl/zaak . En\r\nklaar</b:BerichtTekst>'
			. '<b:Referentie>Z-1</b:Referentie><b:GebruikerID>999993653</b:GebruikerID><b:SoortGebruiker>Burger</b:SoortGebruiker></b:BerichtInformatie>'
			. '<b:Bijlagen><b:Bijlage><b:Inhoud>JVBERi0xLjcgYQ==</b:Inhoud><b:BijlageType>Pdf</b:BijlageType><b:Omschrijving>Besluit</b:Omschrijving><b:Volgorde>1</b:Volgorde></b:Bijlage>'
			. '<b:Bijlage><b:Inhoud>JVBERi0xLjQgYg==</b:Inhoud><b:BijlageType>Pdf</b:BijlageType><b:Omschrijving>brief.pdf</b:Omschrijving><b:Volgorde>2</b:Volgorde></b:Bijlage></b:Bijlagen>'
			. '</b:Bericht></r:Berichten>' . "\n";

		$this->assertSame($expected, $xml);
	}//end testTheWholeDocumentIsBuiltInSchemaOrder()

	/**
	 * Without attachments there is no Bijlagen element, and without a case reference no Referentie.
	 *
	 * @return void
	 */
	public function testNoAttachmentsAndNoReferenceLeaveTheirElementsOut(): void {
		$xml = $this->build(['caseRef' => '', 'attachments' => ['not-an-attachment']]);

		$this->assertStringNotContainsString('Bijlagen', $xml);
		$this->assertStringNotContainsString('Referentie', $xml);
		$this->assertStringContainsString('<b:BerichtTekst>Regel een\r\nZie https://example.nl/zaak . En\r\nklaar</b:BerichtTekst><b:GebruikerID>', $xml);
	}//end testNoAttachmentsAndNoReferenceLeaveTheirElementsOut()

	/**
	 * An attachment without a description is described by its position.
	 *
	 * @return void
	 */
	public function testAnAttachmentWithoutADescriptionIsNamedByItsPosition(): void {
		$xml = $this->build(['attachments' => [['content' => '%PDF-1.4 a', 'name' => 'eerste'], ['content' => '%PDF-1.4 b']]]);

		$this->assertStringContainsString('<b:Omschrijving>eerste</b:Omschrijving><b:Volgorde>1</b:Volgorde>', $xml);
		$this->assertStringContainsString('<b:Omschrijving>Bijlage 2</b:Omschrijving><b:Volgorde>2</b:Volgorde>', $xml);
	}//end testAnAttachmentWithoutADescriptionIsNamedByItsPosition()

	/**
	 * Each refusal, with its exact message and the attachment position it names.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string, 2: string}>
	 */
	public static function refusals(): array {
		$pdf = ['content' => '%PDF-1.4 ok'];
		return [
			'empty subject' => [['subject' => ''], 'BESLUIT', 'Onderwerp is empty; the Berichtenbox needs one.'],
			'empty type' => [[], '', 'BerichtType is empty; the Berichtenbox needs one.'],
			'bsn before subject' => [['recipient' => '1234567890', 'subject' => ''], 'BESLUIT', 'GebruikerID must be a BSN of at most 9 digits.'],
			'subject before text' => [['subject' => str_repeat('a', 51), 'body' => str_repeat('b', 4001)], 'BESLUIT', 'Onderwerp has 51 characters; the Berichtenbox takes at most 50. Nothing was cut to fit.'],
			'text before type' => [['body' => str_repeat('b', 4001)], 'ABCDEFGHI', 'BerichtTekst has 4001 characters; the Berichtenbox takes at most 4000. Nothing was cut to fit.'],
			'second attachment base64' => [['attachments' => [$pdf, ['content' => '***', 'encoding' => 'base64']]], 'BESLUIT', 'Attachment 2 is not valid base64.'],
			'second attachment not pdf' => [['attachments' => [$pdf, ['content' => 'plain']]], 'BESLUIT', 'Attachment 2 is not a PDF. The Berichtenbox takes PDF attachments only.'],
			'pdf check before description' => [['attachments' => [['content' => 'plain', 'description' => str_repeat('d', 41)]]], 'BESLUIT', 'Attachment 1 is not a PDF. The Berichtenbox takes PDF attachments only.'],
			'description too long' => [['attachments' => [['content' => '%PDF-1.4', 'description' => str_repeat('d', 41)]]], 'BESLUIT', 'Omschrijving has 41 characters; the Berichtenbox takes at most 40. Nothing was cut to fit.'],
			'count before content' => [['attachments' => [['content' => 'x'], $pdf, $pdf]], 'BESLUIT', 'A letter carries at most 2 attachments; this one has 3.'],
			'content before total size' => [['attachments' => [['content' => '%PDF-' . str_repeat('x', 512000)], ['content' => 'plain']]], 'BESLUIT', 'Attachment 2 is not a PDF. The Berichtenbox takes PDF attachments only.'],
			'total size' => [['attachments' => [['content' => '%PDF-' . str_repeat('x', 300000)], ['content' => '%PDF-' . str_repeat('x', 300000)]]], 'BESLUIT', 'The attachments are 586 kB together; the Berichtenbox takes at most 500 kB before base64.'],
		];
	}//end refusals()

	/**
	 * A letter that breaks a rule is refused with the message of the first rule it breaks.
	 *
	 * @param array<string,mixed> $override The message fields that break the rule.
	 * @param string              $type     The BerichtType.
	 * @param string              $message  The refusal message.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testTheFirstBrokenRuleRefuses(array $override, string $type, string $message): void {
		try {
			$this->build($override, $type);
			$this->fail('The letter was not refused.');
		} catch (BerichtenboxException $exception) {
			$this->assertSame($message, $exception->getMessage());
			$this->assertSame(BerichtenboxException::CODE_INVALID_LETTER, $exception->getReason());
		}
	}//end testTheFirstBrokenRuleRefuses()
}//end class
