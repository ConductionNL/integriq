<?php

/**
 * Emitting contract of the EventService: construct and process one CloudEvent.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The CloudEvent emitter of {@see EventService}, for callers that only
 * announce a domain event and never deliver, replay or process messages.
 */
interface CloudEventEmitterInterface {
	/**
	 * Construct and process an arbitrary CloudEvent.
	 *
	 * Generalises the `handleObjectCreated`/`Updated`/`Deleted` shape (build a
	 * CloudEvents-shaped `event` OR object, then fan it out via
	 * {@see processEvent}) for connectors that need to emit a domain-specific
	 * event `type` — e.g. `nl.conduction.peppol.delivery.status` — rather than
	 * one of the fixed `com.nextcloud.openregister.object.*` types.
	 *
	 * @param string $type The CloudEvents `type` attribute (e.g. `nl.conduction.peppol.delivery.status`).
	 * @param string $source The CloudEvents `source` attribute (the producing component identity).
	 * @param string|null $subject The CloudEvents `subject` attribute, or null when not applicable.
	 * @param array $data The CloudEvents `data` payload.
	 * @param string|null $userId The Nextcloud user id that produced this event, or null for system-produced.
	 *
	 * @return ObjectEntity[] The created CloudEvent messages.
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-delivery-status-cloudevents-on-every-state-change-req-004
	 */
	public function emitCloudEvent(string $type, string $source, ?string $subject, array $data, ?string $userId = null): array;
}
