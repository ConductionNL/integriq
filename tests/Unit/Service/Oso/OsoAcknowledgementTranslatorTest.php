<?php

/**
 * Unit tests for OsoAcknowledgementTranslator.
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
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OSO export acknowledgement translator.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */
class OsoAcknowledgementTranslatorTest extends TestCase {

	/**
	 * @var OsoAcknowledgementTranslator
	 */
	private OsoAcknowledgementTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OsoAcknowledgementTranslator();

	}//end setUp()

	/**
	 * Load a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw fixture contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/oso/' . $name);
	}//end fixture()

	/**
	 * An accepted acknowledgement translates with accepted true.
	 *
	 * @return void
	 */
	public function testAcceptedAcknowledgementTranslatesAcceptedTrue(): void {
		$update = $this->translator->translate($this->fixture('retour-accepted.xml'));

		$this->assertSame('seed-oso-kenmerk-001', $update['kenmerk']);
		$this->assertSame('0', $update['signaalcode']);
		$this->assertTrue($update['accepted']);

	}//end testAcceptedAcknowledgementTranslatesAcceptedTrue()

	/**
	 * A retour with no kenmerk is rejected before any status update is returned.
	 *
	 * @return void
	 */
	public function testMissingKenmerkRaisesBeforeAnyUpdate(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('missing stuurgegevens.kenmerk');

		$this->translator->translate($this->fixture('retour-no-kenmerk.xml'));

	}//end testMissingKenmerkRaisesBeforeAnyUpdate()
}//end class
