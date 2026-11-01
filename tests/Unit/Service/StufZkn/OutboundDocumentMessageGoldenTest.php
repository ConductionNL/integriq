<?php

/**
 * Golden characterization of OutboundDocumentTranslator::documentMessage().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Renders the voegZaakdocumentToe message for a full delivery, a sparse one,
 * a date-time and a whitespace-only delivery, a template marker and every
 * missing required value, and compares the envelope (with the random
 * referentienummer and the clock masked) or the refusal with a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * OUTBOUND_DOCUMENT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator
 * @uses   \OCA\Integriq\Service\Stuf\StufLiteralLeakGuard
 * @uses   \OCA\Integriq\Service\Stuf\StufXmlParser
 */
class OutboundDocumentMessageGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/stuf-zds/document-message-golden.json';

	/**
	 * The cases: each one the arguments of one documentMessage() call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$file   = ['content' => "PDF\x00bytes", 'filename' => 'besluit.pdf', 'mimeType' => 'application/pdf'];
		$full   = [
			'titel' => '  Besluit op aanvraag  ',
			'beschrijving' => 'Kopie',
			'creatiedatum' => '2026-10-05',
			'ontvangstdatum' => '2026-10-06T08:15:00+02:00',
			'formaat' => 'application/x-pdf',
			'taal' => 'dut',
			'versie' => '2',
			'status' => 'definitief',
			'verzenddatum' => '2026-10-07',
			'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
			'auteur' => 'Team Vergunningen',
			'link' => 'https://example.org/doc/1',
		];
		$base   = [
			'document' => $full,
			'documentId' => '0363-DOC-1',
			'zaakIdentificatie' => '0363-ZAAK-9',
			'documenttype' => 'Besluit',
			'file' => $file,
			'zender' => ['organisatie' => '0363', 'applicatie' => 'integriq'],
			'ontvanger' => ['organisatie' => '0363', 'applicatie' => 'ZSH'],
		];
		$sparse = array_merge($base, ['document' => ['titel' => 'Brief'], 'ontvanger' => ['organisatie' => ' 0363 ']]);
		$blank  = array_merge(
			$base,
			[
				'document' => ['titel' => 'Brief', 'beschrijving' => '   ', 'creatiedatum' => '  ', 'taal' => ' ', 'formaat' => ''],
				'zender' => ['organisatie' => ' 0363 ', 'applicatie' => '  '],
			]
		);

		return [
			'full' => $base,
			'sparse' => $sparse,
			'blank-optional' => $blank,
			'marker' => array_merge($base, ['document' => ['titel' => 'Brief {{ naam }}']]),
			'no-documentId' => array_merge($base, ['documentId' => '']),
			'blank-zaak' => array_merge($base, ['zaakIdentificatie' => '  ']),
			'no-documenttype' => array_merge($base, ['documenttype' => '']),
			'no-titel' => array_merge($base, ['document' => ['beschrijving' => 'x']]),
			'blank-titel' => array_merge($base, ['document' => ['titel' => '   ']]),
		];
	}//end cases()

	/**
	 * Run one case: the masked envelope, or the refusal message.
	 *
	 * @param array<string, mixed> $case The arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		try {
			$message = $this->render(case: $case);
		} catch (StufZknTranslationException $exception) {
			return ['refused' => $exception->getMessage()];
		}

		$reference = $message['referentienummer'];
		$xml       = str_replace($reference, '{REFERENCE}', $message['xml']);
		$xml       = (string)preg_replace('#<StUF:tijdstipBericht>\d{14}</StUF:tijdstipBericht>#', '<StUF:tijdstipBericht>{CLOCK}</StUF:tijdstipBericht>', $xml);

		return [
			'referenceShape' => (preg_match('/^ZDS-[0-9a-f]{16}$/', $reference) === 1),
			'referenceInXml' => substr_count($message['xml'], $reference),
			'keys' => array_keys($message),
			'xml' => $xml,
		];
	}//end runCase()

	/**
	 * Call documentMessage() with one case's arguments.
	 *
	 * @param array<string, mixed> $case The arguments.
	 *
	 * @return array{referentienummer:string,xml:string}
	 */
	private function render(array $case): array {
		return (new OutboundDocumentTranslator())->documentMessage(
			document: $case['document'],
			documentId: $case['documentId'],
			zaakIdentificatie: $case['zaakIdentificatie'],
			documenttype: $case['documenttype'],
			file: $case['file'],
			zender: $case['zender'],
			ontvanger: $case['ontvanger']
		);
	}//end render()

	/**
	 * Every case renders as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('OUTBOUND_DOCUMENT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
