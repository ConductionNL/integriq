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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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
	 * Search Talk messages as the person, in a period.
	 *
	 * @param string      $userId The person searching.
	 * @param string      $terms  The search terms.
	 * @param string|null $from   Start of the period, Y-m-d.
	 * @param string|null $to     End of the period, Y-m-d.
	 * @param int         $limit  At most this many hits.
	 *
	 * @return array{hits: array<int,array<string,mixed>>, moreCount: int, notices: array<int,string>} REQ-DCC-004 hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function search(string $userId, string $terms, ?string $from, ?string $to, int $limit): array {
		if ($this->isAvailable() === false) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => ['talk-not-installed']];
		}

		$user = $this->users->get($userId);
		if ($user === null || trim($terms) === '' || $limit < 1) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => []];
		}

		try {
			$provider = $this->container->get(self::SEARCH_CLASS);
			$result = $provider->search($user, new IntegriqSearchQuery(term: $terms, limit: $limit));
			$entries = (array)($result->jsonSerialize()['entries'] ?? []);
		} catch (Throwable) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => ['search-failed']];
		}

		$hits = [];
		foreach ($entries as $entry) {
			$hit = $this->hit(entry: $entry, from: $from, to: $to);
			if ($hit !== null && count($hits) < $limit) {
				$hits[] = $hit;
			}
		}

		return ['hits' => $hits, 'moreCount' => 0, 'notices' => []];
	}//end search()

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

		if ($this->isAvailable() === false) {
			return ['error' => 'talk-not-installed'];
		}

		try {
			$room = $this->container->get(self::MANAGER_CLASS)->getRoomForUserByToken($match[1], $userId);
		} catch (Throwable) {
			return ['error' => 'not-a-participant'];
		}

		$roomId = (string)$room->getId();
		$hit = $this->hitComment(messageId: $match[2], roomId: $roomId);
		if ($hit === null) {
			return null;
		}

		$labels = $this->participants(room: $room);
		$messages = array_merge(
			array_reverse($this->around(roomId: $roomId, messageId: (int)$match[2], direction: 'desc', context: $context)),
			[$hit],
			$this->around(roomId: $roomId, messageId: (int)$match[2], direction: 'asc', context: $context)
		);

		$thread = [
			'platform' => self::PLATFORM,
			'conversation' => [
				'id' => $match[1],
				'name' => (string)$room->getDisplayName($userId),
				'kind' => (self::ROOM_KINDS[(int)$room->getType()] ?? 'group'),
			],
			'participants' => array_values($labels),
			'messages' => array_map(
				fn (IComment $comment): array => $this->message(comment: $comment, labels: $labels, hitId: $match[2]),
				$messages
			),
		];

		return $this->transcript->answer(thread: $thread, fetchedAt: gmdate(DATE_ATOM));
	}//end fetch()

	/**
	 * A REQ-DCC-004 hit from one of Talk's search entries, inside the period.
	 *
	 * @param mixed       $entry The `SearchResultEntry`.
	 * @param string|null $from  Start of the period, Y-m-d.
	 * @param string|null $to    End of the period, Y-m-d.
	 *
	 * @return array<string,mixed>|null The hit, or null when outside the period or unreadable.
	 */
	private function hit(mixed $entry, ?string $from, ?string $to): ?array {
		if (($entry instanceof \JsonSerializable) === false) {
			return null;
		}

		$data = (array)$entry->jsonSerialize();
		$attributes = (array)($data['attributes'] ?? []);
		$token = (string)($attributes['conversation'] ?? '');
		$messageId = (string)($attributes['messageId'] ?? '');
		$timestamp = (int)($attributes['timestamp'] ?? 0);
		if ($token === '' || $messageId === '' || $this->inPeriod(timestamp: $timestamp, from: $from, to: $to) === false) {
			return null;
		}

		return [
			'sourceSlug' => self::PLATFORM,
			'remoteId' => self::HANDLE_PREFIX . $token . ':' . $messageId,
			'title' => (string)($data['title'] ?? ''),
			'mimeType' => 'text/plain',
			'sizeBytes' => null,
			'modifiedAt' => gmdate(DATE_ATOM, $timestamp),
			'modifiedByLabel' => (string)($attributes['actorId'] ?? ''),
			'path' => null,
			'previewUrl' => (string)($data['resourceUrl'] ?? ''),
			'snippet' => (string)($data['subline'] ?? ''),
			'score' => null,
			'entityType' => 'chatMessage',
			'platform' => self::PLATFORM,
			'conversationId' => $token,
		];
	}//end hit()

	/**
	 * Whether a message time falls inside the period (both ends whole days, UTC).
	 *
	 * @param int         $timestamp The message time.
	 * @param string|null $from      Start of the period, Y-m-d.
	 * @param string|null $to        End of the period, Y-m-d.
	 *
	 * @return bool True when inside.
	 */
	private function inPeriod(int $timestamp, ?string $from, ?string $to): bool {
		$utc = new DateTimeZone('UTC');
		if ($from !== null && $from !== '') {
			$start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
			if ($start !== false && $timestamp < $start->getTimestamp()) {
				return false;
			}
		}

		if ($to !== null && $to !== '') {
			$end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);
			if ($end !== false && $timestamp >= $end->modify('+1 day')->getTimestamp()) {
				return false;
			}
		}

		return true;
	}//end inPeriod()

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

		if ($comment->getObjectType() !== 'chat' || (string)$comment->getObjectId() !== $roomId || $this->isKept(comment: $comment) === false) {
			return null;
		}

		return $comment;
	}//end hitComment()

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

		return $expires === null || $expires > new \DateTime();
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
