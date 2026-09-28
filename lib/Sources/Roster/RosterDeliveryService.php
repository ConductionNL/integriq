<?php

/**
 * Integriq Roster Delivery Service.
 *
 * One rostering delivery end to end: fetch a source's lessons, map them onto
 * planninq's timetable session shape, hand them to planninq, and report what
 * planninq did. This is the `timetable-import` handler: learniq reaches it
 * through {@see \OCA\Integriq\Event\RosterImportRequestedEvent}, and
 * integriq's native exchange job runner can call it directly once that lands.
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
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers one rostering source into planninq.
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */
class RosterDeliveryService {
	/**
	 * Contract version of the delivery result.
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param RosterImportSourceAdapter   $adapter Fetches and maps a source's lessons.
	 * @param RosterMappingPresetRegistry $presets Knows the rostering sources.
	 * @param PlanninqTimetableTarget     $target  Hands sessions to planninq.
	 * @param LoggerInterface             $logger  Structured logger.
	 */
	public function __construct(
		private readonly RosterImportSourceAdapter $adapter,
		private readonly RosterMappingPresetRegistry $presets,
		private readonly PlanninqTimetableTarget $target,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function deliver(string $systemId, array $options = [], string $correlationId = ''): array {
		if ($this->presets->has(systemId: $systemId) === false) {
			throw new RosterDeliveryException(errorCode: 'unknown-source', message: "'{$systemId}' is not a rostering source.");
		}

		try {
			$sessions = $this->adapter->importLessons(systemId: $systemId, options: $options);
		} catch (Throwable $e) {
			throw new RosterDeliveryException(errorCode: 'fetch-failed', message: 'The rostering system could not be read: ' . $e->getMessage(), previous: $e);
		}

		$planninq = $this->target->deliver(systemId: $systemId, sessions: $sessions, correlationId: $correlationId);

		$this->logger->info(
			'roster-delivery.delivered',
			[
				'source' => $systemId,
				'correlation' => $correlationId,
				'fetched' => count($sessions),
				'created' => ($planninq['created'] ?? null),
				'updated' => ($planninq['updated'] ?? null),
				'unchanged' => ($planninq['unchanged'] ?? null),
				'rejected' => count((array)($planninq['rejected'] ?? [])),
			]
		);

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'status' => 'delivered',
			'systemId' => $systemId,
			'target' => RosterTargetConfiguration::TARGET,
			'flavour' => $this->adapter->flavour(),
			'active' => $this->adapter->isActive(),
			'fetched' => count($sessions),
			'planninq' => $planninq,
		];
	}//end deliver()
}//end class
