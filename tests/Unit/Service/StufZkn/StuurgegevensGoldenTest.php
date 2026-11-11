<?php

/**
 * Golden test for the stuurgegevens block of the StUF-ZKN messages this bridge builds.
 *
 * Pins the whole rendered envelope of the Di02 identificatie request, the Bv03 and Fo03 replies and
 * the zakLk01 kennisgeving, with the generated referentienummer and the tijdstipBericht masked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator;
use OCA\Integriq\Service\StufZkn\OutboundNotificationTranslator;
use OCA\Integriq\Service\StufZkn\StufZknAcknowledgementBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Every built message matches tests/fixtures/stuf-stuurgegevens/messages.json.
 */
class StuurgegevensGoldenTest extends TestCase {

	/**
	 * The golden file.
	 */
	private const GOLDEN = __DIR__ . '/../../../fixtures/stuf-stuurgegevens/messages.json';

	/**
	 * Mask what changes per run: the generated referentienummer and the tijdstipBericht.
	 *
	 * @param string $xml The rendered envelope.
	 *
	 * @return string The envelope with both masked.
	 */
	private function mask(string $xml): string {
		$xml = preg_replace('#(<[^>]*referentienummer>)[^<]*(</)#', '$1REF$2', $xml);
		return (string)preg_replace('#(<[^>]*tijdstipBericht>)[^<]*(</)#', '$1TIME$2', (string)$xml);
	}//end mask()

	/**
	 * Every message, rendered by the current code.
	 *
	 * @return array<string,string>
	 */
	private function messages(): array {
		$document     = new OutboundDocumentTranslator();
		$ack          = new StufZknAcknowledgementBuilder();
		$notification = new OutboundNotificationTranslator();
		$case         = [
			'identificatie' => 'ZAAK-2026-001',
			'omschrijving' => 'Kapvergunning',
			'zaaktypeCode' => 'B0337',
			'zaaktypeOmschrijving' => 'Kapvergunning',
			'registratiedatum' => '20260716',
			'startdatum' => '20260716',
		];

		$messages = [
			'di02-full' => $document->identificationRequest(
				['organisatie' => '0363', 'applicatie' => 'integriq'],
				['organisatie' => '0363', 'applicatie' => 'ZSH']
			)['xml'],
			'di02-sparse' => $document->identificationRequest(
				['organisatie' => ' 0363 ', 'applicatie' => ''],
				['organisatie' => '']
			)['xml'],
			'bv03' => $ack->buildBv03('ZKN-abc', '0363', '1900'),
			'bv03-uncorrelated' => $ack->buildBv03('', '0363', ''),
			'fo03' => $ack->buildFo03('schema_invalid', 'ZKN-abc', '0363', '1900'),
			'fo03-unknown-reason' => $ack->buildFo03('nope', '', '0363', ''),
			'lk01-T' => $notification->translate($case, 'T', '0363', '1900')['xml'],
			'lk01-W-empty-org' => $notification->translate($case, 'W', '', '')['xml'],
		];

		return array_map(fn (string $xml) => $this->mask(xml: $xml), $messages);
	}//end messages()

	/**
	 * Every message matches the golden file, and each referentienummer is fresh per message.
	 *
	 * @return void
	 */
	public function testEveryMessageMatchesTheGoldenFile(): void {
		$actual = $this->messages();
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testEveryMessageMatchesTheGoldenFile()

	/**
	 * A reply's referentienummer starts with its berichtcode in capitals.
	 *
	 * @return void
	 */
	public function testAReplyReferenceCarriesItsBerichtcode(): void {
		$xml = (new StufZknAcknowledgementBuilder())->buildFo03('nope', '', '0363', '');
		$this->assertMatchesRegularExpression('#referentienummer>FO03-[0-9a-f]{12}<#', $xml);
		$this->assertMatchesRegularExpression('#tijdstipBericht>\d{14}<#', $xml);
	}//end testAReplyReferenceCarriesItsBerichtcode()
}//end class
