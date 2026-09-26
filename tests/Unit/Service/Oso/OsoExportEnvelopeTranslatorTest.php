<?php

/**
 * Unit tests for OsoExportEnvelopeTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Oso;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OSO export envelope translator.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */
class OsoExportEnvelopeTranslatorTest extends TestCase {

	/**
	 * @var OsoExportEnvelopeTranslator
	 */
	private OsoExportEnvelopeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OsoExportEnvelopeTranslator();

	}//end setUp()

	/**
	 * A complete export payload translates to a valid envelope carrying every field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-complete-export-payload-translates-to-a-valid-envelope
	 */
	public function testCompletePayloadTranslatesToValidEnvelope(): void {
		$xml = $this->translator->translate(
			'seed-oso-kenmerk-001',
			[
				'learnerEckId' => 'eck-id-seed-001',
				'targetSchoolBrin' => '34CD',
				'categories' => [['category' => 'basisgegevens', 'included' => true, 'data' => []]],
			]
		);

		$this->assertStringContainsString('<kenmerk>seed-oso-kenmerk-001</kenmerk>', $xml);
		$this->assertStringContainsString('<learnerEckId>eck-id-seed-001</learnerEckId>', $xml);
		$this->assertStringContainsString('<targetSchoolBrin>34CD</targetSchoolBrin>', $xml);
		$this->assertStringContainsString('<name>basisgegevens</name>', $xml);
		$this->assertStringContainsString('<included>true</included>', $xml);

	}//end testCompletePayloadTranslatesToValidEnvelope()

	/**
	 * A missing required field never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	public function testMissingRequiredFieldNeverReachesEnvelope(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('Required field "targetSchoolBrin" is missing or empty');

		$this->translator->translate(
			'k1',
			['learnerEckId' => 'eck-id-seed-001', 'categories' => [['category' => 'basisgegevens', 'included' => true]]]
		);

	}//end testMissingRequiredFieldNeverReachesEnvelope()

	/**
	 * An empty categories array is rejected.
	 *
	 * @return void
	 */
	public function testEmptyCategoriesRejected(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('Required field "categories" is missing or empty');

		$this->translator->translate(
			'k1',
			['learnerEckId' => 'eck-id-seed-001', 'targetSchoolBrin' => '34CD', 'categories' => []]
		);

	}//end testEmptyCategoriesRejected()

	/**
	 * An excluded category is transmitted as excluded, not omitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-an-excluded-category-is-transmitted-as-excluded-not-omitted
	 */
	public function testExcludedCategoryTransmittedNotOmitted(): void {
		$xml = $this->translator->translate(
			'k1',
			[
				'learnerEckId' => 'eck-id-seed-001',
				'targetSchoolBrin' => '34CD',
				'categories' => [['category' => 'onderwijskundig-rapport', 'included' => false, 'data' => []]],
			]
		);

		$this->assertStringContainsString('<name>onderwijskundig-rapport</name>', $xml);
		$this->assertStringContainsString('<included>false</included>', $xml);

	}//end testExcludedCategoryTransmittedNotOmitted()

	/**
	 * An empty kenmerk is rejected before any envelope is built.
	 *
	 * @return void
	 */
	public function testEmptyKenmerkRaises(): void {
		$this->expectException(OsoTranslationException::class);

		$this->translator->translate(
			'',
			['learnerEckId' => 'eck-id-seed-001', 'targetSchoolBrin' => '34CD', 'categories' => [['category' => 'basisgegevens', 'included' => true]]]
		);

	}//end testEmptyKenmerkRaises()

	/**
	 * Optional attachmentRefs are appended only when present.
	 *
	 * @return void
	 */
	public function testAttachmentRefsAppendedOnlyWhenPresent(): void {
		$without = $this->translator->translate(
			'k1',
			['learnerEckId' => 'eck-id-seed-001', 'targetSchoolBrin' => '34CD', 'categories' => [['category' => 'basisgegevens', 'included' => true]]]
		);
		$this->assertStringNotContainsString('<attachmentRefs>', $without);

		$with = $this->translator->translate(
			'k1',
			[
				'learnerEckId' => 'eck-id-seed-001',
				'targetSchoolBrin' => '34CD',
				'categories' => [['category' => 'basisgegevens', 'included' => true]],
				'attachmentRefs' => ['nc:files/oso/rapport.pdf'],
			]
		);
		$this->assertStringContainsString('nc:files/oso/rapport.pdf', $with);

	}//end testAttachmentRefsAppendedOnlyWhenPresent()
}//end class
