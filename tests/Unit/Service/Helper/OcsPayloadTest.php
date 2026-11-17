<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\OcsPayload;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A decoded OCS value as an array: an array is kept, anything else is empty.
 *
 * @covers \OCA\Integriq\Service\Helper\OcsPayload
 */
final class OcsPayloadTest extends TestCase {

	public function testAnArrayIsKeptAsItIs(): void {
		$this->assertSame(['id' => 3, 'rows' => [1]], OcsPayload::asArray(value: ['id' => 3, 'rows' => [1]]));
		$this->assertSame([], OcsPayload::asArray(value: []));
	}

	/**
	 * @dataProvider notArrays
	 */
	public function testAnythingElseIsEmpty(mixed $value): void {
		$this->assertSame([], OcsPayload::asArray(value: $value));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function notArrays(): array {
		return ['null' => [null], 'string' => ['[1,2]'], 'int' => [1], 'false' => [false], 'object' => [new stdClass()]];
	}
}
