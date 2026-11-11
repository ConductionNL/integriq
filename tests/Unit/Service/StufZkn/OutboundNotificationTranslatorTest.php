<?php

/**
 * Unit tests for OutboundNotificationTranslator.
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

use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\InboundMessageTranslator;
use OCA\Integriq\Service\StufZkn\OutboundNotificationTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OR/ZGW zaak -> zakLk01 kennisgeving translator, including the literal-leak
 * guard and a structural round-trip through the inbound translator.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */
class OutboundNotificationTranslatorTest extends TestCase {

	private const GOLDEN_FIRST_ENVELOPE = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soap:Body><zkn:zakLk01><zkn:stuurgegevens><StUF:berichtcode>Lk01</StUF:berichtcode><StUF:zender><StUF:organisatie>GEM-0363</StUF:organisatie></StUF:zender><StUF:ontvanger><StUF:organisatie>ZKN-APP</StUF:organisatie></StUF:ontvanger><StUF:referentienummer>ZKN-REF</StUF:referentienummer><StUF:tijdstipBericht>TIME</StUF:tijdstipBericht><StUF:entiteittype>ZAK</StUF:entiteittype></zkn:stuurgegevens><zkn:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort><StUF:indicatorOvername>V</StUF:indicatorOvername></zkn:parameters><zkn:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="T"><zkn:identificatie>ZAAK-2026-001</zkn:identificatie><zkn:omschrijving>Kapvergunning</zkn:omschrijving><zkn:toelichting StUF:noValue="geenWaarde" xsi:nil="true"/><zkn:zaaktype><zkn:code>B0337</zkn:code><zkn:omschrijving>Kapvergunning</zkn:omschrijving></zkn:zaaktype><zkn:registratiedatum>20260716</zkn:registratiedatum><zkn:startdatum>20260716</zkn:startdatum></zkn:object></zkn:zakLk01></soap:Body></soap:Envelope>
';

	private const GOLDEN_ENVELOPES_SHA1 = '873b19ba72f28138c34ad5cce5ba0c847971234f';

	private const GOLDEN_REFUSALS = [
		'Unsupported outbound verwerkingssoort "X" (expected T, W, or V).',
		'Required field "startdatum" is missing or empty for an outbound zaak kennisgeving — refusing to build an envelope with unresolved data.',
		'Required field "omschrijving" is missing or empty for an outbound zaak kennisgeving — refusing to build an envelope with unresolved data.',
		'Rendered kennisgeving still contains an unresolved template marker — refusing to send.',
	];

	/**
	 * @var OutboundNotificationTranslator
	 */
	private OutboundNotificationTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OutboundNotificationTranslator();

	}//end setUp()

	/**
	 * A complete zaak set.
	 *
	 * @param array $overrides Extra fields merged over the default.
	 *
	 * @return array
	 */
	private function case(array $overrides = []): array {
		return array_merge(
			[
				'identificatie' => 'ZAAK-2026-001',
				'omschrijving' => 'Kapvergunning',
				'zaaktypeCode' => 'B0337',
				'zaaktypeOmschrijving' => 'Kapvergunning',
				'registratiedatum' => '20260716',
				'startdatum' => '20260716',
			],
			$overrides
		);

	}//end zaak()

	/**
	 * A complete zaak create translates to a valid `zakLk01` toevoeging.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-complete-zaak-create-translates-to-a-valid-zaklk01-toevoeging
	 */
	public function testCompleteZaakCreateTranslatesToValidZakLk01(): void {
		$result = $this->translator->translate($this->case(), 'T', 'Procest', 'Gemeente X');

		$this->assertNotEmpty($result['referentienummer']);
		$this->assertStringStartsWith('ZKN-', $result['referentienummer']);
		$this->assertStringContainsString('<zkn:zakLk01>', $result['xml']);
		$this->assertStringContainsString('StUF:verwerkingssoort="T"', $result['xml']);
		$this->assertStringContainsString('<zkn:identificatie>ZAAK-2026-001</zkn:identificatie>', $result['xml']);

	}//end testCompleteZaakCreateTranslatesToValidZakLk01()

	/**
	 * The rendered envelope round-trips structurally through the inbound translator (zaak/document
	 * shape consistency between both directions).
	 *
	 * @return void
	 */
	public function testRenderedEnvelopeRoundTripsThroughInboundTranslator(): void {
		$result = $this->translator->translate($this->case(), 'W', 'Procest', 'Gemeente X');

		$inbound = (new InboundMessageTranslator())->translate($result['xml']);

		$this->assertSame('zaak', $inbound['kind']);
		$this->assertSame('W', $inbound['verwerkingssoort']);
		$this->assertSame('ZAAK-2026-001', $inbound['fields']['identificatie']);
		$this->assertSame($result['referentienummer'], $inbound['referentienummer']);

	}//end testRenderedEnvelopeRoundTripsThroughInboundTranslator()

	/**
	 * Each supported verwerkingssoort (T/W/V) is accepted.
	 *
	 * @param string $processingKind The verwerkingssoort code under test.
	 *
	 * @return void
	 *
	 * @dataProvider verwerkingssoortProvider
	 */
	public function testEachSupportedVerwerkingssoortIsAccepted(string $processingKind): void {
		$result = $this->translator->translate($this->case(), $processingKind, 'Procest', 'Gemeente X');
		$this->assertStringContainsString('StUF:verwerkingssoort="' . $processingKind . '"', $result['xml']);

	}//end testEachSupportedVerwerkingssoortIsAccepted()

	/**
	 * Data provider for the three outbound-supported verwerkingssoort codes.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function verwerkingssoortProvider(): array {
		return [
			'T (toevoeging)' => ['T'],
			'W (wijziging)' => ['W'],
			'V (vervallen)' => ['V'],
		];

	}//end verwerkingssoortProvider()

	/**
	 * An unsupported verwerkingssoort is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedVerwerkingssoortThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($this->case(), 'I', 'Procest', 'Gemeente X');

	}//end testUnsupportedVerwerkingssoortThrows()

	/**
	 * A missing required field never reaches the XML — literal-leak guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-missing-required-field-never-reaches-the-xml--literal-leak-guard
	 */
	public function testMissingRequiredFieldThrows(): void {
		$case = $this->case();
		unset($case['zaaktypeCode']);

		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($case, 'T', 'Procest', 'Gemeente X');

	}//end testMissingRequiredFieldThrows()

	/**
	 * An empty-string required field is treated identically to a missing one.
	 *
	 * @return void
	 */
	public function testEmptyStringRequiredFieldThrows(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->translator->translate($this->case(['omschrijving' => '   ']), 'T', 'Procest', 'Gemeente X');

	}//end testEmptyStringRequiredFieldThrows()

	/**
	 * An optional missing toelichting renders as an explicit StUF:noValue/xsi:nil field, never an
	 * empty tag.
	 *
	 * @return void
	 */
	public function testMissingOptionalFieldRendersAsNilNotEmptyTag(): void {
		$result = $this->translator->translate($this->case(), 'T', 'Procest', 'Gemeente X');

		$this->assertStringContainsString('StUF:noValue="geenWaarde"', $result['xml']);
		$this->assertStringContainsString('xsi:nil="true"', $result['xml']);
		$this->assertStringNotContainsString('<zkn:toelichting></zkn:toelichting>', $result['xml']);

	}//end testMissingOptionalFieldRendersAsNilNotEmptyTag()

	/**
	 * GOLDEN: the rendered envelope for every combination of the optional
	 * fields and every verwerkingssoort, with the random reference and the
	 * message time normalised, plus every refusal message. Pinned on the
	 * translator before its builders were reshaped.
	 *
	 * @return void
	 */
	public function testGoldenEnvelopesAndRefusals(): void {
		$rendered = [];
		$optional = [
			[],
			['toelichting' => 'Boom & <tuin> "achter"', 'einddatum' => '20261231', 'status' => 'afgerond'],
			['zaaktypeOmschrijving' => '', 'toelichting' => '0', 'status' => ''],
		];
		foreach ($optional as $overrides) {
			foreach (['T', 'W', 'V'] as $kind) {
				$result = $this->translator->translate($this->case($overrides), $kind, 'GEM-0363', 'ZKN-APP');
				$this->assertMatchesRegularExpression('/^ZKN-[0-9a-f]{16}$/', $result['referentienummer']);
				$xml = str_replace($result['referentienummer'], 'ZKN-REF', $result['xml']);
				$rendered[] = (string)preg_replace('/<StUF:tijdstipBericht>\d{14}</', '<StUF:tijdstipBericht>TIME<', $xml);
			}
		}

		$refusals = [];
		foreach ([['X', []], ['T', ['startdatum' => '  ']], ['T', ['omschrijving' => null]], ['T', ['toelichting' => '{{ zaak.toelichting }}']]] as [$kind, $overrides]) {
			try {
				$this->translator->translate($this->case($overrides), $kind, 'GEM-0363', 'ZKN-APP');
				$refusals[] = 'none';
			} catch (StufZknTranslationException $exception) {
				$refusals[] = $exception->getMessage();
			}
		}

		$this->assertSame(self::GOLDEN_FIRST_ENVELOPE, $rendered[0]);
		$this->assertSame(self::GOLDEN_ENVELOPES_SHA1, sha1(implode("\n", $rendered)));
		$this->assertSame(self::GOLDEN_REFUSALS, $refusals);

	}//end testGoldenEnvelopesAndRefusals()

}//end class
