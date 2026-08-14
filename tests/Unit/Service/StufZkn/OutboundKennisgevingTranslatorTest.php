<?php

/**
 * Unit tests for OutboundKennisgevingTranslator.
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
use OCA\OpenConnector\Service\StufZkn\OutboundKennisgevingTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OR/ZGW zaak -> zakLk01 kennisgeving translator, including the literal-leak
 * guard and a structural round-trip through the inbound translator.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */
class OutboundKennisgevingTranslatorTest extends TestCase {

	/**
	 * @var OutboundKennisgevingTranslator
	 */
	private OutboundKennisgevingTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OutboundKennisgevingTranslator();

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

		$inbound = (new InboundBerichtTranslator())->translate($result['xml']);

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
}//end class
