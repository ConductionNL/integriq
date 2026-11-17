<?php

/**
 * Integriq Nextcloud Talk message source.
 *
 * Searches Talk messages as the requesting person through Talk's own unified
 * search provider, and fetches a Talk hit with its conversation after Talk
 * confirms the person is still a participant
 * (connectors-graph-document-search-chat-threads, design D5).
 *
 * The Talk calls are read on Talk 22.0.20 (nextcloud/spreed stable32), the
 * release for this app's lowest supported Nextcloud: MessageSearch::search(IUser,
 * ISearchQuery), Manager::getRoomForUserByToken(string, ?string) and
 * ParticipantService::getParticipantsForRoom(Room). Each is resolved only
 * when its class exists.
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
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Chat;

use DateTime;
use DateTimeInterface;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCP\App\IAppManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Talk as a document search source: hits and threads, always as the person who asks.
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */
class TalkMessageSource {

	/**
	 * The prefix of every Talk handle: `chatMessage:talk:{token}:{messageId}`.
	 */
	public const HANDLE_PREFIX = 'chatMessage:talk:';

	/**
	 * The platform a Talk hit and thread carry.
	 */
	public const PLATFORM = 'nextcloud-talk';

	/**
	 * Talk's app id.
	 */
	public const APP_ID = 'spreed';

	/**
	 * Talk's unified search provider for messages.
	 */
	public const SEARCH_CLASS = 'OCA\\Talk\\Search\\MessageSearch';

	/**
	 * Talk's room manager.
	 */
	public const MANAGER_CLASS = 'OCA\\Talk\\Manager';

	/**
	 * Talk's participant service.
	 */
	public const PARTICIPANTS_CLASS = 'OCA\\Talk\\Service\\ParticipantService';

	/**
	 * The comment verbs that are chat messages; system messages and deleted messages carry others.
	 */
	public const MESSAGE_VERBS = ['comment', 'object_shared'];

	/**
	 * Talk's room types, by the kind a thread names (Talk's Room::TYPE_* values).
	 */
	private const ROOM_KINDS = [1 => 'one-to-one', 2 => 'group', 3 => 'public', 5 => 'one-to-one'];

	/**
	 * Renders the thread as the fetch answer.
	 *
	 * @var ThreadTranscript
	 */
	private readonly ThreadTranscript $transcript;

	/**
	 * Turns Talk's search entries into hits.
	 *
	 * @var TalkSearchHits
	 */
	private readonly TalkSearchHits $hits;

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether Talk is enabled.
	 * @param ContainerInterface $container  Resolves Talk's classes when they exist.
	 * @param IUserManager       $users      Resolves the requesting person.
	 * @param ICommentsManager   $comments   Reads the messages around a hit.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IUserManager $users,
		private readonly ICommentsManager $comments,
	) {
		$this->transcript = new ThreadTranscript();
		$this->hits = new TalkSearchHits();
	}//end __construct()

	/**
	 * Whether Talk is installed and enabled, with the classes this source calls.
	 *
	 * @return bool True when Talk can be searched.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function isAvailable(): bool {
		foreach ([self::SEARCH_CLASS, self::MANAGER_CLASS, self::PARTICIPANTS_CLASS] as $class) {
			if ($this->isLoadable(className: $class) === false) {
				return false;
			}
		}

		return $this->appManager->isEnabledForAnyone(self::APP_ID) === true;
	}//end isAvailable()

	/**
	 * Whether a class of another app can be loaded on this instance.
	 *
	 * @param string $className The class.
	 *
	 * @return bool True when it exists.
	 */
	private function isLoadable(string $className): bool {
		return class_exists($className);
	}//end isLoadable()

	/**
	 * Whether a handle names a Talk message.
	 *
	 * @param string $handle The handle.
	 *
	 * @return bool True for a Talk handle.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function isTalkHandle(string $handle): bool {
		return str_starts_with($handle, self::HANDLE_PREFIX);
	}//end isTalkHandle()

	/**
	 * Search Talk messages as the person who asks, in the asked period.
	 *
	 * @param DocumentSearchRequestedEvent $search The search: person, terms, period and limit.
	 *
	 * @return array{hits: array<int,array<string,mixed>>, moreCount: int, notices: array<int,string>} REQ-DCC-004 hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function search(DocumentSearchRequestedEvent $search): array {
		if ($this->isAvailable() === false) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => ['talk-not-installed']];
		}

		$entries = $this->entries(search: $search);
		if ($entries === null) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => ['search-failed']];
		}

		$hits = $this->hits->fromEntries(entries: $entries, from: $search->getFrom(), to: $search->getTo());

		return ['hits' => array_slice($hits, 0, max(0, $search->getLimit())), 'moreCount' => 0, 'notices' => []];
	}//end search()

	/**
	 * Talk's own search entries for the person and terms.
	 *
	 * @param DocumentSearchRequestedEvent $search The search.
	 *
	 * @return array<int,mixed>|null The entries (none for an unknown person or empty terms), or null when Talk failed.
	 */
	private function entries(DocumentSearchRequestedEvent $search): ?array {
		$user = $this->users->get($search->getUserId());
		if ($this->isSearchable(user: $user, search: $search) === false) {
			return [];
		}

		try {
			$result = $this->container->get(self::SEARCH_CLASS)->search(
				$user,
				new IntegriqSearchQuery(term: $search->getTerms(), limit: $search->getLimit())
			);

			return array_values((array)($result->jsonSerialize()['entries'] ?? []));
		} catch (Throwable) {
			return null;
		}
	}//end entries()

	/**
	 * Whether a search can run: a known person, terms and a positive limit.
	 *
	 * @param mixed                        $user   The person, or null when unknown.
	 * @param DocumentSearchRequestedEvent $search The search.
	 *
	 * @return bool True when Talk should be asked.
	 */
	private function isSearchable(mixed $user, DocumentSearchRequestedEvent $search): bool {
		return $user !== null && trim($search->getTerms()) !== '' && $search->getLimit() >= 1;
	}//end isSearchable()

	/**
	 * Fetch a Talk hit with its conversation, as the person.
	 *
	 * Talk confirms the person is still a participant before any message is
	 * read; one who left gets `not-a-participant` and no messages.
	 *
	 * @param string $handle  A `chatMessage:talk:{token}:{messageId}` handle.
	 * @param string $userId  The person fetching.
	 * @param int    $context Messages to read on each side of the hit; the caller validated it.
	 *
	 * @return array<string,mixed>|null `{fileName, mimeType, content, thread}`, `{error}`, or null when the
	 *         handle names no message of that conversation.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function fetch(string $handle, string $userId, int $context): ?array {
		if (preg_match('/^chatMessage:talk:([A-Za-z0-9]+):([0-9]+)$/', $handle, $match) !== 1) {
			return null;
		}

		return $this->fetchFromRoom(token: $match[1], messageId: $match[2], userId: $userId, context: $context);
	}//end fetch()

	/**
	 * Fetch a Talk message with its conversation from the room the token names, as the person.
	 *
	 * @param string $token     The conversation token.
	 * @param string $messageId The message id.
	 * @param string $userId    The person fetching.
	 * @param int    $context   Messages to read on each side of the hit.
	 *
	 * @return array<string,mixed>|null `{fileName, mimeType, content, thread}`, `{error}`, or null.
	 */
	private function fetchFromRoom(string $token, string $messageId, string $userId, int $context): ?array {
		if ($this->isAvailable() === false) {
			return ['error' => 'talk-not-installed'];
		}

		try {
			$room = $this->container->get(self::MANAGER_CLASS)->getRoomForUserByToken($token, $userId);
		} catch (Throwable) {
			return ['error' => 'not-a-participant'];
		}

		$hit = $this->hitComment(messageId: $messageId, roomId: (string)$room->getId());
		if ($hit === null) {
			return null;
		}

		$thread = $this->thread(room: $room, token: $token, hit: $hit, hitId: $messageId, userId: $userId, context: $context);

		return $this->transcript->answer(thread: $thread, fetchedAt: gmdate(DATE_ATOM));
	}//end fetchFromRoom()

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
			'platform' => self::PLATFORM,
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

		$found = $this->comments->getCommentsWithVerbForObjectSinceComment('chat', $roomId, self::MESSAGE_VERBS, $messageId, $direction, $context, false);

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
		if (in_array($comment->getVerb(), self::MESSAGE_VERBS, true) === false) {
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
			$participants = $this->container->get(self::PARTICIPANTS_CLASS)->getParticipantsForRoom($room);
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
