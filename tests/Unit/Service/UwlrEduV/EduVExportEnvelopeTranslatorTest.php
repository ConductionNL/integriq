<?php

/**
 * Unit tests for EduVExportEnvelopeTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\UwlrEduV\EduVExportEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Edu-V export envelope translator.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-003-edu-v-export-envelope-translation-across-three-qualified-data-services
 */
class EduVExportEnvelopeTranslatorTest extends TestCase {

	/**
	 * @var EduVExportEnvelopeTranslator
	 */
	private EduVExportEnvelopeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new EduVExportEnvelopeTranslator();

	}//end setUp()

	/**
	 * Each of the three qualified data services names its own targetSchema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-each-edu-v-subtype-names-its-own-targetschema
	 */
	public function testEachDataServiceNamesItsOwnTargetSchema(): void {
		$seen = [];
		foreach (EduVExportEnvelopeTranslator::DATA_SERVICE_SCHEMAS as $dataService => $expectedSchema) {
			$xml = $this->translator->translate('k1', $dataService, ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);
			$this->assertStringContainsString('<targetSchema>' . $expectedSchema . '</targetSchema>', $xml);
			$seen[] = $expectedSchema;
		}

		$this->assertSame(array_unique($seen), $seen, 'Each data service must produce a distinct targetSchema.');

	}//end testEachDataServiceNamesItsOwnTargetSchema()

	/**
	 * An unqualified data service is rejected before any envelope is built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-an-unknown-data-service-is-rejected-before-any-envelope-is-built
	 */
	public function testUnqualifiedDataServiceIsRejected(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('Unknown Edu-V data service');

		$this->translator->translate('k1', 'onderwijsresultaten', ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);

	}//end testUnqualifiedDataServiceIsRejected()

	/**
	 * A missing eckId never reaches the envelope.
	 *
	 * @return void
	 */
	public function testMissingEckIdNeverReachesTheEnvelope(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('Required field "eckId" is missing or empty');

		$this->translator->translate('k1', 'onderwijsdeelnemers', ['schoolBrin' => '12AB']);

	}//end testMissingEckIdNeverReachesTheEnvelope()
}//end class
