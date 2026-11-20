<?php

/**
 * Integriq Nextcloud Talk thread reader.
 *
 * Reads the conversation around a Talk hit in a room Talk already confirmed
 * the person is a participant of (connectors-graph-document-search-chat-threads,
 * design D5): the hit's own comment, the messages on each side and the
 * room's participants.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Chat
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
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Chat;

use DateTime;
use DateTimeInterface;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Builds the `thread` of a Talk `chatMessage` hit.
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */
class TalkThreadReader {

	/**
	 * Talk's room types, by the kind a thread names (Talk's Room::TYPE_* values).
	 */
	private const ROOM_KINDS = [1 => 'one-to-one', 2 => 'group', 3 => 'public', 5 => 'one-to-one'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves Talk's participant service.
	 * @param ICommentsManager   $comments  Reads the messages around a hit.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ICommentsManager $comments,
	) {
	}//end __construct()

	/**
	 * The thread around a message of a room the person is a participant of.
	 *
	 * @param object $room      Talk's Room, resolved for the person.
	 * @param string $token     The conversation token.
	 * @param string $messageId The hit's message id.
	 * @param string $userId    The person fetching.
	 * @param int    $context   Messages to read on each side of the hit.
	 *
	 * @return array<string,mixed>|null The thread, or null when the message is not a kept chat message of that room.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function read(object $room, string $token, string $messageId, string $userId, int $context): ?array {
		$hit = $this->hitComment(messageId: $messageId, roomId: (string)$room->getId());
		if ($hit === null) {
			return null;
		}

		return $this->thread(room: $room, token: $token, hit: $hit, hitId: $messageId, userId: $userId, context: $context);
	}//end read()

	/**
	 * The thread around a hit: the messages on each side in time order, and the room's participants.
	 *
	 * @param object   $room    Talk's Room.
	 * @param string   $token   The conversation token.
	 * @param IComment $hit     The hit's own comment.
	 * @param string   $hitId   The hit's message id.
	 * @param string   $userId  The person fetching.
	 * @param int      $context Messages to read on each side of the hit.
	 *
	 * @return array<string,mixed> The thread.
	 */
	private function thread(object $room, string $token, IComment $hit, string $hitId, string $userId, int $context): array {
		$roomId = (string)$room->getId();
		$labels = $this->participants(room: $room);
		$messages = array_merge(
			array_reverse($this->around(roomId: $roomId, messageId: (int)$hitId, direction: 'desc', context: $context)),
			[$hit],
			$this->around(roomId: $roomId, messageId: (int)$hitId, direction: 'asc', context: $context)
		);

		return [
			'platform' => TalkMessageSource::PLATFORM,
			'conversation' => [
				'id' => $token,
				'name' => (string)$room->getDisplayName($userId),
				'kind' => (self::ROOM_KINDS[(int)$room->getType()] ?? 'group'),
			],
			'participants' => array_values($labels),
			'messages' => array_map(
				fn (IComment $comment): array => $this->message(comment: $comment, labels: $labels, hitId: $hitId),
				$messages
			),
		];
	}//end thread()

	/**
	 * The hit's own comment, only when it is a chat message of this conversation.
	 *
	 * A hand-built handle can pair a conversation the person is in with a
	 * message of another one; that message is not read.
	 *
	 * @param string $messageId The message id.
	 * @param string $roomId    The conversation's id.
	 *
	 * @return IComment|null The comment, or null.
	 */
	private function hitComment(string $messageId, string $roomId): ?IComment {
		try {
			$comment = $this->comments->get($messageId);
		} catch (Throwable) {
			return null;
		}

		if ($this->isMessageOf(comment: $comment, roomId: $roomId) === false) {
			return null;
		}

		return $comment;
	}//end hitComment()

	/**
	 * Whether a comment is a kept chat message of this conversation.
	 *
	 * @param IComment $comment The comment.
	 * @param string   $roomId  The conversation's id.
	 *
	 * @return bool True when it belongs to the conversation and is kept.
	 */
	private function isMessageOf(IComment $comment, string $roomId): bool {
		return $comment->getObjectType() === 'chat' && (string)$comment->getObjectId() === $roomId && $this->isKept(comment: $comment) === true;
	}//end isMessageOf()

	/**
	 * The chat messages on one side of the hit, nearest first.
	 *
	 * @param string $roomId    The conversation's id.
	 * @param int    $messageId The hit's id.
	 * @param string $direction `desc` for older, `asc` for newer.
	 * @param int    $context   How many.
	 *
	 * @return array<int,IComment> The messages.
	 */
	private function around(string $roomId, int $messageId, string $direction, int $context): array {
		if ($context < 1) {
			// A limit of 0 means "all" to the comments manager.
			return [];
		}

		$found = $this->comments->getCommentsWithVerbForObjectSinceComment(
			'chat',
			$roomId,
			TalkMessageSource::MESSAGE_VERBS,
			$messageId,
			$direction,
			$context,
			false
		);

		return array_values(array_filter($found, fn (IComment $comment): bool => $this->isKept(comment: $comment)));
	}//end around()

	/**
	 * Whether a comment is a live chat message: a message verb and not expired.
	 *
	 * @param IComment $comment The comment.
	 *
	 * @return bool True when kept.
	 */
	private function isKept(IComment $comment): bool {
		if (in_array($comment->getVerb(), TalkMessageSource::MESSAGE_VERBS, true) === false) {
			return false;
		}

		$expires = $comment->getExpireDate();

		return $expires === null || $expires > new DateTime();
	}//end isKept()

	/**
	 * The conversation's participants, keyed by actor.
	 *
	 * @param object $room Talk's Room.
	 *
	 * @return array<string,array{id: string, label: string}> The participants by `actorType/actorId`.
	 */
	private function participants(object $room): array {
		$labels = [];
		try {
			$participants = $this->container->get(TalkMessageSource::PARTICIPANTS_CLASS)->getParticipantsForRoom($room);
		} catch (Throwable) {
			return [];
		}

		foreach ((array)$participants as $participant) {
			$attendee = $participant->getAttendee();
			$key = (string)$attendee->getActorType() . '/' . (string)$attendee->getActorId();
			$label = (string)$attendee->getDisplayName();
			if ($label === '') {
				$label = (string)$attendee->getActorId();
			}

			$labels[$key] = ['id' => (string)$attendee->getActorId(), 'label' => $label];
		}

		return $labels;
	}//end participants()

	/**
	 * One thread message from a Talk comment.
	 *
	 * @param IComment                                        $comment The comment.
	 * @param array<string,array{id: string, label: string}> $labels  The participants by actor.
	 * @param string                                          $hitId   The hit's id.
	 *
	 * @return array{id: string, at: string, actorLabel: string, text: string, isHit: bool} The message.
	 */
	private function message(IComment $comment, array $labels, string $hitId): array {
		$key = (string)$comment->getActorType() . '/' . (string)$comment->getActorId();
		$created = $comment->getCreationDateTime();
		$at = '';
		if ($created instanceof DateTimeInterface) {
			$at = $created->format(DATE_ATOM);
		}

		return [
			'id' => (string)$comment->getId(),
			'at' => $at,
			'actorLabel' => ($labels[$key]['label'] ?? (string)$comment->getActorId()),
			'text' => (string)$comment->getMessage(),
			'isHit' => (string)$comment->getId() === $hitId,
		];
	}//end message()
}//end class
