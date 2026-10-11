<?php

/**
 * Unit tests for StufZdsResponseReader.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufZdsResponseReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsResponseReader
 */
class StufZdsResponseReaderTest extends TestCase {

	/**
	 * Wrap a body in a SOAP envelope with the StUF namespaces.
	 *
	 * @param string $body The body.
	 *
	 * @return string The envelope.
	 */
	private static function envelope(string $body): string {
		return '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
			. ' xmlns:stuf="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"'
			. ' xmlns:bg="http://www.egem.nl/StUF/sector/bg/0310"><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
	}//end envelope()

	/**
	 * A Du02 names the allocated identificatie and the crossRefnummer.
	 *
	 * @return void
	 */
	public function testConfirmationReadsIdentificatieAndCrossRef(): void {
		$xml = self::envelope('<zkn:genereerZaakIdentificatie_Du02><zkn:stuurgegevens><stuf:crossRefnummer>REF1</stuf:crossRefnummer></zkn:stuurgegevens><zkn:zaak stuf:entiteittype="ZAK"><zkn:identificatie>0363-ZAAK-7</zkn:identificatie></zkn:zaak></zkn:genereerZaakIdentificatie_Du02>');
		$this->assertSame(['crossRefnummer' => 'REF1', 'caseIdentification' => '0363-ZAAK-7'], (new StufZdsResponseReader())->confirmation($xml));
	}//end testConfirmationReadsIdentificatieAndCrossRef()

	/**
	 * A Bv03 without identificatie reads as null.
	 *
	 * @return void
	 */
	public function testBareBevestigingHasNoIdentificatie(): void {
		$xml = self::envelope('<stuf:Bv03Bericht><stuf:stuurgegevens><stuf:crossRefnummer>R</stuf:crossRefnummer></stuf:stuurgegevens></stuf:Bv03Bericht>');
		$this->assertNull((new StufZdsResponseReader())->confirmation($xml)['caseIdentification']);
	}//end testBareBevestigingHasNoIdentificatie()

	/**
	 * A zakLa01 reads into a zaak with its statussen and betrokkenen.
	 *
	 * @return void
	 */
	public function testCaseDetailsReadsTheZaak(): void {
		$xml = self::envelope('<zkn:zakLa01><zkn:antwoord><zkn:object stuf:entiteittype="ZAK"><zkn:identificatie>Z1</zkn:identificatie><zkn:omschrijving>Omschr</zkn:omschrijving><zkn:einddatum>20261231</zkn:einddatum><zkn:zaaktype><zkn:omschrijving>Melding</zkn:omschrijving></zkn:zaaktype>'
			. '<zkn:heeftAlsInitiator><zkn:gerelateerde><bg:inp.bsn>999993653</bg:inp.bsn></zkn:gerelateerde></zkn:heeftAlsInitiator>'
			. '<zkn:heeftStatus><zkn:gerelateerde><zkn:statustype>Ontvangen</zkn:statustype></zkn:gerelateerde><zkn:datumStatusGezet>20261011</zkn:datumStatusGezet></zkn:heeftStatus></zkn:object></zkn:antwoord></zkn:zakLa01>');
		$case = (new StufZdsResponseReader())->caseDetails($xml);
		$this->assertSame('Z1', $case['identificatie']);
		$this->assertSame('20261231', $case['einddatum']);
		$this->assertSame('Melding', $case['zaaktype']['omschrijving']);
		$this->assertSame([['role' => 'heeftAlsInitiator', 'bsn' => '999993653']], $case['betrokkenen']);
		$this->assertSame([['datumStatusGezet' => '20261011', 'statustype' => 'Ontvangen']], $case['statussen']);
	}//end testCaseDetailsReadsTheZaak()

	/**
	 * A busy StUF fout is transient, any other permanent.
	 *
	 * @return void
	 */
	public function testFaultClassification(): void {
		$reader = new StufZdsResponseReader();
		$busy   = $reader->fault(self::envelope('<stuf:Fo02Bericht><stuf:body><stuf:code>StUF067</stuf:code><stuf:omschrijving>Bezet</stuf:omschrijving></stuf:body></stuf:Fo02Bericht>'));
		$this->assertSame(['code' => 'StUF067', 'omschrijving' => 'Bezet', 'details' => '', 'kind' => 'transient'], $busy);
		$this->assertSame('permanent', $reader->fault(self::envelope('<stuf:Fo02Bericht><stuf:body><stuf:code>StUF055</stuf:code></stuf:body></stuf:Fo02Bericht>'))['kind']);
	}//end testFaultClassification()

	/**
	 * An answer with a DTD is refused, not expanded.
	 *
	 * @return void
	 */
	public function testAnAnswerWithAnEntityIsNotExpanded(): void {
		$xml    = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "EXPANDED">]><r><identificatie>&x;</identificatie></r>';
		$reader = new StufZdsResponseReader();
		$this->assertNull($reader->confirmation($xml)['caseIdentification']);
		$this->assertSame('PARSE_ERROR', $reader->fault($xml)['code']);
	}//end testAnAnswerWithAnEntityIsNotExpanded()
}//end class
