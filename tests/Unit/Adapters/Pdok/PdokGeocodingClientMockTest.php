<?php

/**
 * Unit tests for the dormant PDOK geocoding mock's find and reverse answers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Pdok;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Find and reverse answer the one canned address whatever they are asked, so
 * a dormant install always has a result to render.
 *
 * @covers \OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock
 */
class PdokGeocodingClientMockTest extends TestCase {

	/**
	 * Find answers exactly the canned entry lookup gives, for any query.
	 *
	 * @return void
	 */
	public function testFindAnswersTheCannedAddressForAnyQuery(): void {
		$mock = new PdokGeocodingClientMock();
		$canned = $mock->lookup('any-id');

		$this->assertSame([$canned], $mock->find('Dorpsstraat 1', 3));
		$this->assertSame([$canned], $mock->find(''));
		$this->assertSame('adr-mock-lauriergracht-37', $mock->find('x')[0]['pdokId']);
	}//end testFindAnswersTheCannedAddressForAnyQuery()

	/**
	 * Reverse answers the canned entry as its one candidate, wherever the point is.
	 *
	 * @return void
	 */
	public function testReverseAnswersTheCannedAddressForAnyPoint(): void {
		$mock = new PdokGeocodingClientMock();
		$canned = $mock->lookup('any-id');

		$this->assertSame([$canned], $mock->reverse(52.0, 5.0));
		$this->assertSame([$canned], $mock->reverse(-90.0, 180.0));
		$this->assertSame('Lauriergracht 37, 1016 RG Amsterdam', $mock->reverse(0.0, 0.0)[0]['displayName']);
	}//end testReverseAnswersTheCannedAddressForAnyPoint()
}//end class
