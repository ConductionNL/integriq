<?php

/**
 * Unit tests for SystemDnsResolver: a host it cannot look up answers no
 * records instead of throwing, so a missing record reads as "not checked".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\SystemDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the host-resolver binding's refusals.
 *
 * @covers \OCA\Integriq\Outbound\Identity\SystemDnsResolver
 */
class SystemDnsResolverTest extends TestCase {

	/**
	 * An empty or blank host answers no records without a lookup.
	 *
	 * @dataProvider blankHosts
	 *
	 * @param string $host The host.
	 *
	 * @return void
	 */
	public function testABlankHostAnswersNoRecords(string $host): void {
		$this->assertSame([], (new SystemDnsResolver())->txt($host));
	}//end testABlankHostAnswersNoRecords()

	/**
	 * Blank hosts.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function blankHosts(): array {
		return [
			'empty' => [''],
			'spaces' => ['   '],
			'whitespace' => ["\t\n "],
		];
	}//end blankHosts()

}//end class
