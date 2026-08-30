<?php

/**
 * Unit tests for RolTranslator.
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
use OCA\Integriq\Service\ZgwVersion\RolTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `rol` resource translator — the shim's one DOCUMENTED
 * LOSSY translator (procest's own mapping never emits `betrokkeneType`,
 * see design.md "Rol: documented lossy translation").
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#scenario-rols-betrokkenetype-gap-is-a-documented-lossy-best-effort-translation
 */
class RolTranslatorTest extends TestCase {

	/**
	 * @var RolTranslator
	 */
	private RolTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new RolTranslator();
	}//end setUp()

	/**
	 * A conformant `1.0` rol fixture (no `betrokkeneType` — the fleet's own gap).
	 *
	 * @return array<string, mixed>
	 */
	private function conformantPayload(): array {
		return [
			'url' => 'https://host/api/zgw/zaken/v1/rollen/rol',
			'uuid' => 'rol',
			'zaak' => 'https://host/api/zgw/zaken/v1/zaken/abc',
			'roltype' => 'https://host/api/zgw/catalogi/v1/roltypen/rt',
			'betrokkeneIdentificatie' => 'BSN-123456782',
		];
	}//end conformantPayload()

	/**
	 * @return void
	 */
	public function testGetResource(): void {
		$this->assertSame(expected: 'rol', actual: $this->translator->getResource());
	}//end testGetResource()

	/**
	 * @return void
	 */
	public function testTranslateToV16AddsDefaultBetrokkeneType(): void {
		$payload = $this->conformantPayload();
		$translated = $this->translator->translateToV16(payload: $payload);

		$this->assertArrayHasKey(key: 'betrokkeneType', array: $translated);
		$this->assertSame(expected: 'natuurlijk_persoon', actual: $translated['betrokkeneType']);
	}//end testTranslateToV16AddsDefaultBetrokkeneType()

	/**
	 * @return void
	 */
	public function testTranslateToV16PreservesAnExplicitBetrokkeneType(): void {
		$payload = $this->conformantPayload();
		$payload['betrokkeneType'] = 'niet_natuurlijk_persoon';

		$translated = $this->translator->translateToV16(payload: $payload);

		$this->assertSame(expected: 'niet_natuurlijk_persoon', actual: $translated['betrokkeneType']);
	}//end testTranslateToV16PreservesAnExplicitBetrokkeneType()

	/**
	 * @return void
	 */
	public function testTranslateToV1xStripsBetrokkeneType(): void {
		$payload = $this->conformantPayload();
		$payload['betrokkeneType'] = 'natuurlijk_persoon';

		$translated = $this->translator->translateToV1x(payload: $payload);

		$this->assertArrayNotHasKey(key: 'betrokkeneType', array: $translated);
	}//end testTranslateToV1xStripsBetrokkeneType()

	/**
	 * @return void
	 */
	public function testMissingRequiredFieldThrowsLiteralLeak(): void {
		$payload = $this->conformantPayload();
		unset($payload['betrokkeneIdentificatie']);

		$this->expectException(ZgwLiteralLeakException::class);
		$this->translator->translateToV16(payload: $payload);
	}//end testMissingRequiredFieldThrowsLiteralLeak()

	/**
	 * The `1.0` -> `1.6` -> `1.0` round trip IS lossless with respect to
	 * what procest itself reads (it never reads `betrokkeneType`) — this
	 * documents that explicitly, distinct from the LOSSY `1.0` -> `1.6`
	 * direction (which invents a default the source data never carried).
	 *
	 * @return void
	 */
	public function testRoundTripIsLosslessWithRespectToFleetShape(): void {
		$payload = $this->conformantPayload();
		$roundTripped = $this->translator->translateToV1x(
			payload: $this->translator->translateToV16(payload: $payload)
		);

		$this->assertSame(expected: $payload, actual: $roundTripped);
	}//end testRoundTripIsLosslessWithRespectToFleetShape()
}//end class
