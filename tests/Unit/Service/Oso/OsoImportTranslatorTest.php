<?php

/**
 * Unit tests for OsoImportTranslator.
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
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OSO import translator, contract-tested against recorded fixtures.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
 */
class OsoImportTranslatorTest extends TestCase {

	/**
	 * @var OsoImportTranslator
	 */
	private OsoImportTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new OsoImportTranslator();

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
	 * A complete inbound dossier parses into the OsoImportDossier field shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-complete-inbound-dossier-dispatches-osodossierreceivedevent
	 */
	public function testCompleteDossierParsesToImportDossierShape(): void {
		$parsed = $this->translator->translate($this->fixture('import-complete.xml'));

		$this->assertSame('12AB', $parsed['sourceSchoolBrin']);
		$this->assertSame('eck-id-seed-001', $parsed['learnerEckId']);
		$this->assertCount(2, $parsed['categories']);
		$this->assertSame('basisgegevens', $parsed['categories'][0]['category']);
		$this->assertTrue($parsed['categories'][0]['included']);
		$this->assertFalse($parsed['categories'][1]['included']);
		$this->assertSame(['nc:files/oso/onderwijskundig-rapport.pdf'], $parsed['attachmentRefs']);
		$this->assertNotNull($parsed['draftProfile']);
		$this->assertSame('Fatima', $parsed['draftProfile']['givenName']);
		$this->assertSame('El Amrani', $parsed['draftProfile']['familyName']);
		$this->assertSame('2015-04-12', $parsed['draftProfile']['birthDate']);
		$this->assertSame('eck-id-seed-001', $parsed['draftProfile']['eckId']);
		$this->assertSame('12AB', $parsed['draftProfile']['schoolId']);

	}//end testCompleteDossierParsesToImportDossierShape()

	/**
	 * A dossier with no sending school BRIN is rejected before returning.
	 *
	 * @return void
	 */
	public function testMissingBrinRaises(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('missing stuurgegevens.afzenderBrin');

		$this->translator->translate($this->fixture('import-no-brin.xml'));

	}//end testMissingBrinRaises()

	/**
	 * An empty string raises before any XML parsing is attempted.
	 *
	 * @return void
	 */
	public function testEmptyXmlRaises(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('Inbound OSO dossier is empty');

		$this->translator->translate('');

	}//end testEmptyXmlRaises()

	/**
	 * Malformed XML raises rather than partially parsing.
	 *
	 * @return void
	 */
	public function testMalformedXmlRaises(): void {
		$this->expectException(OsoTranslationException::class);
		$this->expectExceptionMessage('not well-formed XML');

		$this->translator->translate('<OsoOverstapdossier><stuurgegevens>');

	}//end testMalformedXmlRaises()
}//end class
