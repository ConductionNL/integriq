<?php

/**
 * Integriq Planninq Timetable Target.
 *
 * Hands a batch of timetable sessions to planninq, the fleet's timetable
 * owner (decision D10), through planninq's own typed event (ADR-041):
 * `OCA\Planninq\Event\TimetableUpsertRequestedEvent`, contract v1 of planninq
 * change `school-timetable-target`. The class is looked up by name, never
 * imported, so integriq runs without planninq installed. When the class is
 * absent or nothing answers, the delivery FAILS CLOSED: it throws, and never
 * reports a timetable as delivered.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Delivers timetable sessions into planninq.
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004
 */
class PlanninqTimetableTarget {
	/**
	 * Planninq's upsert event, contract v1.
	 */
	public const EVENT_CLASS = 'OCA\\Planninq\\Event\\TimetableUpsertRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 * @param string           $eventClass The event class name (tests only).
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly string $eventClass = self::EVENT_CLASS,
	) {
	}//end __construct()

	/**
	 * Whether planninq's event class exists on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004
	 */
	public function isAvailable(): bool {
		return class_exists($this->eventClass) === true;
	}//end isAvailable()

	/**
	 * Deliver one source's sessions and return planninq's upsert result.
	 *
	 * @param string                         $systemId      The rostering Source row id (planninq's sourceSystem).
	 * @param array<int,array<string,mixed>> $sessions      Planninq session rows.
	 * @param string                         $correlationId The caller's job or run id.
	 *
	 * @return array<string,mixed> Planninq's upsert result.
	 *
	 * @throws RosterDeliveryException `planninq-absent` when planninq is not installed or did not answer;
	 *                                 `planninq-refused` when planninq refused the batch as a whole.
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004
	 */
	public function deliver(string $systemId, array $sessions, string $correlationId = ''): array {
		if ($this->isAvailable() === false) {
			throw new RosterDeliveryException(errorCode: 'planninq-absent', message: 'Planninq is not installed, so the timetable has nowhere to go.');
		}

		// Named arguments work on a dynamic class name, and they are the
		// contract: a renamed parameter in planninq fails here, loudly.
		$event = new ($this->eventClass)(
			sourceApp: 'integriq',
			sourceSystem: $systemId,
			sessions: $sessions,
			correlationId: $correlationId,
		);
		$this->dispatcher->dispatchTyped($event);

		$result = $event->getResult();
		if ($event->isHandled() === false || is_array($result) === false) {
			throw new RosterDeliveryException(errorCode: 'planninq-absent', message: 'Planninq did not answer the timetable delivery.');
		}

		if (isset($result['error']) === true) {
			throw new RosterDeliveryException(errorCode: 'planninq-refused', message: (string)$result['error']);
		}

		return $result;
	}//end deliver()
}//end class
