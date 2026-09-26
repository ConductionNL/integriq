<?php

/**
 * Unit tests for RodAcknowledgementTranslator.
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
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ROD acknowledgement translator, contract-tested against
 * recorded fixtures under tests/fixtures/rod/.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
class RodAcknowledgementTranslatorTest extends TestCase {

	/**
	 * @var RodAcknowledgementTranslator
	 */
	private RodAcknowledgementTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new RodAcknowledgementTranslator();

	}//end setUp()

	/**
	 * Load a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw fixture contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/rod/' . $name);
	}//end fixture()

	/**
	 * An accepted acknowledgement (signaalcode 0) translates with accepted true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-an-accepted-acknowledgement-dispatches-an-event-with-accepted-true
	 */
	public function testAcceptedAcknowledgementTranslatesAcceptedTrue(): void {
		$update = $this->translator->translate($this->fixture('retour-accepted.xml'));

		$this->assertSame('seed-kenmerk-002', $update['kenmerk']);
		$this->assertSame('0', $update['signaalcode']);
		$this->assertSame('Verwerkt', $update['signaalOmschrijving']);
		$this->assertTrue($update['accepted']);

	}//end testAcceptedAcknowledgementTranslatesAcceptedTrue()

	/**
	 * A rejection signaalcode translates with accepted false, preserving the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-rejection-signaalcode-dispatches-an-event-with-accepted-false-and-the-reason
	 */
	public function testRejectionSignaalcodeTranslatesAcceptedFalse(): void {
		$update = $this->translator->translate($this->fixture('retour-rejected.xml'));

		$this->assertSame('seed-kenmerk-003', $update['kenmerk']);
		$this->assertSame('7', $update['signaalcode']);
		$this->assertSame('Leerling niet bekend bij DUO', $update['signaalOmschrijving']);
		$this->assertFalse($update['accepted']);

	}//end testRejectionSignaalcodeTranslatesAcceptedFalse()

	/**
	 * A retour with no kenmerk is rejected before any status update is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-retour-with-no-kenmerk-is-rejected-before-any-event
	 */
	public function testMissingKenmerkRaisesBeforeAnyUpdate(): void {
		$this->expectException(RodTranslationException::class);
		$this->expectExceptionMessage('missing stuurgegevens.kenmerk');

		$this->translator->translate($this->fixture('retour-no-kenmerk.xml'));

	}//end testMissingKenmerkRaisesBeforeAnyUpdate()

	/**
	 * An empty string raises before any XML parsing is attempted.
	 *
	 * @return void
	 */
	public function testEmptyXmlRaises(): void {
		$this->expectException(RodTranslationException::class);
		$this->expectExceptionMessage('Retour envelope is empty');

		$this->translator->translate('');

	}//end testEmptyXmlRaises()

	/**
	 * Malformed XML raises rather than partially parsing.
	 *
	 * @return void
	 */
	public function testMalformedXmlRaises(): void {
		$this->expectException(RodTranslationException::class);
		$this->expectExceptionMessage('not well-formed XML');

		$this->translator->translate('<RodRetour><stuurgegevens>');

	}//end testMalformedXmlRaises()
}//end class
