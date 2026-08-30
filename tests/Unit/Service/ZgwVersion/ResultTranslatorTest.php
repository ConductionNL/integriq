<?php

/**
 * Unit tests for ResultTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ZgwVersion
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

namespace OCA\Integriq\Tests\Unit\Service\ZgwVersion;

use OCA\Integriq\Exception\ZgwLiteralLeakException;
use OCA\Integriq\Service\ZgwVersion\ResultTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `resultaat` resource translator — the shim's one VERIFIED
 * field-level `1.6` delta (VNG `zaken-api` CHANGELOG 1.5.1, `resultaattoelichting`
 * removed as a duplicate of `toelichting`, issue #2157).
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#scenario-resultaat-translates-the-verified-resultaattoelichting-delta-both-directions
 */
class ResultTranslatorTest extends TestCase {

	/**
	 * @var ResultTranslator
	 */
	private ResultTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new ResultTranslator();
	}//end setUp()

	/**
	 * A conformant `1.0` resultaat fixture (procest never emits `resultaattoelichting`).
	 *
	 * @return array<string, mixed>
	 */
	private function conformantPayload(): array {
		return [
			'url' => 'https://host/api/zgw/zaken/v1/resultaten/res',
			'uuid' => 'res',
			'zaak' => 'https://host/api/zgw/zaken/v1/zaken/abc',
			'resultaattype' => 'https://host/api/zgw/catalogi/v1/resultaattypen/rt',
			'toelichting' => 'Afgehandeld',
		];
	}//end conformantPayload()

	/**
	 * @return void
	 */
	public function testGetResource(): void {
		$this->assertSame(expected: 'resultaat', actual: $this->translator->getResource());
	}//end testGetResource()

	/**
	 * @return void
	 */
	public function testTranslateToV16DropsLegacyDuplicateAsNoOp(): void {
		$payload = $this->conformantPayload();
		$translated = $this->translator->translateToV16(payload: $payload);

		$this->assertArrayNotHasKey(key: 'resultaattoelichting', array: $translated);
		$this->assertSame(expected: 'Afgehandeld', actual: $translated['toelichting']);
	}//end testTranslateToV16DropsLegacyDuplicateAsNoOp()

	/**
	 * @return void
	 */
	public function testTranslateToV16DropsAnAlreadyPresentLegacyDuplicate(): void {
		$payload = $this->conformantPayload();
		$payload['resultaattoelichting'] = 'Afgehandeld';

		$translated = $this->translator->translateToV16(payload: $payload);

		$this->assertArrayNotHasKey(key: 'resultaattoelichting', array: $translated);
	}//end testTranslateToV16DropsAnAlreadyPresentLegacyDuplicate()

	/**
	 * @return void
	 */
	public function testTranslateToV1xAddsLegacyDuplicateMirroringToelichting(): void {
		$payload = $this->conformantPayload();
		$translated = $this->translator->translateToV1x(payload: $payload);

		$this->assertArrayHasKey(key: 'resultaattoelichting', array: $translated);
		$this->assertSame(expected: 'Afgehandeld', actual: $translated['resultaattoelichting']);
		$this->assertSame(expected: $translated['toelichting'], actual: $translated['resultaattoelichting']);
	}//end testTranslateToV1xAddsLegacyDuplicateMirroringToelichting()

	/**
	 * @return void
	 */
	public function testMissingRequiredFieldThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		unset($payload['resultaattype']);

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV16(payload: $payload);
	}//end testMissingRequiredFieldThrowsLiteralLeak()

	/**
	 * The `1.0` -> `1.6` -> `1.0` round trip is lossy for `resultaattoelichting`
	 * by design (the legacy duplicate is regenerated, not preserved verbatim)
	 * — this documents that explicitly rather than asserting a false lossless
	 * guarantee.
	 *
	 * @return void
	 */
	public function testRoundTripPreservesCanonicalFieldButRegeneratesLegacyDuplicate(): void {
		$payload = $this->conformantPayload();
		$roundTripped = $this->translator->translateToV1x(
			payload: $this->translator->translateToV16(payload: $payload)
		);

		$this->assertSame(expected: $payload['toelichting'], actual: $roundTripped['toelichting']);
		$this->assertSame(expected: $payload['toelichting'], actual: $roundTripped['resultaattoelichting']);
	}//end testRoundTripPreservesCanonicalFieldButRegeneratesLegacyDuplicate()
}//end class
