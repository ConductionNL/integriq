<?php

/**
 * Integriq Intake Assignment Service.
 *
 * Works a message from the shared intake inbox: a handler links a held or
 * routed message to a case that already exists. The message records the case
 * and who linked it, and the app that owns the case is asked to take the
 * message and its attachments in (IntakeMessageAssignedEvent). Integriq owns
 * no case type, so it names none here.
 *
 * @category Service
 * @package  OCA\Integriq\Intake
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use DateTimeInterface;
use OCA\Integriq\Event\IntakeMessageAssignedEvent;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Links an inbound message to a case a handler picked.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
class IntakeAssignmentService implements IntakeAssignmentServiceInterface {

	/**
	 * A handler linked the message to a case.
	 */
	public const STATUS_ASSIGNED = 'assigned';

	/**
	 * The states a handler may assign from: a message waiting for a human,
	 * or one a rule routed to the wrong case. A message that was never
	 * stored whole (received), failed or was refused is not one to work.
	 */
	public const ASSIGNABLE_STATES = [
		IntakeRoutingService::STATUS_HELD,
		IntakeRoutingService::STATUS_ROUTED,
		self::STATUS_ASSIGNED,
	];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService  $objectService   OpenRegister object service, read and written as the handler.
	 * @param IEventDispatcher $eventDispatcher Dispatches the hand-off to the app that owns the case.
	 * @param ITimeFactory     $timeFactory     The clock the assignment is stamped with.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Link one stored inbound message to a case a handler picked.
	 *
	 * @param string               $messageUuid The `intake_message` uuid.
	 * @param array<string,string> $assignment  `caseRef` (the case) and `assignedBy` (the handler's user id).
	 *
	 * @return array<string,mixed> `id`, `status`, `targetRef`, `assignedBy`, `assignedAt` and `attachmentsLinked`.
	 *
	 * @throws IntakeChannelException When the message is unknown.
	 * @throws IntakeRoutingException When no case is named, or the message cannot be assigned in its state.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function assign(string $messageUuid, array $assignment): array {
		$caseRef = trim((string)($assignment['caseRef'] ?? ''));
		if ($caseRef === '') {
			throw new IntakeRoutingException(message: 'Pick the case to assign the message to.');
		}

		$object = $this->findMessage(messageUuid: $messageUuid)->getObject();
		$status = (string)($object['status'] ?? '');
		if (in_array($status, self::ASSIGNABLE_STATES, true) === false) {
			throw new IntakeRoutingException(
				message: 'A message that is "' . $status . '" cannot be assigned to a case.'
			);
		}

		$object['status'] = self::STATUS_ASSIGNED;
		$object['targetRef'] = $caseRef;
		$object['reason'] = '';
		$object['assignedBy'] = (string)($assignment['assignedBy'] ?? '');
		$object['assignedAt'] = $this->timeFactory->getDateTime()->format(DateTimeInterface::ATOM);

		$stored = $this->objectService->saveObject(
			object: $object,
			register: IntakeRoutingServiceInterface::REGISTER,
			schema: IntakeRoutingServiceInterface::SCHEMA_MESSAGE,
			uuid: $messageUuid,
		);

		$event = new IntakeMessageAssignedEvent(message: $object, caseRef: $caseRef, messageUuid: $messageUuid);
		$this->eventDispatcher->dispatchTyped($event);

		return [
			'id' => (string)$stored->getUuid(),
			'status' => self::STATUS_ASSIGNED,
			'targetRef' => $caseRef,
			'assignedBy' => $object['assignedBy'],
			'assignedAt' => $object['assignedAt'],
			'attachmentsLinked' => $event->isLinked(),
		];
	}//end assign()

	/**
	 * Read the message as the handler.
	 *
	 * @param string $messageUuid The `intake_message` uuid.
	 *
	 * @return ObjectEntity The stored message.
	 *
	 * @throws IntakeChannelException When there is none the handler may read.
	 */
	private function findMessage(string $messageUuid): ObjectEntity {
		try {
			$stored = $this->objectService->find(
				id: $messageUuid,
				register: IntakeRoutingServiceInterface::REGISTER,
				schema: IntakeRoutingServiceInterface::SCHEMA_MESSAGE,
			);
		} catch (DoesNotExistException) {
			throw new IntakeChannelException(message: 'No inbound message "' . $messageUuid . '".');
		}

		if (($stored instanceof ObjectEntity) === false) {
			throw new IntakeChannelException(message: 'No inbound message "' . $messageUuid . '".');
		}

		return $stored;
	}//end findMessage()
}//end class
