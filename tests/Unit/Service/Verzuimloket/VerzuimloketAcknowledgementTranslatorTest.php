<?php

/**
 * Unit tests for VerzuimloketAcknowledgementTranslator.
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
use OCA\Integriq\Service\Verzuimloket\VerzuimloketAcknowledgementTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Verzuimloket acknowledgement translator.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-003-duo-acknowledgement-translation-to-a-typed-event
 */
class VerzuimloketAcknowledgementTranslatorTest extends TestCase {

	/**
	 * @var VerzuimloketAcknowledgementTranslator
	 */
	private VerzuimloketAcknowledgementTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new VerzuimloketAcknowledgementTranslator();

	}//end setUp()

	/**
	 * Load a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw fixture contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/verzuimloket/' . $name);
	}//end fixture()

	/**
	 * An accepted acknowledgement (signaalcode 0) translates with accepted true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-an-accepted-acknowledgement-dispatches-an-event-with-accepted-true
	 */
	public function testAcceptedAcknowledgementTranslatesAcceptedTrue(): void {
		$update = $this->translator->translate($this->fixture('retour-accepted.xml'));

		$this->assertSame('seed-verzuim-kenmerk-001', $update['kenmerk']);
		$this->assertSame('0', $update['signaalcode']);
		$this->assertSame('Verwerkt', $update['signaalOmschrijving']);
		$this->assertTrue($update['accepted']);

	}//end testAcceptedAcknowledgementTranslatesAcceptedTrue()

	/**
	 * A rejection signaalcode translates with accepted false, preserving the reason.
	 *
	 * @return void
	 */
	public function testRejectionSignaalcodeTranslatesAcceptedFalse(): void {
		$update = $this->translator->translate($this->fixture('retour-rejected.xml'));

		$this->assertSame('seed-verzuim-kenmerk-002', $update['kenmerk']);
		$this->assertSame('7', $update['signaalcode']);
		$this->assertFalse($update['accepted']);

	}//end testRejectionSignaalcodeTranslatesAcceptedFalse()

	/**
	 * A retour with no kenmerk is rejected before any status update is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-a-retour-with-no-kenmerk-is-rejected-before-any-event
	 */
	public function testMissingKenmerkRaisesBeforeAnyUpdate(): void {
		$this->expectException(VerzuimloketTranslationException::class);
		$this->expectExceptionMessage('missing stuurgegevens.kenmerk');

		$this->translator->translate($this->fixture('retour-no-kenmerk.xml'));

	}//end testMissingKenmerkRaisesBeforeAnyUpdate()

	/**
	 * An empty string raises before any XML parsing is attempted.
	 *
	 * @return void
	 */
	public function testEmptyXmlRaises(): void {
		$this->expectException(VerzuimloketTranslationException::class);
		$this->expectExceptionMessage('Retour envelope is empty');

		$this->translator->translate('');

	}//end testEmptyXmlRaises()
}//end class
