<?php

/**
 * A gateway delivery: delivered, refused and never sent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\GatewayDelivery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Gateway\GatewayDelivery
 */
class GatewayDeliveryTest extends TestCase {

	/**
	 * A delivery carries its identifier and transport, and is not replayed.
	 *
	 * @return void
	 */
	public function testADeliveredMessageIsNotReplayable(): void {
		$delivery = GatewayDelivery::delivered(gatewayId: 'postex', identifier: 'msg-1', transport: 'digikoppeling');

		$this->assertTrue(condition: $delivery->isDelivered());
		$this->assertTrue(condition: $delivery->wasTransmitted());
		$this->assertFalse(condition: $delivery->isReplayable());
		$this->assertSame(expected: 'msg-1', actual: $delivery->getIdentifier());
		$this->assertSame(expected: '', actual: $delivery->getReason());
		$this->assertSame(
			expected: [
				'gateway' => 'postex',
				'delivered' => true,
				'identifier' => 'msg-1',
				'reason' => '',
				'transmitted' => true,
				'replayable' => false,
				'transport' => 'digikoppeling',
			],
			actual: $delivery->toArray()
		);
	}//end testADeliveredMessageIsNotReplayable()

	/**
	 * A delivery without an identifier defaults to https.
	 *
	 * @return void
	 */
	public function testADeliveryDefaultsToHttps(): void {
		$delivery = GatewayDelivery::delivered(gatewayId: 'corv');

		$this->assertNull(actual: $delivery->getIdentifier());
		$this->assertSame(expected: 'https', actual: $delivery->toArray()['transport']);
	}//end testADeliveryDefaultsToHttps()

	/**
	 * A refusal was transmitted, failed with the other side's reason, and can be replayed.
	 *
	 * @return void
	 */
	public function testARefusalIsATransmittedReplayableFailure(): void {
		$delivery = GatewayDelivery::refused(gatewayId: 'wkpb', reason: 'Kadaster refused the parcel', transport: 'ebms');

		$this->assertFalse(condition: $delivery->isDelivered());
		$this->assertTrue(condition: $delivery->wasTransmitted());
		$this->assertTrue(condition: $delivery->isReplayable());
		$this->assertNull(actual: $delivery->getIdentifier());
		$this->assertSame(expected: 'Kadaster refused the parcel', actual: $delivery->getReason());
		$this->assertSame(expected: 'ebms', actual: $delivery->toArray()['transport']);
	}//end testARefusalIsATransmittedReplayableFailure()

	/**
	 * A message that never validated was not transmitted and is not replayed as is.
	 *
	 * @return void
	 */
	public function testAMessageNotSentWasNeverTransmitted(): void {
		$delivery = GatewayDelivery::notSent(gatewayId: 'ggk', reason: 'The payload failed validation');

		$this->assertFalse(condition: $delivery->isDelivered());
		$this->assertFalse(condition: $delivery->wasTransmitted());
		$this->assertFalse(condition: $delivery->isReplayable());
		$this->assertSame(expected: 'The payload failed validation', actual: $delivery->getReason());
		$this->assertSame(
			expected: [
				'gateway' => 'ggk',
				'delivered' => false,
				'identifier' => null,
				'reason' => 'The payload failed validation',
				'transmitted' => false,
				'replayable' => false,
				'transport' => 'https',
			],
			actual: $delivery->toArray()
		);
	}//end testAMessageNotSentWasNeverTransmitted()
}//end class
