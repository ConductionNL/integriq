<?php

/**
 * The records event keeps the first answer and never keeps a value.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Event\ExchangeRecordsReceivedEvent
 */
final class ExchangeRecordsReceivedEventTest extends TestCase {

	/**
	 * An event handing over three records.
	 *
	 * @return ExchangeRecordsReceivedEvent
	 */
	private function event(): ExchangeRecordsReceivedEvent {
		return new ExchangeRecordsReceivedEvent(
			jobId: 'job-1',
			ownerApp: 'learniq',
			target: 'lvs-results',
			direction: 'import',
			ownerRef: 'import/7',
			scope: [],
			records: [
				['recordId' => 'a', 'sourceKind' => 'lvs-result', 'data' => []],
				['recordId' => 'b', 'sourceKind' => 'lvs-result', 'data' => []],
				['recordId' => 'c', 'sourceKind' => 'lvs-result', 'data' => []],
			]
		);
	}//end event()

	/**
	 * Unanswered until accept() runs.
	 *
	 * @return void
	 */
	public function testUnansweredByDefault(): void {
		$event = $this->event();

		$this->assertFalse($event->isAnswered());
		$this->assertSame(0, $event->getAcceptedCount());
		$this->assertSame([], $event->getRejected());
	}//end testUnansweredByDefault()

	/**
	 * The first answer counts; a second listener cannot rewrite it.
	 *
	 * @return void
	 */
	public function testTheFirstAnswerWins(): void {
		$event = $this->event();
		$event->accept(acceptedCount: 3);
		$event->accept(acceptedCount: 0, rejected: [['recordId' => 'a', 'errorCode' => 'X']]);

		$this->assertTrue($event->isAnswered());
		$this->assertSame(3, $event->getAcceptedCount());
		$this->assertSame([], $event->getRejected());
	}//end testTheFirstAnswerWins()

	/**
	 * Unknown ids and empty codes are dropped, values never kept, and the count clamped.
	 *
	 * @return void
	 */
	public function testRejectionsAreNormalised(): void {
		$event = $this->event();
		$event->accept(
			acceptedCount: 99,
			rejected: [
				['recordId' => 'b', 'errorCode' => 'LVS-UNKNOWN-PUPIL', 'offendingFields' => ['eckId', 3, ''], 'value' => 'secret'],
				['recordId' => 'zzz', 'errorCode' => 'X'],
				['recordId' => 'c', 'errorCode' => ''],
			]
		);

		$this->assertSame(
			[['recordId' => 'b', 'sourceKind' => 'lvs-result', 'errorCode' => 'LVS-UNKNOWN-PUPIL', 'offendingFields' => ['eckId']]],
			$event->getRejected()
		);
		$this->assertSame(2, $event->getAcceptedCount());
	}//end testRejectionsAreNormalised()
}//end class
