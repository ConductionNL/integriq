<?php

/**
 * A mapped payload answers the path a field is read from: the mapped path when
 * the source maps it, else the field itself.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\MappedPayload;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MappedPayload.
 *
 * @covers \OCA\Integriq\Service\Kiss\MappedPayload
 */
class MappedPayloadTest extends TestCase {

	/**
	 * A mapped field reads from its mapped path; an unmapped one from itself.
	 *
	 * @return void
	 */
	public function testPathOfFollowsTheMapping(): void {
		$mapped = new MappedPayload(payload: ['call' => ['from' => '0201234567']], mapping: ['caller' => 'call.from', 'direction' => 7]);

		$this->assertSame('call.from', $mapped->pathOf('caller'));
		$this->assertSame('7', $mapped->pathOf('direction'));
		$this->assertSame('callId', $mapped->pathOf('callId'));
		$this->assertSame(['call' => ['from' => '0201234567']], $mapped->payload);
		$this->assertSame(['caller' => 'call.from', 'direction' => 7], $mapped->mapping);
	}//end testPathOfFollowsTheMapping()

	/**
	 * A field mapped to null falls back to the field name.
	 *
	 * @return void
	 */
	public function testANullMappingFallsBackToTheField(): void {
		$this->assertSame('caller', (new MappedPayload(payload: [], mapping: ['caller' => null]))->pathOf('caller'));
	}//end testANullMappingFallsBackToTheField()
}//end class
