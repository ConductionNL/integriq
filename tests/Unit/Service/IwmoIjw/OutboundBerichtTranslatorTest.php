<?php

/**
 * Unit tests for OutboundBerichtTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\IwmoIjw;

use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\IwmoIjw\OutboundBerichtTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the toewijzing/declaratie -> Wmo/Jw envelope translator, including the
 * literal-leak guard.
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */
class OutboundBerichtTranslatorTest extends TestCase {

	/**
	 * @var OutboundBerichtTranslator
	 */
	private OutboundBerichtTranslator $translator;

	/**
	 * A complete toewijzing case object.
	 *
	 * @var array
	 */
	private array $toewijzing = [
		'bsn' => '999995571',
		'productcode' => '05C05',
		'ingangsdatum' => '2026-08-01',
		'omvang' => '4 uur per week',
		'leveringsvorm' => 'ZIN',
		'aanbiederAgbCode' => '01234567',
		'gemeentecode' => 'GM0344',
	];

	/**
	 * A complete declaratie case object.
	 *
	 * @var array
	 */
	private array $declaratie = [
		'toewijzingReferentie' => 'WMO-abc123',
		'productcode' => '05C05',
		'factuurnummer' => 'F-2026-001',
		'bedrag' => '480.00',
		'periodeStart' => '2026-08-01',
		'periodeEind' => '2026-08-31',
		'aanbiederAgbCode' => '01234567',
		'gemeentecode' => 'GM0344',
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OutboundBerichtTranslator();

	}//end setUp()

	/**
	 * A complete toewijzing translates to a valid Wmo303 envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-complete-toewijzing-translates-to-a-valid-wmo303-envelope
	 */
	public function testToewijzingTranslatesToWmo303(): void {
		$result = $this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');

		$this->assertSame('Wmo303', $result['berichttype']);
		$this->assertNotSame('', $result['ref']);
		$this->assertStringContainsString('<bsn>999995571</bsn>', $result['xml']);
		$this->assertStringContainsString('<productcode>05C05</productcode>', $result['xml']);
		$this->assertStringContainsString('<ingangsdatum>2026-08-01</ingangsdatum>', $result['xml']);
		$this->assertStringContainsString('<omvang>4 uur per week</omvang>', $result['xml']);
		$this->assertStringContainsString('<leveringsvorm>ZIN</leveringsvorm>', $result['xml']);
		$this->assertStringContainsString('<berichtcode>Wmo303</berichtcode>', $result['xml']);

	}//end testToewijzingTranslatesToWmo303()

	/**
	 * The same case object translates to Jw303 when domain is jw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-the-same-case-object-translates-to-jw303-when-domain-is-jw
	 */
	public function testToewijzingTranslatesToJw303ForJwDomain(): void {
		$result = $this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'jw');

		$this->assertSame('Jw303', $result['berichttype']);
		$this->assertStringStartsWith('JW-', $result['ref']);

	}//end testToewijzingTranslatesToJw303ForJwDomain()

	/**
	 * A complete declaratie translates to a valid Wmo321/Jw321 envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-complete-declaratie-translates-to-a-valid-wmo321jw321-envelope
	 */
	public function testDeclaratieTranslatesToWmo321(): void {
		$result = $this->translator->translate($this->declaratie, OutboundBerichtTranslator::KIND_DECLARATIE, 'wmo');

		$this->assertSame('Wmo321', $result['berichttype']);
		$this->assertStringContainsString('<toewijzingReferentie>WMO-abc123</toewijzingReferentie>', $result['xml']);
		$this->assertStringContainsString('<factuurnummer>F-2026-001</factuurnummer>', $result['xml']);
		$this->assertStringContainsString('<bedrag>480.00</bedrag>', $result['xml']);
		$this->assertStringContainsString('<periodeStart>2026-08-01</periodeStart>', $result['xml']);
		$this->assertStringContainsString('<periodeEind>2026-08-31</periodeEind>', $result['xml']);

	}//end testDeclaratieTranslatesToWmo321()

	/**
	 * The declaratie kind produces Jw321 for domain jw.
	 *
	 * @return void
	 */
	public function testDeclaratieTranslatesToJw321ForJwDomain(): void {
		$result = $this->translator->translate($this->declaratie, OutboundBerichtTranslator::KIND_DECLARATIE, 'jw');

		$this->assertSame('Jw321', $result['berichttype']);

	}//end testDeclaratieTranslatesToJw321ForJwDomain()

	/**
	 * A missing required field raises IwmoIjwTranslationException naming the field —
	 * never reaches the XML (literal-leak guard).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-xml--literal-leak-guard
	 */
	public function testMissingRequiredFieldRaisesTranslationException(): void {
		$incomplete = $this->toewijzing;
		unset($incomplete['productcode']);

		$this->expectException(IwmoIjwTranslationException::class);
		$this->expectExceptionMessageMatches('/productcode/');
		$this->translator->translate($incomplete, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');

	}//end testMissingRequiredFieldRaisesTranslationException()

	/**
	 * An empty-string required field is treated identically to a missing one.
	 *
	 * @return void
	 */
	public function testEmptyStringRequiredFieldRaisesTranslationException(): void {
		$incomplete = $this->toewijzing;
		$incomplete['leveringsvorm'] = '   ';

		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($incomplete, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');

	}//end testEmptyStringRequiredFieldRaisesTranslationException()

	/**
	 * An unsupported kind is rejected before any field validation.
	 *
	 * @return void
	 */
	public function testUnsupportedKindRaisesTranslationException(): void {
		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($this->toewijzing, 'unknown-kind', 'wmo');

	}//end testUnsupportedKindRaisesTranslationException()

	/**
	 * An unsupported domain is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedDomainRaisesTranslationException(): void {
		$this->expectException(IwmoIjwTranslationException::class);
		$this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'unknown-domain');

	}//end testUnsupportedDomainRaisesTranslationException()

	/**
	 * An optional field (einddatum) is omitted from the envelope when absent, never
	 * emitted as an empty tag.
	 *
	 * @return void
	 */
	public function testOptionalFieldOmittedWhenAbsent(): void {
		$result = $this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');
		$this->assertStringNotContainsString('<einddatum', $result['xml']);

	}//end testOptionalFieldOmittedWhenAbsent()

	/**
	 * An optional field (einddatum), when present, is included verbatim.
	 *
	 * @return void
	 */
	public function testOptionalFieldIncludedWhenPresent(): void {
		$withEinddatum = $this->toewijzing;
		$withEinddatum['einddatum'] = '2027-08-01';

		$result = $this->translator->translate($withEinddatum, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');
		$this->assertStringContainsString('<einddatum>2027-08-01</einddatum>', $result['xml']);

	}//end testOptionalFieldIncludedWhenPresent()

	/**
	 * The generated referentienummer is echoed into stuurgegevens.referentienummer.
	 *
	 * @return void
	 */
	public function testReferentienummerIsEmbeddedInEnvelope(): void {
		$result = $this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');
		$this->assertStringContainsString(
			'<referentienummer>' . $result['ref'] . '</referentienummer>',
			$result['xml']
		);

	}//end testReferentienummerIsEmbeddedInEnvelope()

	/**
	 * gemeentecode/aanbiederAgbCode populate the zender/ontvanger stuurgegevens.
	 *
	 * @return void
	 */
	public function testZenderAndOntvangerAreEmbedded(): void {
		$result = $this->translator->translate($this->toewijzing, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');
		$this->assertStringContainsString('<zender><code>GM0344</code></zender>', $result['xml']);
		$this->assertStringContainsString('<ontvanger><code>01234567</code></ontvanger>', $result['xml']);

	}//end testZenderAndOntvangerAreEmbedded()

	/**
	 * Field values are XML-escaped — a value carrying a reserved character never
	 * breaks the envelope structure.
	 *
	 * @return void
	 */
	public function testFieldValuesAreXmlEscaped(): void {
		$withAmpersand = $this->toewijzing;
		$withAmpersand['leveringsvorm'] = 'ZIN & PGB';

		$result = $this->translator->translate($withAmpersand, OutboundBerichtTranslator::KIND_TOEWIJZING, 'wmo');
		$this->assertStringContainsString('ZIN &amp; PGB', $result['xml']);
		$this->assertStringNotContainsString('ZIN & PGB<', $result['xml']);

	}//end testFieldValuesAreXmlEscaped()
}//end class
