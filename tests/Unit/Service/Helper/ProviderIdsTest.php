<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\ProviderIds;
use PHPUnit\Framework\TestCase;

/**
 * An empty provider id means the log provider, and a list of ids reads as
 * a comma list or "(none)".
 *
 * @covers \OCA\Integriq\Service\Helper\ProviderIds
 */
final class ProviderIdsTest extends TestCase {

	public function testAnEmptyIdIsTheLogProvider(): void {
		$this->assertSame('log', ProviderIds::orLog(providerId: ''));
	}

	public function testAnyOtherIdIsKept(): void {
		$this->assertSame('rest', ProviderIds::orLog(providerId: 'rest'));
		$this->assertSame(' ', ProviderIds::orLog(providerId: ' '));
	}

	public function testIdsAreListedWithCommas(): void {
		$this->assertSame('log, rest, soap', ProviderIds::listed(ids: ['log', 'rest', 'soap']));
		$this->assertSame('log', ProviderIds::listed(ids: ['log']));
	}

	public function testNoIdsReadAsNone(): void {
		$this->assertSame('(none)', ProviderIds::listed(ids: []));
	}
}
