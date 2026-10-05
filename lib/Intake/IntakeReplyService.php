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
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Outbound\OutboundSendGate;
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
	 * @param OutboundSendGate $gate Asks the opt-out list and keeps the outbound log row.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IntakeChannelRegistry $registry,
		private readonly OutboundSendGate $gate,
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
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-direct-reply-to-a-citizen-s-message-passes-an-opt-out-req-ooa-009
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

		// Opt-out-before-send: a direct answer to the citizen's own message is
		// asked as `reply` with the message as `inReplyTo`. An opt-out does not
		// stop it (Ruben, 2026-10-05); only an unusable address or an
		// unreadable list can, and it carries no unsubscribe link.
		[$channel, $address] = $this->recipientOf(message: $message);
		$gateOptions = ['sourceApp' => 'integriq', 'correlationId' => $messageUuid, 'inReplyTo' => $messageUuid];
		$decision = $this->gate->check(
			channel: $channel,
			category: OptOutCategories::REPLY,
			address: $address,
			options: $gateOptions
		);
		if ($decision['send'] !== true) {
			$this->gate->recordRefusal(channel: $channel, subjectRef: $messageUuid, decision: $decision, options: $gateOptions);
			$result = ReplyResult::failed($message->getChannelId(), (string)$decision['code'] . ': ' . (string)$decision['reason']);
			$this->record(stored: $stored, object: $object, text: $text, result: $result);
			return $result;
		}

		$composed = $this->gate->compose(body: $text, decision: $decision, channel: $channel);
		$logRow = $this->gate->open(
			channel: $channel,
			subjectRef: $messageUuid,
			subject: '',
			body: $composed['body'],
			address: (string)$decision['address'],
			options: $gateOptions
		);

		$result = $adapter->reply($message, $composed['body']);
		$this->record(stored: $stored, object: $object, text: $text, result: $result);
		if ($result->getStatus() === ReplyResult::STATUS_SENT) {
			$this->gate->handedOver(uuid: $logRow, address: (string)$decision['address'], reference: $result->getReference());
			return $result;
		}

		$this->gate->failed(uuid: $logRow, address: (string)$decision['address'], step: OutboundSendGate::STEP_SEND, reason: (string)$result->getDetail());

		return $result;

	}//end reply()

	/**
	 * The channel and address a reply goes to, as the opt-out list keys them.
	 *
	 * @param InboundMessage $message The message replied to.
	 *
	 * @return array{0:string,1:string} The channel and the address.
	 */
	private function recipientOf(InboundMessage $message): array {
		$correspondent = $message->getCorrespondent();
		$email = trim((string)($correspondent['address'] ?? ''));
		$phone = trim((string)($correspondent['phone'] ?? ''));

		if ($message->getChannelId() === RecipientKey::CHANNEL_MESSAGING) {
			return [RecipientKey::CHANNEL_MESSAGING, $phone];
		}

		if ($message->getChannelId() === RecipientKey::CHANNEL_TEAMS) {
			return [RecipientKey::CHANNEL_TEAMS, trim((string)($correspondent['id'] ?? ''))];
		}

		if ($email !== '') {
			return [RecipientKey::CHANNEL_EMAIL, $email];
		}

		return [RecipientKey::CHANNEL_SMS, $phone];

	}//end recipientOf()

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

		// Nulls become empty strings: the intake_message schema types every
		// reply field as a string and OpenRegister refuses null, so a sent
		// reply (no detail) or one without a channel reference would 500
		// after it had already left.
		$replies[] = array_merge(
			array_map(static fn ($value) => ($value ?? ''), $result->toArray()),
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
