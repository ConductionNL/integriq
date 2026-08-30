<?php

/**
 * Unit tests for the shared StufLiteralLeakGuard.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Stuf;

use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared literal-leak scan used by iwmo-ijw-adapter and stuf-zkn-bridge.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-shared-literal-leak-guard-req-000
 */
class StufLiteralLeakGuardTest extends TestCase {

	/**
	 * @var StufLiteralLeakGuard
	 */
	private StufLiteralLeakGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new StufLiteralLeakGuard();

	}//end setUp()

	/**
	 * A clean envelope has no unresolved placeholder.
	 *
	 * @return void
	 */
	public function testCleanEnvelopeHasNoPlaceholder(): void {
		$this->assertFalse($this->guard->hasUnresolvedPlaceholder(xml: '<root><identificatie>ZAAK-1</identificatie></root>'));

	}//end testCleanEnvelopeHasNoPlaceholder()

	/**
	 * A `{{marker}}`-style leftover template is detected.
	 *
	 * @return void
	 */
	public function testDetectsCurlyBraceMarker(): void {
		$this->assertTrue($this->guard->hasUnresolvedPlaceholder(xml: '<root>{{identificatie}}</root>'));

	}//end testDetectsCurlyBraceMarker()

	/**
	 * A literal `%%UNRESOLVED%%` marker is detected.
	 *
	 * @return void
	 */
	public function testDetectsUnresolvedLiteral(): void {
		$this->assertTrue($this->guard->hasUnresolvedPlaceholder(xml: '<root>%%UNRESOLVED%%</root>'));

	}//end testDetectsUnresolvedLiteral()
}//end class
