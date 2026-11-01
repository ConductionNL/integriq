<?php

/**
 * Contract of the RosterDeliveryService.
 *
 * @category Contract
 * @package  OCA\Integriq\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

/**
 * Public operations of {@see RosterDeliveryService} that other components call.
 */
interface RosterDeliveryServiceInterface {
	/**
	 * Deliver one source into planninq.
	 *
	 * @param string              $systemId      The rostering Source row id.
	 * @param array<string,mixed> $options       The delivery's `groupMap` and `teacherMap`, if any.
	 * @param string              $correlationId The caller's job or run id.
	 *
	 * @return array<string,mixed> The contract's success result.
	 *
	 * @throws RosterDeliveryException With the contract error code.
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function deliver(string $systemId, array $options = [], string $correlationId = ''): array;
}
