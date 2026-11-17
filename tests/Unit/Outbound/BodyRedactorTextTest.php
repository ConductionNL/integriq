<?php

/**
 * Unit tests for BodyRedactor::redactText(), the redaction run on text that
 * leaves Nextcloud for an error service (REQ-OTEL-006).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\BodyRedactor;
use PHPUnit\Framework\TestCase;

/**
 * BSN-shaped numbers and credentials are replaced; other numbers stay.
 */
class BodyRedactorTextTest extends TestCase {

	/**
	 * A BSN, written plain or split by separators, is replaced.
	 *
	 * @return void
	 */
	public function testABsnInAnyWritingIsReplaced(): void {
		$redactor = new BodyRedactor();

		$this->assertSame(expected: 'BSN [redacted] not found', actual: $redactor->redactText(text: 'BSN 999993653 not found'));
		$this->assertSame(expected: 'bsn=[redacted];', actual: $redactor->redactText(text: 'bsn=999.993.653;'));
		$this->assertSame(expected: '[redacted] and [redacted]', actual: $redactor->redactText(text: '9999-936-53 and 999_99_36_53'));

	}//end testABsnInAnyWritingIsReplaced()

	/**
	 * Credentials go as they do in a body; timestamps and short numbers stay.
	 *
	 * @return void
	 */
	public function testCredentialsGoAndOtherNumbersStay(): void {
		$redactor = new BodyRedactor();

		$this->assertSame(expected: 'Authorization: Bearer [redacted]', actual: $redactor->redactText(text: 'Authorization: Bearer abcdefgh12345678'));
		$this->assertSame(expected: 'at 1760000000 on line 412', actual: $redactor->redactText(text: 'at 1760000000 on line 412'));
		$this->assertSame(expected: 'order 12345678', actual: $redactor->redactText(text: 'order 12345678'));

	}//end testCredentialsGoAndOtherNumbersStay()
}//end class
