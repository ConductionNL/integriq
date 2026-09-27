<?php

/**
 * Unit tests for VerzuimloketEnvelopeTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Verzuimloket outbound envelope translator.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
class VerzuimloketEnvelopeTranslatorTest extends TestCase {

	/**
	 * @var VerzuimloketEnvelopeTranslator
	 */
	private VerzuimloketEnvelopeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new VerzuimloketEnvelopeTranslator();

	}//end setUp()

	/**
	 * A complete eerste-melding translates to a valid envelope carrying every field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-a-complete-eerste-melding-translates-to-a-valid-envelope
	 */
	public function testCompleteEersteMeldingTranslatesToValidEnvelope(): void {
		$xml = $this->translator->translate(
			'eerste-melding',
			'seed-verzuim-kenmerk-001',
			[
				'bsn' => '999999990',
				'windowStart' => '2026-09-01',
				'windowEnd' => '2026-09-28',
				'metricValue' => 16,
			]
		);

		$this->assertStringContainsString('<meldingType>eerste-melding</meldingType>', $xml);
		$this->assertStringContainsString('<kenmerk>seed-verzuim-kenmerk-001</kenmerk>', $xml);
		$this->assertStringContainsString('<bsn>999999990</bsn>', $xml);
		$this->assertStringContainsString('<windowStart>2026-09-01</windowStart>', $xml);
		$this->assertStringContainsString('<windowEnd>2026-09-28</windowEnd>', $xml);
		$this->assertStringContainsString('<metricValue>16</metricValue>', $xml);

	}//end testCompleteEersteMeldingTranslatesToValidEnvelope()

	/**
	 * A missing required field never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	public function testMissingRequiredFieldNeverReachesEnvelope(): void {
		$this->expectException(VerzuimloketTranslationException::class);
		$this->expectExceptionMessage('Required field "metricValue" is missing or empty');

		$this->translator->translate(
			'eerste-melding',
			'seed-verzuim-kenmerk-001',
			['bsn' => '999999990', 'windowStart' => '2026-09-01', 'windowEnd' => '2026-09-28']
		);

	}//end testMissingRequiredFieldNeverReachesEnvelope()

	/**
	 * langdurig-relatief-verzuim requires no windowEnd.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-langdurig-relatief-verzuim-requires-no-windowend
	 */
	public function testLrvDoesNotRequireWindowEnd(): void {
		$xml = $this->translator->translate(
			'langdurig-relatief-verzuim',
			'k1',
			['bsn' => '999999990', 'startDate' => '2026-08-01']
		);

		$this->assertStringContainsString('<startDate>2026-08-01</startDate>', $xml);
		$this->assertStringNotContainsString('<windowEnd>', $xml);

	}//end testLrvDoesNotRequireWindowEnd()

	/**
	 * An unsupported meldingType is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedMeldingTypeRaises(): void {
		$this->expectException(VerzuimloketTranslationException::class);
		$this->expectExceptionMessage('Unsupported Verzuimloket meldingType "onbekend"');

		$this->translator->translate('onbekend', 'k1', ['bsn' => '999999990']);

	}//end testUnsupportedMeldingTypeRaises()

	/**
	 * An empty kenmerk is rejected before any envelope is built.
	 *
	 * @return void
	 */
	public function testEmptyKenmerkRaises(): void {
		$this->expectException(VerzuimloketTranslationException::class);

		$this->translator->translate(
			'eerste-melding',
			'',
			['bsn' => '999999990', 'windowStart' => '2026-09-01', 'windowEnd' => '2026-09-28', 'metricValue' => 16]
		);

	}//end testEmptyKenmerkRaises()

	/**
	 * Optional breachingRecords/interventions are JSON-encoded and appended only when present.
	 *
	 * @return void
	 */
	public function testOptionalFieldsAppendedOnlyWhenPresent(): void {
		$without = $this->translator->translate(
			'eerste-melding',
			'k1',
			['bsn' => '999999990', 'windowStart' => '2026-09-01', 'windowEnd' => '2026-09-28', 'metricValue' => 16]
		);
		$this->assertStringNotContainsString('<breachingRecords>', $without);
		$this->assertStringNotContainsString('<interventions>', $without);

		$with = $this->translator->translate(
			'eerste-melding',
			'k1',
			[
				'bsn' => '999999990',
				'windowStart' => '2026-09-01',
				'windowEnd' => '2026-09-28',
				'metricValue' => 16,
				'breachingRecords' => [['date' => '2026-09-15', 'lesuren' => 4]],
				'interventions' => [['recordedBy' => 'u-mentor-1', 'recordedAt' => '2026-09-16T09:00:00+02:00', 'note' => 'Contact opgenomen']],
			]
		);
		$this->assertStringContainsString('<breachingRecords>', $with);
		$this->assertStringContainsString('<interventions>', $with);
		$this->assertStringContainsString('2026-09-15', $with);

	}//end testOptionalFieldsAppendedOnlyWhenPresent()

	/**
	 * herhaalmelding shares eerste-melding's required-field shape.
	 *
	 * @return void
	 */
	public function testHerhaalmeldingTranslates(): void {
		$xml = $this->translator->translate(
			'herhaalmelding',
			'k1',
			['bsn' => '999999990', 'windowStart' => '2026-10-01', 'windowEnd' => '2026-10-28', 'metricValue' => 18]
		);
		$this->assertStringContainsString('<meldingType>herhaalmelding</meldingType>', $xml);

	}//end testHerhaalmeldingTranslates()
}//end class
