<?php

/**
 * Integriq — InboundRouteReceipt tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\GatewayRegistry;
use OCA\Integriq\Gateway\InboundRouteReceipt;
use PHPUnit\Framework\TestCase;

/**
 * What a receipt records for a known and an unknown route.
 *
 * @covers \OCA\Integriq\Gateway\InboundRouteReceipt
 * @uses   \OCA\Integriq\Gateway\GatewayRegistry
 * @uses   \OCA\Integriq\Gateway\GatewayDescriptor
 */
class InboundRouteReceiptTest extends TestCase {
	/**
	 * A registry holding one route with a met and a handed-on obligation.
	 *
	 * @return GatewayRegistry The registry.
	 */
	private function registry(): GatewayRegistry {
		return new GatewayRegistry(
			[
				[
					'id' => 'route-a',
					'standard' => 'Route A standard',
					'claimLevel' => 'partial',
					'claimEvidence' => 'Fixture.',
					'wmebvMet' => ['acknowledge-receipt'],
					'wmebvHandedToConsumer' => [['obligation' => 'archive', 'consumerDuty' => 'Keep it.']],
				],
			]
		);
	}//end registry()

	/**
	 * A known route records what the entry met and handed on at receipt time.
	 *
	 * @return void
	 */
	public function testAKnownRouteRecordsItsObligations(): void {
		$receipt = (new InboundRouteReceipt($this->registry()))->record('route-a', 'm-1', 0);

		$this->assertSame(
			[
				'route' => 'route-a',
				'routeKnown' => true,
				'messageId' => 'm-1',
				'receivedAt' => '1970-01-01T00:00:00+00:00',
				'wmebvMet' => ['acknowledge-receipt'],
				'wmebvHandedToConsumer' => [['obligation' => 'archive', 'consumerDuty' => 'Keep it.']],
			],
			$receipt
		);
	}//end testAKnownRouteRecordsItsObligations()

	/**
	 * An unknown route is recorded as unknown with no obligations.
	 *
	 * @return void
	 */
	public function testAnUnknownRouteIsRecordedAsUnknown(): void {
		$receipt = (new InboundRouteReceipt($this->registry()))->record('route-b', 'm-2', 86400);

		$this->assertSame(
			[
				'route' => 'route-b',
				'routeKnown' => false,
				'messageId' => 'm-2',
				'receivedAt' => '1970-01-02T00:00:00+00:00',
				'wmebvMet' => [],
				'wmebvHandedToConsumer' => [],
			],
			$receipt
		);
	}//end testAnUnknownRouteIsRecordedAsUnknown()

	/**
	 * Without a timestamp the receipt is stamped with the current time.
	 *
	 * @return void
	 */
	public function testWithoutATimestampTheCurrentTimeIsUsed(): void {
		$before = time();
		$receipt = (new InboundRouteReceipt($this->registry()))->record('route-a', 'm-3');
		$after = time();

		$stamped = strtotime($receipt['receivedAt']);
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);
	}//end testWithoutATimestampTheCurrentTimeIsUsed()
}//end class
