<?php

/**
 * Unit tests for CaseTypeTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\ZgwVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\ZgwVersion;

use OCA\OpenConnector\Exception\ZgwLiteralLeakException;
use OCA\OpenConnector\Service\ZgwVersion\CaseTypeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `zaaktype` resource translator.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class ZaakTypeTranslatorTest extends TestCase {

	/**
	 * @var CaseTypeTranslator
	 */
	private CaseTypeTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new CaseTypeTranslator();
	}//end setUp()

	/**
	 * A conformant `1.0` zaaktype fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function conformantPayload(): array {
		return [
			'url' => 'https://host/api/zgw/catalogi/v1/zaaktypen/def',
			'uuid' => 'def',
			'identificatie' => 'ZT-001',
			'omschrijving' => 'Test zaaktype',
			'catalogus' => 'https://host/api/zgw/catalogi/v1/catalogussen/cat',
			'vertrouwelijkheidaanduiding' => 'openbaar',
			'besluittypen' => ['https://host/api/zgw/catalogi/v1/besluittypen/bt1'],
			'informatieobjecttypen' => [],
			'gerelateerdeZaaktypen' => [],
		];
	}//end conformantPayload()

	/**
	 * @return void
	 */
	public function testGetResource(): void {
		$this->assertSame(expected: 'zaaktype', actual: $this->translator->getResource());
	}//end testGetResource()

	/**
	 * @return void
	 */
	public function testTranslateToV16IsStructurallyIdentical(): void {
		$payload = $this->conformantPayload();
		$this->assertSame(expected: $payload, actual: $this->translator->translateToV16(payload: $payload));
	}//end testTranslateToV16IsStructurallyIdentical()

	/**
	 * @return void
	 */
	public function testTranslateToV1xIsStructurallyIdentical(): void {
		$payload = $this->conformantPayload();
		$this->assertSame(expected: $payload, actual: $this->translator->translateToV1x(payload: $payload));
	}//end testTranslateToV1xIsStructurallyIdentical()

	/**
	 * The exact malformed shape procest's own `LoadDefaultZgwMappings`
	 * `'besluittypen' => 'decisionTypes'` quirk could produce if that raw
	 * literal ever leaked through unresolved instead of an array of URLs.
	 *
	 * @return void
	 */
	public function testNonArrayBesluittypenThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		$payload['besluittypen'] = 'decisionTypes';

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV16(payload: $payload);
	}//end testNonArrayBesluittypenThrowsLiteralLeak()

	/**
	 * @return void
	 */
	public function testNonArrayInformatieobjecttypenThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		$payload['informatieobjecttypen'] = 'documentTypes';

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV1x(payload: $payload);
	}//end testNonArrayInformatieobjecttypenThrowsLiteralLeak()

	/**
	 * @return void
	 */
	public function testOutOfSetEnumValueThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		$payload['vertrouwelijkheidaanduiding'] = 'top-secret';

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV16(payload: $payload);
	}//end testOutOfSetEnumValueThrowsLiteralLeak()

	/**
	 * @return void
	 */
	public function testMissingRequiredFieldThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		unset($payload['catalogus']);

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV16(payload: $payload);
	}//end testMissingRequiredFieldThrowsLiteralLeak()

	/**
	 * @return void
	 */
	public function testRoundTripIsLossless(): void {
		$payload = $this->conformantPayload();
		$roundTripped = $this->translator->translateToV1x(
			payload: $this->translator->translateToV16(payload: $payload)
		);

		$this->assertSame(expected: $payload, actual: $roundTripped);
	}//end testRoundTripIsLossless()
}//end class
