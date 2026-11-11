<?php

/**
 * Integriq RosterDelivery.
 *
 * One finished roster delivery: the source, the caller's correlation id, the
 * number of fetched sessions and planninq's answer.
 *
 * @category Sources
 * @package  OCA\Integriq\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

/**
 * Value object for one finished roster delivery (ADR-011).
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */
final class RosterDelivery {

	/**
	 * Constructor.
	 *
	 * @param string              $systemId      The rostering Source row id.
	 * @param string              $correlationId The caller's job or run id.
	 * @param int                 $fetched       The number of sessions fetched.
	 * @param array<string,mixed> $planninq      Planninq's answer.
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function __construct(
		public readonly string $systemId,
		public readonly string $correlationId,
		public readonly int $fetched,
		public readonly array $planninq,
	) {
	}//end __construct()
}//end class
