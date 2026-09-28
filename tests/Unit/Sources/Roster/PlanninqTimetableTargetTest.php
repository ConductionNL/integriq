<?php

/**
 * Tests for PlanninqTimetableTarget, run against a verbatim copy of planninq's
 * event class (tests/stubs/planninq/TimetableUpsertRequestedEvent.php).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

require_once __DIR__ . '/../../../stubs/planninq/TimetableUpsertRequestedEvent.php';

use OCA\Integriq\Sources\Roster\PlanninqTimetableTarget;
use OCA\Integriq\Sources\Roster\RosterDeliveryException;
use OCA\Planninq\Event\TimetableUpsertRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Delivery dispatches planninq's event and fails closed without an answer.
 */
class PlanninqTimetableTargetTest extends TestCase {
	/**
	 * A dispatcher that hands every event to a callback, like a registered listener.
	 *
	 * @param callable|null $listener Receives the event; null means nobody listens.
	 * @param array<int,Event> $seen  Collects dispatched events (by reference).
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(?callable $listener, array &$seen = []): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener, &$seen): void {
				$seen[] = $event;
				if ($listener !== null) {
					$listener($event);
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * Planninq answers: its result comes back, and the event carried integriq's provenance.
	 *
	 * @return void
	 */
	public function testPlanninqAnswerIsReturned(): void {
		$seen = [];
		$target = new PlanninqTimetableTarget(
			$this->dispatcher(
				static function (TimetableUpsertRequestedEvent $event): void {
					$event->setResult(['contractVersion' => 1, 'sourceSystem' => $event->getSourceSystem(), 'processed' => count($event->getSessions()), 'created' => 2]);
				},
				$seen
			)
		);

		$result = $target->deliver('roster-zermelo', [['externalRef' => 'a'], ['externalRef' => 'b']], 'job-7');

		$this->assertTrue($target->isAvailable());
		$this->assertSame(2, $result['created']);
		$this->assertSame('roster-zermelo', $result['sourceSystem']);
		$this->assertInstanceOf(TimetableUpsertRequestedEvent::class, $seen[0]);
		$this->assertSame('integriq', $seen[0]->getSourceApp());
		$this->assertSame('job-7', $seen[0]->getCorrelationId());
	}//end testPlanninqAnswerIsReturned()

	/**
	 * Nobody answers: the delivery fails closed.
	 *
	 * @return void
	 */
	public function testSilentPlanninqFailsClosed(): void {
		$target = new PlanninqTimetableTarget($this->dispatcher(null));

		try {
			$target->deliver('roster-zermelo', [['externalRef' => 'a']]);
			$this->fail('an unanswered delivery must not pass');
		} catch (RosterDeliveryException $e) {
			$this->assertSame('planninq-absent', $e->getErrorCode());
		}
	}//end testSilentPlanninqFailsClosed()

	/**
	 * Planninq not installed: nothing is dispatched and the delivery fails closed.
	 *
	 * @return void
	 */
	public function testAbsentPlanninqFailsClosedWithoutDispatching(): void {
		$seen = [];
		$target = new PlanninqTimetableTarget($this->dispatcher(null, $seen), 'OCA\\Planninq\\Event\\NoSuchEvent');

		$this->assertFalse($target->isAvailable());
		try {
			$target->deliver('roster-zermelo', []);
			$this->fail('a delivery without planninq must not pass');
		} catch (RosterDeliveryException $e) {
			$this->assertSame('planninq-absent', $e->getErrorCode());
		}

		$this->assertSame([], $seen);
	}//end testAbsentPlanninqFailsClosedWithoutDispatching()

	/**
	 * Planninq refuses the batch as a whole: the refusal is not a delivery.
	 *
	 * @return void
	 */
	public function testRefusedBatchIsReportedAsRefused(): void {
		$target = new PlanninqTimetableTarget(
			$this->dispatcher(
				static function (TimetableUpsertRequestedEvent $event): void {
					$event->setResult(['contractVersion' => 1, 'created' => 0, 'error' => 'OpenRegister is not available.']);
				}
			)
		);

		$this->expectException(RosterDeliveryException::class);
		$this->expectExceptionMessage('OpenRegister is not available.');
		$target->deliver('roster-zermelo', [['externalRef' => 'a']]);
	}//end testRefusedBatchIsReportedAsRefused()
}//end class
