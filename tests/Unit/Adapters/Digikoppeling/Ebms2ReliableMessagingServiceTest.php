<?php

/**
 * OpenConnector — ebMS2 reliable-messaging state-machine tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling
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
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling;

use OCA\OpenConnector\Adapters\Digikoppeling\Ebms2ReliableMessagingService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ebMS2 reliability state machine (REQ-DK-003).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class Ebms2ReliableMessagingServiceTest extends TestCase {

	/**
	 * An unacknowledged message is retransmitted up to the budget, then
	 * dead-lettered.
	 *
	 * @return void
	 */
	public function testRetransmitThenDeadLetter(): void {
		$svc = new Ebms2ReliableMessagingService();
		$svc->registerOutbound('m1', 'c1');

		$budget = 3;
		$attempts = 0;
		while ($svc->dueForRetransmit('m1', $budget) === true) {
			$svc->recordAttempt('m1');
			$attempts++;
			if ($attempts > 10) {
				$this->fail('retransmit loop did not terminate');
			}
		}

		$this->assertSame(3, $attempts);
		$this->assertTrue($svc->shouldDeadLetter('m1', $budget));

		$record = $svc->deadLetter('m1');
		$this->assertSame('m1', $record['messageId']);
		$this->assertSame('ebms2-retransmission-budget-exhausted', $record['reason']);
		$this->assertFalse($svc->dueForRetransmit('m1', $budget));
	}//end testRetransmitThenDeadLetter()

	/**
	 * An acknowledged message is no longer retransmitted nor dead-lettered.
	 *
	 * @return void
	 */
	public function testAcknowledgedStopsRetransmit(): void {
		$svc = new Ebms2ReliableMessagingService();
		$svc->registerOutbound('m2', 'c1');
		$svc->recordAttempt('m2');
		$svc->acknowledge('m2');

		$this->assertTrue($svc->isAcknowledged('m2'));
		$this->assertFalse($svc->dueForRetransmit('m2', 3));
		$this->assertFalse($svc->shouldDeadLetter('m2', 3));
	}//end testAcknowledgedStopsRetransmit()

	/**
	 * A duplicate inbound MessageId is eliminated (processed at most once).
	 *
	 * @return void
	 */
	public function testInboundDeduplication(): void {
		$svc = new Ebms2ReliableMessagingService();

		$this->assertFalse($svc->receiveInbound('in-1'), 'first receipt is not a duplicate');
		$this->assertTrue($svc->receiveInbound('in-1'), 'second receipt is a duplicate');
		$this->assertFalse($svc->receiveInbound('in-2'));
	}//end testInboundDeduplication()

	/**
	 * Messages are ordered per conversation by sequence number.
	 *
	 * @return void
	 */
	public function testConversationOrdering(): void {
		$svc = new Ebms2ReliableMessagingService();
		$svc->registerOutbound('b', 'conv', 2);
		$svc->registerOutbound('a', 'conv', 1);
		$svc->registerOutbound('c', 'conv', 3);
		$svc->registerOutbound('x', 'other', 1);

		$this->assertSame(['a', 'b', 'c'], $svc->orderedConversation('conv'));
	}//end testConversationOrdering()
}//end class
