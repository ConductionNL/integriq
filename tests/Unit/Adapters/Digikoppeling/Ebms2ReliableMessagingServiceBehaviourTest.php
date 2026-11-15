<?php

/**
 * Integriq — ebMS2 reliable-messaging state machine: unknown messages,
 * retry-budget boundaries, dead-letter records and conversation ordering.
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

use OCA\Integriq\Adapters\Digikoppeling\Ebms2ReliableMessagingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the reliable-delivery state machine beyond the happy paths.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
 */
#[CoversClass(Ebms2ReliableMessagingService::class)]
class Ebms2ReliableMessagingServiceBehaviourTest extends TestCase {

	/**
	 * @var Ebms2ReliableMessagingService
	 */
	private Ebms2ReliableMessagingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new Ebms2ReliableMessagingService();

	}//end setUp()

	/**
	 * A message that was never registered has no attempts and is never due or dead-lettered.
	 *
	 * @return void
	 */
	public function testUnknownMessageIsInert(): void {
		$this->service->acknowledge('ghost');

		$this->assertSame(0, $this->service->recordAttempt('ghost'));
		$this->assertFalse($this->service->isAcknowledged('ghost'));
		$this->assertFalse($this->service->dueForRetransmit('ghost', 3));
		$this->assertFalse($this->service->shouldDeadLetter('ghost', 0));
		$this->assertSame([], $this->service->orderedConversation('conv-1'));

	}//end testUnknownMessageIsInert()

	/**
	 * A fresh message is due, counts its attempts, and stops being due at the budget.
	 *
	 * @return void
	 */
	public function testRetryBudgetBoundary(): void {
		$this->service->registerOutbound('m1', 'conv-1');

		$this->assertTrue($this->service->dueForRetransmit('m1', 2));
		$this->assertFalse($this->service->shouldDeadLetter('m1', 2));

		$this->assertSame(1, $this->service->recordAttempt('m1'));
		$this->assertTrue($this->service->dueForRetransmit('m1', 2));

		$this->assertSame(2, $this->service->recordAttempt('m1'));
		$this->assertFalse($this->service->dueForRetransmit('m1', 2));
		$this->assertTrue($this->service->shouldDeadLetter('m1', 2));

	}//end testRetryBudgetBoundary()

	/**
	 * A zero budget makes a fresh message dead-letter at once and never due.
	 *
	 * @return void
	 */
	public function testZeroBudgetDeadLettersImmediately(): void {
		$this->service->registerOutbound('m1', 'conv-1');

		$this->assertFalse($this->service->dueForRetransmit('m1', 0));
		$this->assertTrue($this->service->shouldDeadLetter('m1', 0));

	}//end testZeroBudgetDeadLettersImmediately()

	/**
	 * An acknowledged message is neither retransmitted nor dead-lettered, even past budget.
	 *
	 * @return void
	 */
	public function testAcknowledgedMessageIsNeverDeadLettered(): void {
		$this->service->registerOutbound('m1', 'conv-1');
		$this->service->recordAttempt('m1');
		$this->service->acknowledge('m1');

		$this->assertTrue($this->service->isAcknowledged('m1'));
		$this->assertFalse($this->service->shouldDeadLetter('m1', 1));
		$this->assertFalse($this->service->dueForRetransmit('m1', 5));

	}//end testAcknowledgedMessageIsNeverDeadLettered()

	/**
	 * Dead-lettering returns the audit record and takes the message out of both decisions.
	 *
	 * @return void
	 */
	public function testDeadLetterRecordAndEffect(): void {
		$this->service->registerOutbound('m1', 'conv-7', 3);
		$this->service->recordAttempt('m1');
		$this->service->recordAttempt('m1');

		$this->assertSame(
			[
				'messageId' => 'm1',
				'conversationId' => 'conv-7',
				'attempts' => 2,
				'reason' => 'ebms2-retransmission-budget-exhausted',
			],
			$this->service->deadLetter('m1')
		);
		$this->assertFalse($this->service->shouldDeadLetter('m1', 2));
		$this->assertFalse($this->service->dueForRetransmit('m1', 10));

	}//end testDeadLetterRecordAndEffect()

	/**
	 * Dead-lettering an unknown message still yields a record, with no conversation and no attempts.
	 *
	 * @return void
	 */
	public function testDeadLetterOfUnknownMessage(): void {
		$this->assertSame(
			[
				'messageId' => 'ghost',
				'conversationId' => null,
				'attempts' => 0,
				'reason' => 'ebms2-retransmission-budget-exhausted',
			],
			$this->service->deadLetter('ghost')
		);
		$this->assertFalse($this->service->dueForRetransmit('ghost', 3));

	}//end testDeadLetterOfUnknownMessage()

	/**
	 * Registering a MessageId again resets its delivery state.
	 *
	 * @return void
	 */
	public function testReRegisteringResetsState(): void {
		$this->service->registerOutbound('m1', 'conv-1');
		$this->service->recordAttempt('m1');
		$this->service->acknowledge('m1');

		$this->service->registerOutbound('m1', 'conv-2');

		$this->assertFalse($this->service->isAcknowledged('m1'));
		$this->assertSame(1, $this->service->recordAttempt('m1'));
		$this->assertSame([], $this->service->orderedConversation('conv-1'));
		$this->assertSame(['m1'], $this->service->orderedConversation('conv-2'));

	}//end testReRegisteringResetsState()

	/**
	 * A conversation lists only its own messages, by ascending sequence.
	 *
	 * @return void
	 */
	public function testOrderedConversationFiltersAndSorts(): void {
		$this->service->registerOutbound('c', 'conv-1', 30);
		$this->service->registerOutbound('x', 'conv-2', 1);
		$this->service->registerOutbound('a', 'conv-1', 10);
		$this->service->registerOutbound('b', 'conv-1', 20);

		$this->assertSame(['a', 'b', 'c'], $this->service->orderedConversation('conv-1'));
		$this->assertSame(['x'], $this->service->orderedConversation('conv-2'));

	}//end testOrderedConversationFiltersAndSorts()

	/**
	 * Inbound duplicates are tracked per MessageId, independently of outbound state.
	 *
	 * @return void
	 */
	public function testInboundDuplicatesArePerMessageId(): void {
		$this->service->registerOutbound('m1', 'conv-1');

		$this->assertFalse($this->service->receiveInbound('m1'));
		$this->assertFalse($this->service->receiveInbound('m2'));
		$this->assertTrue($this->service->receiveInbound('m1'));
		$this->assertTrue($this->service->receiveInbound('m2'));
		$this->assertSame(1, $this->service->recordAttempt('m1'));

	}//end testInboundDuplicatesArePerMessageId()
}//end class
