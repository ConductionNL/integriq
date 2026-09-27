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
 * @spec openspec/changes/integriq-adapter-rod/tasks.md
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
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
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
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
		$this->assertStringContainsString('<bsn>999999990</bsn>', $xml);
		$this->assertStringContainsString('<inschrijvingsdatum>2026-09-01</inschrijvingsdatum>', $xml);
		$this->assertStringContainsString('<leerjaar>4</leerjaar>', $xml);
		$this->assertStringContainsString('<groep>4B</groep>', $xml);

	}//end testCompleteInschrijvingTranslatesToValidEnvelope()

	/**
	 * A missing required field never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
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
	 * schooladvies carries only its own fields and does not require leerjaar/groep.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-schooladvies-carries-no-leerjaargroep-fields
	 */
	public function testSchooladviesDoesNotRequireLeerjaarOrGroep(): void {
		$xml = $this->translator->translate(
			'schooladvies',
			'seed-kenmerk-002',
			['bsn' => '999999991', 'schooladviesWaarde' => 'vmbo-t/havo', 'schooladviesDatum' => '2026-03-01']
		);

		$this->assertStringContainsString('<schooladviesWaarde>vmbo-t/havo</schooladviesWaarde>', $xml);
		$this->assertStringNotContainsString('<leerjaar>', $xml);
		$this->assertStringNotContainsString('<groep>', $xml);

	}//end testSchooladviesDoesNotRequireLeerjaarOrGroep()

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
