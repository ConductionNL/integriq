<?php

/**
 * The gate and records events carry the job they belong to.
 *
 * Pins the job fields every getter of the gate and records events answers,
 * with and without the optional owner reference and scope, and the gate's
 * allowed and refused outcomes, so the shared job fields can move without
 * changing what the owning app reads.
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\ExchangeGateRequestedEvent;
use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the job fields of the gate and records events.
 */
final class ExchangeJobRunEventsTest extends TestCase {

	/**
	 * Both events answer the job fields they were built with.
	 *
	 * @return void
	 */
	public function testBothCarryTheirJob(): void {
		$gate = new ExchangeGateRequestedEvent(jobId: 'job-1', ownerApp: 'learniq', target: 'lvs', direction: 'export', ownerRef: 'x/1', scope: ['group' => 'g']);
		$records = new ExchangeRecordsReceivedEvent(jobId: 'job-2', ownerApp: 'humaniq', target: 'hr', direction: 'import', ownerRef: 'y/2', scope: ['a'], records: [['recordId' => 'r']]);

		foreach ([[$gate, 'job-1', 'learniq', 'lvs', 'export', 'x/1', ['group' => 'g']], [$records, 'job-2', 'humaniq', 'hr', 'import', 'y/2', ['a']]] as [$event, $job, $app, $target, $direction, $ref, $scope]) {
			self::assertInstanceOf(Event::class, $event);
			self::assertSame($job, $event->getJobId());
			self::assertSame($app, $event->getOwnerApp());
			self::assertSame($target, $event->getTarget());
			self::assertSame($direction, $event->getDirection());
			self::assertSame($ref, $event->getOwnerRef());
			self::assertSame($scope, $event->getScope());
			self::assertFalse($event->isAnswered());
		}

		self::assertSame([['recordId' => 'r']], $records->getRecords());

	}//end testBothCarryTheirJob()

	/**
	 * The owner reference and scope default to empty.
	 *
	 * @return void
	 */
	public function testTheOptionalFieldsDefaultToEmpty(): void {
		$gate = new ExchangeGateRequestedEvent(jobId: 'j', ownerApp: 'a', target: 't', direction: 'd');
		$records = new ExchangeRecordsReceivedEvent(jobId: 'j', ownerApp: 'a', target: 't', direction: 'd');

		self::assertSame('', $gate->getOwnerRef());
		self::assertSame([], $gate->getScope());
		self::assertSame('', $records->getOwnerRef());
		self::assertSame([], $records->getScope());
		self::assertSame([], $records->getRecords());

	}//end testTheOptionalFieldsDefaultToEmpty()

	/**
	 * The gate refuses until allowed, and keeps its first answer.
	 *
	 * @return void
	 */
	public function testTheGateKeepsItsFirstAnswer(): void {
		$closed = new ExchangeGateRequestedEvent(jobId: 'j', ownerApp: 'a', target: 't', direction: 'd');
		self::assertFalse($closed->isAllowed());
		self::assertNull($closed->getRefusal());

		$allowed = new ExchangeGateRequestedEvent(jobId: 'j', ownerApp: 'a', target: 't', direction: 'd');
		$allowed->allow(records: ['k' => ['recordId' => 'r']]);
		$allowed->refuse(code: 'late', reason: 'too late');
		self::assertTrue($allowed->isAnswered());
		self::assertTrue($allowed->isAllowed());
		self::assertSame([['recordId' => 'r']], $allowed->getRecords());
		self::assertNull($allowed->getRefusal());

		$refused = new ExchangeGateRequestedEvent(jobId: 'j', ownerApp: 'a', target: 't', direction: 'd');
		$refused->refuse(code: 'no', reason: 'not now');
		$refused->allow(records: [['recordId' => 'r']]);
		self::assertTrue($refused->isAnswered());
		self::assertFalse($refused->isAllowed());
		self::assertSame([], $refused->getRecords());
		self::assertSame(['code' => 'no', 'reason' => 'not now'], $refused->getRefusal());

	}//end testTheGateKeepsItsFirstAnswer()
}//end class
