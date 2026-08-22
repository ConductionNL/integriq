<?php

/**
 * OpenConnector — Grote Berichten reference tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use OCA\Integriq\Adapters\Digikoppeling\GroteBerichtenReference;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Grote Berichten out-of-band reference + checksum (REQ-DK-004).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class GroteBerichtenReferenceTest extends TestCase {

	/**
	 * A reference carries the URL + checksum, not the inline payload, and the
	 * payload round-trips when its checksum matches.
	 *
	 * @return void
	 */
	public function testReferenceRoundTrip(): void {
		$payload = str_repeat('grote-berichten-', 4096);
		$ref = GroteBerichtenReference::forPayload('https://gb.example/doc/1', $payload);

		$part = $ref->toMessagePart();
		$this->assertSame('https://gb.example/doc/1', $part['href']);
		$this->assertSame(hash('sha256', $payload), $part['checksum']);
		$this->assertSame(strlen($payload), $part['sizeBytes']);
		$this->assertStringNotContainsString('grote-berichten', json_encode($part));

		$this->assertSame($payload, $ref->verifyPayload($payload));
	}//end testReferenceRoundTrip()

	/**
	 * A checksum mismatch on retrieval is rejected as a transport error.
	 *
	 * @return void
	 */
	public function testChecksumMismatchRejected(): void {
		$ref = GroteBerichtenReference::forPayload('https://gb.example/doc/2', 'original');

		$this->expectException(DigikoppelingException::class);
		$ref->verifyPayload('tampered');
	}//end testChecksumMismatchRejected()
}//end class
