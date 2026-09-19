<?php

/**
 * Integriq IntakeReplyService.
 *
 * A reply leaves over the channel the message arrived on. A resident who
 * writes on a messaging service and gets an e-mail has been answered on our
 * terms, not theirs, so a channel that cannot reply says `unsupported by this
 * channel` and nothing leaves over any other channel. Every attempt is
 * recorded on the message, sent or not.
 *
 * @category Intake
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use DateTimeImmutable;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Replies to an inbound message on its own channel.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-reply-goes-back-over-the-channel-it-arrived-on-req-ic-005
 */
class IntakeReplyService {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the message and records the reply.
	 * @param IntakeChannelRegistry $registry The channels this instance has.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IntakeChannelRegistry $registry,
	) {

	}//end __construct()

	/**
	 * Reply to one stored inbound message.
	 *
	 * @param string $messageUuid The `intake_message` uuid.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult What happened.
	 *
	 * @throws IntakeChannelException When the message is unknown, or its channel is.
	 */
	public function reply(string $messageUuid, string $text): ReplyResult {
		$stored = $this->findMessage(messageUuid: $messageUuid);
		$object = $stored->getObject();
		$message = InboundMessage::fromObject($object);

		$adapter = $this->registry->get($message->getChannelId());
		if ($adapter->describe()->canReply() === false) {
			$result = ReplyResult::unsupported(
				$message->getChannelId(),
				'The "' . $message->getChannelId() . '" channel cannot carry a reply.'
			);
			$this->record(stored: $stored, object: $object, text: $text, result: $result);
			return $result;
		}

		$result = $adapter->reply($message, $text);
		$this->record(stored: $stored, object: $object, text: $text, result: $result);

		return $result;

	}//end reply()

	/**
	 * Find the stored message.
	 *
	 * @param string $messageUuid The uuid.
	 *
	 * @return ObjectEntity The message.
	 *
	 * @throws IntakeChannelException When there is no such message.
	 */
	private function findMessage(string $messageUuid): ObjectEntity {
		try {
			$stored = $this->objectService->find(
				id: $messageUuid,
				register: IntakeRoutingService::REGISTER,
				schema: IntakeRoutingService::SCHEMA_MESSAGE,
			);
		} catch (DoesNotExistException) {
			throw new IntakeChannelException(message: 'No inbound message "' . $messageUuid . '".');
		}

		if (($stored instanceof ObjectEntity) === false) {
			throw new IntakeChannelException(message: 'No inbound message "' . $messageUuid . '".');
		}

		return $stored;

	}//end findMessage()

	/**
	 * Record the attempt on the message.
	 *
	 * An attempt that did not leave is recorded too: a reply nobody can see
	 * failing is a reply everybody assumes arrived.
	 *
	 * @param ObjectEntity $stored The stored message.
	 * @param array<string,mixed> $object The message payload.
	 * @param string $text The reply text.
	 * @param ReplyResult $result What happened.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $stored, array $object, string $text, ReplyResult $result): void {
		$replies = ($object['replies'] ?? []);
		if (is_array($replies) === false) {
			$replies = [];
		}

		$replies[] = array_merge(
			$result->toArray(),
			[
				'text' => $text,
				'at' => (new DateTimeImmutable())->format('c'),
			]
		);
		$object['replies'] = $replies;

		$this->objectService->saveObject(
			object: $object,
			register: IntakeRoutingService::REGISTER,
			schema: IntakeRoutingService::SCHEMA_MESSAGE,
			uuid: (string)$stored->getUuid(),
		);

	}//end record()

}//end class
