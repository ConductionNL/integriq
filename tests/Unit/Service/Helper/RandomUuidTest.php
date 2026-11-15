<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\RandomUuid;
use PHPUnit\Framework\TestCase;

/**
 * A random RFC 4122 version 4 UUID.
 *
 * @covers \OCA\Integriq\Service\Helper\RandomUuid
 */
final class RandomUuidTest extends TestCase {

	public function testItIsAVersion4UuidWithTheRfc4122Variant(): void {
		for ($i = 0; $i < 50; $i++) {
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				RandomUuid::version4()
			);
		}
	}

	public function testItDiffersEachTime(): void {
		$ids = [];
		for ($i = 0; $i < 100; $i++) {
			$ids[] = RandomUuid::version4();
		}

		$this->assertCount(100, array_unique($ids));
	}
}
