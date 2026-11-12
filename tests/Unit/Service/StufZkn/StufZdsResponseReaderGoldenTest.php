<?php

/**
 * Characterisation test for StufZdsResponseReader.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufZdsResponseReader;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the StUF-ZDS answers read.
 */
class StufZdsResponseReaderGoldenTest extends TestCase {

	/**
	 * A SOAP envelope around a body.
	 *
	 * @param string $body The body.
	 *
	 * @return string
	 */
	private static function envelope(string $body): string {
		return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310"'
			. ' xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310"><s:Body>' . $body . '</s:Body></s:Envelope>';
	}//end envelope()

	/**
	 * The recorded reads.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$reader = new StufZdsResponseReader();
		$bad = ['', '   ', '<!DOCTYPE x><x/>', '<not-xml'];
		$confirmations = [
			'antwoord object' => self::envelope('<StUF:Bv03Bericht><StUF:stuurgegevens><StUF:crossRefnummer> REF-1 </StUF:crossRefnummer></StUF:stuurgegevens><ZKN:antwoord><ZKN:object><ZKN:identificatie>Z-1</ZKN:identificatie></ZKN:object></ZKN:antwoord><ZKN:zaak><ZKN:identificatie>Z-2</ZKN:identificatie></ZKN:zaak></StUF:Bv03Bericht>'),
			'zaak' => self::envelope('<x><ZKN:zaak><ZKN:identificatie>Z-2</ZKN:identificatie></ZKN:zaak><ZKN:identificatie>Z-3</ZKN:identificatie></x>'),
			'any identificatie' => self::envelope('<x><ZKN:antwoord><ZKN:object><ZKN:identificatie> </ZKN:identificatie></ZKN:object></ZKN:antwoord><y><ZKN:identificatie>Z-3</ZKN:identificatie></y></x>'),
			'nothing' => self::envelope('<x/>'),
		];
		$details = self::envelope(
			'<ZKN:zakLa01><ZKN:antwoord><ZKN:object>'
			. '<ZKN:identificatie>Z-9</ZKN:identificatie><ZKN:omschrijving>Kapvergunning</ZKN:omschrijving>'
			. '<ZKN:startdatum>20260101</ZKN:startdatum><ZKN:einddatum/>'
			. '<ZKN:zaaktype><ZKN:omschrijving>Kappen</ZKN:omschrijving></ZKN:zaaktype>'
			. '<ZKN:heeftStatus><ZKN:gerelateerde><ZKN:statustype>Ontvangen</ZKN:statustype></ZKN:gerelateerde><ZKN:datumStatusGezet>20260102</ZKN:datumStatusGezet></ZKN:heeftStatus>'
			. '<ZKN:heeftStatus><ZKN:datumStatusGezet>20260103</ZKN:datumStatusGezet></ZKN:heeftStatus>'
			. '<ZKN:heeftAlsInitiator><ZKN:gerelateerde><ZKN:natuurlijkPersoon><BG:inp.bsn>999993653</BG:inp.bsn></ZKN:natuurlijkPersoon></ZKN:gerelateerde></ZKN:heeftAlsInitiator>'
			. '<ZKN:heeftAlsBelanghebbende><ZKN:gerelateerde/></ZKN:heeftAlsBelanghebbende>'
			. '<ZKN:heeftAlsGemachtigde><BG:inp.bsn>111222333</BG:inp.bsn></ZKN:heeftAlsGemachtigde>'
			. '<ZKN:heeftAlsUitvoerende><BG:inp.bsn>000</BG:inp.bsn></ZKN:heeftAlsUitvoerende>'
			. '</ZKN:object></ZKN:antwoord></ZKN:zakLa01>'
		);
		$faults = [
			'transient' => self::envelope('<s:Fault><faultstring>Server</faultstring><detail><StUF:Fo02Bericht><StUF:body><StUF:code>StUF067</StUF:code><StUF:omschrijving>Te druk</StUF:omschrijving><StUF:details>later</StUF:details></StUF:body></StUF:Fo02Bericht></detail></s:Fault>'),
			'permanent fout' => self::envelope('<s:Fault><faultstring>Server</faultstring><StUF:fout><StUF:code>StUF055</StUF:code></StUF:fout></s:Fault>'),
			'any code, faultstring' => self::envelope('<s:Fault><faultstring>Onbekend</faultstring><x><code>StUF019</code></x></s:Fault>'),
			'empty fault' => self::envelope('<s:Fault/>'),
		];

		$record = ['confirmation' => [], 'caseDetails' => [], 'fault' => []];
		foreach ($confirmations + array_combine(['bad empty', 'bad blank', 'bad doctype', 'bad xml'], $bad) as $name => $xml) {
			$record['confirmation'][$name] = $reader->confirmation($xml);
		}

		foreach (['full' => $details, 'empty envelope' => self::envelope('<x/>'), 'bad doctype' => $bad[2]] as $name => $xml) {
			$record['caseDetails'][$name] = $reader->caseDetails($xml);
		}

		foreach ($faults + ['bad xml' => $bad[3]] as $name => $xml) {
			$record['fault'][$name] = $reader->fault($xml);
		}

		$path = __DIR__ . '/../../../fixtures/stuf-zkn/zds-response-reader.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
