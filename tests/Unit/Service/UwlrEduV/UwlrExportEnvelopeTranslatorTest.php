<?php

/**
 * Unit tests for UwlrExportEnvelopeTranslator.
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
use OCA\Integriq\Service\UwlrEduV\UwlrExportEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the UWLR export envelope translator.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */
class UwlrExportEnvelopeTranslatorTest extends TestCase {

	/**
	 * @var UwlrExportEnvelopeTranslator
	 */
	private UwlrExportEnvelopeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new UwlrExportEnvelopeTranslator();

	}//end setUp()

	/**
	 * Each of the three subtypes translates a complete payload and carries eckId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-pupil-export-payload-translates-to-a-valid-envelope
	 */
	public function testEachSubtypeCarriesEckId(): void {
		foreach (UwlrExportEnvelopeTranslator::SUBTYPES as $subtype) {
			$xml = $this->translator->translate('k1', $subtype, ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);
			$this->assertStringContainsString('<eckId>eck-001</eckId>', $xml);
			$this->assertStringContainsString('<subtype>' . $subtype . '</subtype>', $xml);
		}

	}//end testEachSubtypeCarriesEckId()

	/**
	 * An unknown subtype is rejected before any envelope is built.
	 *
	 * @return void
	 */
	public function testUnknownSubtypeIsRejected(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('Unknown UWLR subtype');

		$this->translator->translate('k1', 'principal', ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);

	}//end testUnknownSubtypeIsRejected()

	/**
	 * A missing eckId never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-missing-eckid-never-reaches-the-envelope
	 */
	public function testMissingEckIdNeverReachesTheEnvelope(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('Required field "eckId" is missing or empty');

		$this->translator->translate('k1', 'pupil', ['schoolBrin' => '12AB']);

	}//end testMissingEckIdNeverReachesTheEnvelope()

	/**
	 * An empty kenmerk is rejected before any envelope is built.
	 *
	 * @return void
	 */
	public function testEmptyKenmerkIsRejected(): void {
		$this->expectException(UwlrEduVTranslationException::class);

		$this->translator->translate('', 'pupil', ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);

	}//end testEmptyKenmerkIsRejected()
}//end class
