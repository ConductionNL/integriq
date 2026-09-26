<?php

/**
 * Unit tests for UwlrEduVAcknowledgementTranslator.
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
use OCA\Integriq\Service\UwlrEduV\UwlrEduVAcknowledgementTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared UWLR/Edu-V/Basispoort/Entree-content acknowledgement translator.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */
class UwlrEduVAcknowledgementTranslatorTest extends TestCase {

	/**
	 * @var UwlrEduVAcknowledgementTranslator
	 */
	private UwlrEduVAcknowledgementTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new UwlrEduVAcknowledgementTranslator();

	}//end setUp()

	/**
	 * Load a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw fixture contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/uwlr-eduv/' . $name);
	}//end fixture()

	/**
	 * An accepted acknowledgement translates with accepted true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-an-accepted-acknowledgement-dispatches-the-event-as-accepted
	 */
	public function testAcceptedAcknowledgementTranslatesAcceptedTrue(): void {
		$update = $this->translator->translate($this->fixture('retour-accepted.xml'));

		$this->assertSame('seed-uwlr-eduv-kenmerk-001', $update['kenmerk']);
		$this->assertSame('0', $update['signaalcode']);
		$this->assertTrue($update['accepted']);

	}//end testAcceptedAcknowledgementTranslatesAcceptedTrue()

	/**
	 * A retour with no kenmerk is rejected before any status update is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-retour-with-no-kenmerk-is-rejected-before-any-dispatch
	 */
	public function testMissingKenmerkRaisesBeforeAnyUpdate(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('missing stuurgegevens.kenmerk');

		$this->translator->translate($this->fixture('retour-no-kenmerk.xml'));

	}//end testMissingKenmerkRaisesBeforeAnyUpdate()
}//end class
