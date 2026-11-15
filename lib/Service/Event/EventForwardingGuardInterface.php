<?php

/**
 * Forwarding contract of the EventLoopGuard: may one object mutation become a CloudEvent.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Event;

/**
 * The forwarding decision of {@see EventLoopGuard}, for listeners that only
 * ask whether an object mutation may be forwarded into the CloudEvent machinery.
 */
interface EventForwardingGuardInterface {
	/**
	 * Refused: this request has already produced the maximum chain of CloudEvents.
	 *
	 * @var string
	 */
	public const REFUSED_CEILING = 'chain-ceiling-reached-in-one-request';

	/**
	 * Decide whether one object mutation may be forwarded.
	 *
	 * @param array<string, mixed> $payload The object's stored payload.
	 * @param string $schemaId The object's schema id, as a string; empty when unknown.
	 * @param array<int, string> $selfSchemaIds Schema ids belonging to the event machinery.
	 *
	 * @return array{forward: bool, reason: string, chain: int, ceiling: int, identified: bool}
	 *
	 * @spec exclude contract declaration only, the decision is specified on EventLoopGuard::decide()
	 */
	public function decide(array $payload, string $schemaId, array $selfSchemaIds): array;
}
