<?php

/**
 * Integriq Call Event Log.
 *
 * The 30-day record of what a CTI source told us, and the one place a contact
 * moment finds the call it is recorded for.
 *
 * Writing a row here is NOT writing a contact moment. A klantcontact exists
 * only when an agent asks for one (REQ-007). Rows are swept by OpenRegister's
 * retention job 30 days after they were written, through the
 * `x-openregister-archival` block on the `call_event` schema
 * (lib/Settings/register.d/cti-call-events.json).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Event\CallEvent;
use OCA\Integriq\Exception\CallEventNotFoundException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Writes accepted call events and finds the ended one a contact moment names.
 */
class CallEventLog {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OpenRegister schema slug of a call event row.
	 *
	 * @var string
	 */
	public const SCHEMA = 'call_event';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OpenRegister's object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Write one accepted call event.
	 *
	 * System context: the CTI webhook has no session, and the schema is closed
	 * to everyone but administrators because a row holds a phone number.
	 *
	 * @param CallEvent $event The event as it was dispatched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	public function record(CallEvent $event): void {
		$this->objectService->saveObject(
			object: [
				'callId'          => $event->callId,
				'kind'            => $event->kind,
				'callerNumber'    => $event->callerNumber,
				'sourceId'        => $event->sourceId,
				'agentId'         => $event->agentId,
				'at'              => $event->at,
				'durationSeconds' => max(0, $event->durationSeconds),
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false,
		);

	}//end record()

	/**
	 * The ended event of a call, as the agent's panel names it.
	 *
	 * Two ended events under one callId from different sources are two calls.
	 * Picking one would record the contact moment on a call the agent may not
	 * have taken, so without a sourceId that tells them apart this refuses.
	 *
	 * @param string $callId   The PBX's call id.
	 * @param string $sourceId The CTI source, or '' when the panel does not say.
	 *
	 * @return array<string, mixed> The stored call event.
	 *
	 * @throws CallEventNotFoundException When no single ended event matches.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	public function findEnded(string $callId, string $sourceId=''): array {
		if ($callId === '') {
			throw new CallEventNotFoundException(message: 'No ended call with this callId.');
		}

		$filters = [
			'register' => self::REGISTER,
			'schema'   => self::SCHEMA,
			'callId'   => $callId,
			'kind'     => CallEvent::KIND_ENDED,
		];
		if ($sourceId !== '') {
			$filters['sourceId'] = $sourceId;
		}

		$found   = $this->objectService->findAll(
			config: ['filters' => $filters, 'limit' => 10],
			_rbac: false,
			_multitenancy: false,
		);
		$results = ($found['results'] ?? $found);

		$matches = [];
		foreach ((array) $results as $result) {
			$row = $this->endedRow(result: $result, callId: $callId, sourceId: $sourceId);
			if ($row !== null) {
				$matches[(string) ($row['sourceId'] ?? '')] = $row;
			}
		}

		if (count($matches) !== 1) {
			throw new CallEventNotFoundException(message: 'No single ended call with this callId.');
		}

		return array_values($matches)[0];

	}//end findEnded()

	/**
	 * A found row, when it really is the ended event asked for.
	 *
	 * The filter is a request to OpenRegister, not a guarantee: the fields are
	 * read again so a dropped filter cannot hand back another call's event.
	 *
	 * @param mixed  $result   An entity or array from findAll().
	 * @param string $callId   The PBX's call id.
	 * @param string $sourceId The CTI source, or '' for any.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	private function endedRow(mixed $result, string $callId, string $sourceId): ?array {
		$row = $result;
		if (is_object($result) === true && method_exists($result, 'getObject') === true) {
			$row = $result->getObject();
		}

		if (is_array($row) === false
			|| (string) ($row['callId'] ?? '') !== $callId
			|| (string) ($row['kind'] ?? '') !== CallEvent::KIND_ENDED
		) {
			return null;
		}

		if ($sourceId !== '' && (string) ($row['sourceId'] ?? '') !== $sourceId) {
			return null;
		}

		return $row;

	}//end endedRow()

}//end class
