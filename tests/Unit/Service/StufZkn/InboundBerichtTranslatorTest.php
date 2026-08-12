<?php

/**
 * Unit tests for InboundBerichtTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\StufZkn
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

namespace OCA\OpenConnector\Tests\Unit\Service\StufZkn;

use OCA\OpenConnector\Exception\StufZknTranslationException;
use OCA\OpenConnector\Service\StufZkn\InboundBerichtTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the inbound zakLk01/edcLk01 -> normalised zaak/document translator, including
 * verwerkingssoort handling, noValue/nil handling, and the referentienummer/identificatie
 * literal-leak guards.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-inbound-zaklk01-edclk01-translation-with-a-literal-leak-guard-req-002
 */
class InboundBerichtTranslatorTest extends TestCase {

	/**
	 * @var InboundBerichtTranslator
	 */
	private InboundBerichtTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new InboundBerichtTranslator();

	}//end setUp()

	/**
	 * Build a minimal `zakLk01` SOAP envelope.
	 *
	 * @param string $verwerkingssoort The `object`'s verwerkingssoort attribute.
	 * @param string $referentienummer The stuurgegevens.referentienummer.
	 * @param string $identificatie The object's identificatie ('' to omit the element).
	 * @param string $toelichtingXml Raw `<zkn:toelichting>` XML fragment (default: nil).
	 *
	 * @return string
	 */
	private function zakLk01(
		string $verwerkingssoort = 'T',
		string $referentienummer = 'REF-1',
		string $identificatie = 'ZAAK-1',
		string $toelichtingXml = '<zkn:toelichting StUF:noValue="geenWaarde" xsi:nil="true"/>',
	): string {
		$identificatieXml = ($identificatie === '') ? '' : '<zkn:identificatie>' . $identificatie . '</zkn:identificatie>';

		return <<<XML
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:StUF="http://www.egem.nl/StUF/StUF0301"
                xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soap:Body>
    <zkn:zakLk01>
      <zkn:stuurgegevens>
        <StUF:berichtcode>Lk01</StUF:berichtcode>
        <StUF:zender><StUF:organisatie>Gemeente X</StUF:organisatie></StUF:zender>
        <StUF:ontvanger><StUF:organisatie>Procest</StUF:organisatie></StUF:ontvanger>
        <StUF:referentienummer>{$referentienummer}</StUF:referentienummer>
        <StUF:tijdstipBericht>20260716120000</StUF:tijdstipBericht>
        <StUF:entiteittype>ZAK</StUF:entiteittype>
      </zkn:stuurgegevens>
      <zkn:parameters>
        <StUF:mutatiesoort>{$verwerkingssoort}</StUF:mutatiesoort>
        <StUF:indicatorOvername>V</StUF:indicatorOvername>
      </zkn:parameters>
      <zkn:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="{$verwerkingssoort}">
        {$identificatieXml}
        <zkn:omschrijving>Vergunningaanvraag kap boom</zkn:omschrijving>
        {$toelichtingXml}
        <zkn:zaaktype>
          <zkn:code>B0337</zkn:code>
          <zkn:omschrijving>Kapvergunning</zkn:omschrijving>
        </zkn:zaaktype>
        <zkn:registratiedatum>20260716</zkn:registratiedatum>
        <zkn:startdatum>20260716</zkn:startdatum>
      </zkn:object>
    </zkn:zakLk01>
  </soap:Body>
</soap:Envelope>
XML;

	}//end zakLk01()

	/**
	 * Build a minimal `edcLk01` SOAP envelope.
	 *
	 * @return string
	 */
	private function edcLk01(): string {
		return <<<XML
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:StUF="http://www.egem.nl/StUF/StUF0301"
                xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soap:Body>
    <zkn:edcLk01>
      <zkn:stuurgegevens>
        <StUF:berichtcode>Lk01</StUF:berichtcode>
        <StUF:zender><StUF:organisatie>Gemeente X</StUF:organisatie></StUF:zender>
        <StUF:ontvanger><StUF:organisatie>Procest</StUF:organisatie></StUF:ontvanger>
        <StUF:referentienummer>REF-DOC-1</StUF:referentienummer>
        <StUF:tijdstipBericht>20260716120000</StUF:tijdstipBericht>
        <StUF:entiteittype>EDC</StUF:entiteittype>
      </zkn:stuurgegevens>
      <zkn:parameters>
        <StUF:mutatiesoort>T</StUF:mutatiesoort>
      </zkn:parameters>
      <zkn:object StUF:entiteittype="EDC" StUF:verwerkingssoort="T">
        <zkn:identificatie>DOC-1</zkn:identificatie>
        <zkn:titel>Kapvergunning besluit</zkn:titel>
        <zkn:formaat>application/pdf</zkn:formaat>
        <zkn:creatiedatum>20260716</zkn:creatiedatum>
        <zkn:isRelevantVoor>
          <zkn:gerelateerde>
            <zkn:identificatie>ZAAK-1</zkn:identificatie>
          </zkn:gerelateerde>
        </zkn:isRelevantVoor>
      </zkn:object>
    </zkn:edcLk01>
  </soap:Body>
</soap:Envelope>
XML;

	}//end edcLk01()

	/**
	 * A complete `zakLk01` toevoeging translates to a normalised zaak representation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-complete-zaklk01-toevoeging-translates-to-a-normalised-zaak-representation
	 */
	public function testCompleteZakLk01ToevoegingTranslatesToNormalisedZaak(): void {
		$result = $this->translator->translate($this->zakLk01(verwerkingssoort: 'T'));

		$this->assertSame('zaak', $result['kind']);
		$this->assertSame('zakLk01', $result['berichttype']);
		$this->assertSame('REF-1', $result['referentienummer']);
		$this->assertSame('Gemeente X', $result['senderOrganisatie']);
		$this->assertSame('T', $result['verwerkingssoort']);
		$this->assertSame('ZAK', $result['entiteittype']);
		$this->assertSame('ZAAK-1', $result['fields']['identificatie']);
		$this->assertSame('Vergunningaanvraag kap boom', $result['fields']['omschrijving']);
		$this->assertSame('B0337', $result['fields']['zaaktypeCode']);
		$this->assertSame('Kapvergunning', $result['fields']['zaaktypeOmschrijving']);
		$this->assertSame('20260716', $result['fields']['registratiedatum']);
		$this->assertSame('20260716', $result['fields']['startdatum']);
		$this->assertNull($result['fields']['toelichting']);

	}//end testCompleteZakLk01ToevoegingTranslatesToNormalisedZaak()

	/**
	 * A complete `edcLk01` translates to a normalised document representation, including the
	 * related zaak identificatie.
	 *
	 * @return void
	 */
	public function testCompleteEdcLk01TranslatesToNormalisedDocument(): void {
		$result = $this->translator->translate($this->edcLk01());

		$this->assertSame('document', $result['kind']);
		$this->assertSame('edcLk01', $result['berichttype']);
		$this->assertSame('EDC', $result['entiteittype']);
		$this->assertSame('DOC-1', $result['fields']['identificatie']);
		$this->assertSame('Kapvergunning besluit', $result['fields']['titel']);
		$this->assertSame('application/pdf', $result['fields']['formaat']);
		$this->assertSame('ZAAK-1', $result['fields']['zaakIdentificatie']);

	}//end testCompleteEdcLk01TranslatesToNormalisedDocument()

	/**
	 * Each recognised verwerkingssoort (T/W/I/V) is accepted and echoed back.
	 *
	 * @param string $verwerkingssoort The verwerkingssoort code under test.
	 *
	 * @return void
	 *
	 * @dataProvider verwerkingssoortProvider
	 */
	public function testEachVerwerkingssoortIsAccepted(string $verwerkingssoort): void {
		$result = $this->translator->translate($this->zakLk01(verwerkingssoort: $verwerkingssoort));
		$this->assertSame($verwerkingssoort, $result['verwerkingssoort']);

	}//end testEachVerwerkingssoortIsAccepted()

	/**
	 * Data provider for the four recognised verwerkingssoort codes.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function verwerkingssoortProvider(): array {
		return [
			'T (toevoeging)' => ['T'],
			'W (wijziging)' => ['W'],
			'I (wijziging identificerend gegeven)' => ['I'],
			'V (vervallen)' => ['V'],
		];

	}//end verwerkingssoortProvider()

	/**
	 * An unrecognised verwerkingssoort is rejected.
	 *
	 * @return void
	 */
	public function testUnrecognisedVerwerkingssoortThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($this->zakLk01(verwerkingssoort: 'X'));

	}//end testUnrecognisedVerwerkingssoortThrows()

	/**
	 * A field explicitly marked StUF:noValue/xsi:nil is read as null, never an empty-string literal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-novalue-nil-field-is-read-as-null-never-an-empty-string-literal
	 */
	public function testNoValueNilFieldReadsAsNull(): void {
		$result = $this->translator->translate($this->zakLk01());
		$this->assertNull($result['fields']['toelichting']);

	}//end testNoValueNilFieldReadsAsNull()

	/**
	 * A present, non-nil field is read as its trimmed text value, distinct from the nil case.
	 *
	 * @return void
	 */
	public function testPresentFieldIsReadAsText(): void {
		$result = $this->translator->translate(
			$this->zakLk01(toelichtingXml: '<zkn:toelichting>Spoedaanvraag</zkn:toelichting>')
		);
		$this->assertSame('Spoedaanvraag', $result['fields']['toelichting']);

	}//end testPresentFieldIsReadAsText()

	/**
	 * A missing/empty referentienummer never reaches an OR mapping — literal-leak guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-missing-referentienummer-or-identificatie-never-reaches-an-or-mapping--literal-leak-guard
	 */
	public function testMissingReferentienummerThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($this->zakLk01(referentienummer: ''));

	}//end testMissingReferentienummerThrows()

	/**
	 * A missing/empty identificatie never reaches an OR mapping — literal-leak guard.
	 *
	 * @return void
	 */
	public function testMissingIdentificatieThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($this->zakLk01(identificatie: ''));

	}//end testMissingIdentificatieThrows()

	/**
	 * An envelope with neither zakLk01 nor edcLk01 is rejected.
	 *
	 * @return void
	 */
	public function testUnrecognisedBerichttypeThrows(): void {
		$xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><zkn:somethingElse xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"/></soap:Body>'
			. '</soap:Envelope>';

		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($xml);

	}//end testUnrecognisedBerichttypeThrows()

	/**
	 * Empty input is rejected.
	 *
	 * @return void
	 */
	public function testEmptyInputThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate('');

	}//end testEmptyInputThrows()

	/**
	 * Malformed XML is rejected.
	 *
	 * @return void
	 */
	public function testMalformedXmlThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate('<soap:Envelope><unclosed></soap:Envelope>');

	}//end testMalformedXmlThrows()

	/**
	 * An XXE payload is rejected (or left inert), never resolved into the response — proven by
	 * the translator either throwing (malformed after the DOCTYPE-carrying parse) or, if parsed,
	 * never surfacing `/etc/passwd` content anywhere in the returned array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-an-xxe-payload-in-an-inbound-envelope-is-rejected-or-left-unexpanded
	 */
	public function testXxePayloadIsRejectedOrNeverResolved(): void {
		$xxe = '<?xml version="1.0"?>'
			. '<!DOCTYPE root [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body>&xxe;</soap:Body></soap:Envelope>';

		try {
			$result = $this->translator->translate($xxe);
			$this->assertStringNotContainsString('root:', json_encode($result));
		} catch (StufZknTranslationException $exception) {
			$this->assertTrue(true);
		}

	}//end testXxePayloadIsRejectedOrNeverResolved()
}//end class
