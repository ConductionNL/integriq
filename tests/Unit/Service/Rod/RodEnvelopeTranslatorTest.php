<?php

/**
 * Unit tests for RodEnvelopeTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-29-integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ROD outbound envelope translator, contract-tested against
 * recorded fixtures under tests/fixtures/rod/.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
class RodEnvelopeTranslatorTest extends TestCase {

	/**
	 * @var RodEnvelopeTranslator
	 */
	private RodEnvelopeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new RodEnvelopeTranslator();

	}//end setUp()

	/**
	 * A complete inschrijving translates to a valid envelope carrying every field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
	 */
	public function testCompleteInschrijvingTranslatesToValidEnvelope(): void {
		$xml = $this->translator->translate(
			'inschrijving',
			'seed-kenmerk-001',
			[
				'bsn' => '999999990',
				'inschrijvingsdatum' => '2026-09-01',
				'leerjaar' => 4,
				'groep' => '4B',
			]
		);

		$this->assertStringContainsString('<berichtsoort>inschrijving</berichtsoort>', $xml);
		$this->assertStringContainsString('<kenmerk>seed-kenmerk-001</kenmerk>', $xml);
		// The legacy `bsn` key reads as a burgerservicenummer in DUO's choice element.
		$this->assertStringContainsString(
			'<persoonsgebondenNummer><burgerservicenummer>999999990</burgerservicenummer></persoonsgebondenNummer>',
			$xml
		);
		$this->assertStringNotContainsString('<bsn>', $xml);
		$this->assertStringContainsString('<inschrijvingsdatum>2026-09-01</inschrijvingsdatum>', $xml);
		$this->assertStringContainsString('<leerjaar>4</leerjaar>', $xml);
		$this->assertStringContainsString('<groep>4B</groep>', $xml);

	}//end testCompleteInschrijvingTranslatesToValidEnvelope()

	/**
	 * A missing required field never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	public function testMissingRequiredFieldNeverReachesEnvelope(): void {
		$this->expectException(RodTranslationException::class);
		$this->expectExceptionMessage('Required field "leerjaar" is missing or empty');

		$this->translator->translate(
			'inschrijving',
			'seed-kenmerk-001',
			['bsn' => '999999990', 'inschrijvingsdatum' => '2026-09-01', 'groep' => '4B']
		);

	}//end testMissingRequiredFieldNeverReachesEnvelope()

	/**
	 * An empty-string required field is treated the same as a missing one.
	 *
	 * @return void
	 */
	public function testEmptyStringRequiredFieldRaises(): void {
		$this->expectException(RodTranslationException::class);

		$this->translator->translate(
			'inschrijving',
			'seed-kenmerk-001',
			['bsn' => '999999990', 'inschrijvingsdatum' => '2026-09-01', 'leerjaar' => 4, 'groep' => '']
		);

	}//end testEmptyStringRequiredFieldRaises()

	/**
	 * A school advice record as learniq hands it.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function advies(array $override=[]): array {
		return array_merge(
			[
				'persoonsgebondenNummer' => '123456782',
				'persoonsgebondenNummerType' => 'onderwijsnummer',
				'adviesvolgnummer' => 'ADV2026001',
				'onderwijsaanbieder' => '100A200',
				'onderwijslocatie' => '100X200',
				'vestigingscode' => '12AB00',
				'adviesjaar' => '2026',
				'advies1' => 'VMBO_KB',
				'advies1Datum' => '2026-01-20',
				'advies2' => 'VMBO_KB_TM_VMBO_GL/TL',
				'advies2Datum' => '2026-05-15',
			],
			$override
		);
	}//end advies()

	/**
	 * schooladvies renders DUO's AanleverenAdviesVO_Request, in PvE order, with both advices.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-advice-with-a-reconsidered-definitive-advice
	 */
	public function testSchooladviesRendersAanleverenAdviesVoRequest(): void {
		$xml = $this->translator->translate('schooladvies', 'seed-kenmerk-002', $this->advies());

		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$request = $doc->getElementsByTagNameNS(RodEnvelopeTranslator::ADVIES_VO_NAMESPACE, 'AanleverenAdviesVO_Request')->item(0);
		$this->assertNotNull($request, 'the request element carries the DUO_PO_AdviesVO_V1 namespace');

		$children = [];
		foreach ($request->childNodes as $child) {
			$children[] = $child->localName;
		}

		$this->assertSame(
			['persoonsgebondenNummer', 'adviesvolgnummer', 'onderwijsaanbieder', 'onderwijslocatie', 'vestigingscode', 'adviesjaar', 'advies1', 'advies2'],
			$children
		);
		$this->assertStringContainsString('<persoonsgebondenNummer><onderwijsnummer>123456782</onderwijsnummer></persoonsgebondenNummer>', $xml);
		$this->assertStringContainsString('<advies1><advies>VMBO_KB</advies><adviesdatum>2026-01-20</adviesdatum></advies1>', $xml);
		$this->assertStringContainsString('<advies2><advies>VMBO_KB_TM_VMBO_GL/TL</advies><adviesdatum>2026-05-15</adviesdatum></advies2>', $xml);
		$this->assertStringNotContainsString('<leerjaar>', $xml);
		$this->assertStringNotContainsString('<burgerservicenummer>', $xml);

	}//end testSchooladviesRendersAanleverenAdviesVoRequest()

	/**
	 * A null advies2 and null location codes are left out, not rendered empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-advice-without-a-second-advice
	 */
	public function testNullAdvies2AndLocationsAreLeftOut(): void {
		$xml = $this->translator->translate(
			'schooladvies',
			'k',
			$this->advies(['advies2' => null, 'advies2Datum' => null, 'onderwijsaanbieder' => null, 'onderwijslocatie' => null])
		);

		$this->assertStringNotContainsString('advies2', $xml);
		$this->assertStringNotContainsString('onderwijsaanbieder', $xml);
		$this->assertStringNotContainsString('onderwijslocatie', $xml);
		$this->assertStringContainsString('<advies1>', $xml);

	}//end testNullAdvies2AndLocationsAreLeftOut()

	/**
	 * Every malformed field fails translation, naming the field and never the number.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-malformed-onderwijsaanbieder
	 */
	public function testMalformedAdviesFieldsAreRefusedByName(): void {
		$cases = [
			'onderwijsaanbieder' => ['onderwijsaanbieder' => '100B200'],
			'onderwijslocatie' => ['onderwijslocatie' => '100A200'],
			'adviesvolgnummer' => ['adviesvolgnummer' => 'ADV-2026'],
			'vestigingscode' => ['vestigingscode' => '12AB'],
			'adviesjaar' => ['adviesjaar' => '26'],
			'advies1' => ['advies1' => 'vmbo-kb'],
			'advies1Datum' => ['advies1Datum' => '20-01-2026'],
			'advies2Datum' => ['advies2Datum' => null],
			'persoonsgebondenNummerType' => ['persoonsgebondenNummerType' => 'paspoort'],
			'persoonsgebondenNummer' => ['persoonsgebondenNummer' => '12345678'],
			'advies1" and "advies2' => ['advies1' => null, 'advies1Datum' => null, 'advies2' => null, 'advies2Datum' => null],
		];
		foreach ($cases as $field => $override) {
			try {
				$this->translator->translate('schooladvies', 'k', $this->advies($override));
				$this->fail('Expected a refusal for '.$field);
			} catch (RodTranslationException $exception) {
				$this->assertStringContainsString('"'.$field.'"', $exception->getMessage());
				$this->assertStringNotContainsString('123456782', $exception->getMessage());
			}
		}

	}//end testMalformedAdviesFieldsAreRefusedByName()

	/**
	 * The learner record as learniq hands it, through the seeded learner mapping,
	 * puts the number in the choice element its type names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-an-onderwijsnummer
	 */
	public function testALearnerWithAnOnderwijsnummerUsesTheOnderwijsnummerElement(): void {
		$xml = $this->translator->translate(
			'inschrijving',
			'k',
			[
				'eckId' => 'https://ketenid.nl/201703/00000000',
				'persoonsgebondenNummer' => '123456782',
				'persoonsgebondenNummerType' => 'onderwijsnummer',
				'inschrijvingsdatum' => '2026-09-01',
				'leerjaar' => 4,
				'groep' => '4B',
			]
		);

		$this->assertStringContainsString('<persoonsgebondenNummer><onderwijsnummer>123456782</onderwijsnummer></persoonsgebondenNummer>', $xml);
		$this->assertStringNotContainsString('burgerservicenummer', $xml);
		$this->assertStringNotContainsString('ketenid', $xml);

	}//end testALearnerWithAnOnderwijsnummerUsesTheOnderwijsnummerElement()

	/**
	 * An unsupported berichtsoort is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedBerichtsoortRaises(): void {
		$this->expectException(RodTranslationException::class);
		$this->expectExceptionMessage('Unsupported ROD berichtsoort "onbekend"');

		$this->translator->translate('onbekend', 'k1', ['bsn' => '999999990']);

	}//end testUnsupportedBerichtsoortRaises()

	/**
	 * An empty kenmerk is rejected before any envelope is built.
	 *
	 * @return void
	 */
	public function testEmptyKenmerkRaises(): void {
		$this->expectException(RodTranslationException::class);

		$this->translator->translate(
			'inschrijving',
			'',
			['bsn' => '999999990', 'inschrijvingsdatum' => '2026-09-01', 'leerjaar' => 4, 'groep' => '4B']
		);

	}//end testEmptyKenmerkRaises()

	/**
	 * Optional OPP dates are appended only when present.
	 *
	 * @return void
	 */
	public function testOptionalOppDatesAppendedOnlyWhenPresent(): void {
		$withoutOpp = $this->translator->translate(
			'inschrijving',
			'k1',
			['bsn' => '999999990', 'inschrijvingsdatum' => '2026-09-01', 'leerjaar' => 4, 'groep' => '4B']
		);
		$this->assertStringNotContainsString('<oppStartdatum>', $withoutOpp);

		$withOpp = $this->translator->translate(
			'inschrijving',
			'k1',
			[
				'bsn' => '999999990',
				'inschrijvingsdatum' => '2026-09-01',
				'leerjaar' => 4,
				'groep' => '4B',
				'oppStartdatum' => '2026-09-01',
			]
		);
		$this->assertStringContainsString('<oppStartdatum>2026-09-01</oppStartdatum>', $withOpp);

	}//end testOptionalOppDatesAppendedOnlyWhenPresent()

	/**
	 * uitschrijving and verblijfsgegevens each translate with their own required fields.
	 *
	 * @return void
	 */
	public function testUitschrijvingAndVerblijfsgegevensTranslate(): void {
		$uitschrijving = $this->translator->translate(
			'uitschrijving',
			'k1',
			['bsn' => '999999990', 'uitschrijvingsdatum' => '2026-07-01', 'redenUitschrijving' => 'verhuizing']
		);
		$this->assertStringContainsString('<redenUitschrijving>verhuizing</redenUitschrijving>', $uitschrijving);

		$verblijfsgegevens = $this->translator->translate(
			'verblijfsgegevens',
			'k2',
			['bsn' => '999999990', 'ingangsdatum' => '2026-09-01', 'leerjaar' => 5, 'groep' => '5A']
		);
		$this->assertStringContainsString('<leerjaar>5</leerjaar>', $verblijfsgegevens);

	}//end testUitschrijvingAndVerblijfsgegevensTranslate()
}//end class
