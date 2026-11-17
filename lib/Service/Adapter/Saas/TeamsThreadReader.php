<?php

/**
 * Integriq Teams thread reader.
 *
 * Reads the conversation around a Teams chat or channel message through
 * Microsoft Graph (connectors-graph-document-search-chat-threads, design D4).
 * The transport is handed in, so every call runs with the grant the caller
 * chose: the requester's delegated grant, never the application grant.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
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

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Builds the `thread` of a Teams `chatMessage` hit.
 *
 * Handles (each id `rawurlencode`d, since Teams ids carry colons):
 *   `chatMessage:teams:channel:{teamId}:{channelId}:{rootId}:{messageId}`
 *   `chatMessage:teams:chat:{chatId}:{messageId}`
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */
class TeamsThreadReader {

	/**
	 * The prefix of every Teams chat handle.
	 */
	public const HANDLE_PREFIX = 'chatMessage:teams:';

	/**
	 * At most this many message pages are read for one thread.
	 */
	public const PAGE_CAP = 4;

	/**
	 * Messages asked for per page.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * The only origin a Graph `@odata.nextLink` is followed to.
	 */
	private const GRAPH_ORIGIN = 'https://graph.microsoft.com';

	/**
	 * The ids each handle kind carries after its kind segment, in handle order.
	 */
	private const HANDLE_SHAPES = [
		'channel' => ['teamId', 'channelId', 'rootId', 'messageId'],
		'chat'    => ['chatId', 'messageId'],
	];

	/**
	 * Whether a handle names a Teams chat message.
	 *
	 * @param string $handle The handle.
	 *
	 * @return bool True for a Teams chat handle.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function isTeamsHandle(string $handle): bool {
		return str_starts_with($handle, self::HANDLE_PREFIX);
	}//end isTeamsHandle()

	/**
	 * The thread around the message a handle names.
	 *
	 * @param string   $handle  The hit's handle.
	 * @param int      $context Messages to read on each side of the hit.
	 * @param callable $get     `fn (string $path): ?array{status: int, body: string}`, with the requester's grant.
	 *
	 * @return array<string,mixed>|null `['thread' => …]`, `['error' => code]`, or null for an unknown handle or message.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function read(string $handle, int $context, callable $get): ?array {
		$parts = $this->parse(handle: $handle);
		if ($parts === null) {
			return null;
		}

		if ($parts['kind'] === 'channel') {
			return $this->channel(parts: $parts, context: $context, get: $get);
		}

		return $this->chat(parts: $parts, context: $context, get: $get);
	}//end read()

	/**
	 * Split a handle into its decoded ids.
	 *
	 * @param string $handle The handle.
	 *
	 * @return array<string,string>|null The ids, or null when the handle is not a well-formed Teams handle.
	 */
	private function parse(string $handle): ?array {
		if ($this->isTeamsHandle(handle: $handle) === false) {
			return null;
		}

		$segments = array_map('rawurldecode', explode(':', substr($handle, strlen(self::HANDLE_PREFIX))));
		$shape = self::HANDLE_SHAPES[$segments[0]] ?? [];
		if (in_array('', $segments, true) === true || $shape === [] || count($segments) !== count($shape) + 1) {
			return null;
		}

		return ['kind' => $segments[0]] + array_combine($shape, array_slice($segments, 1));
	}//end parse()

	/**
	 * A channel message's thread: the root post, its replies and the channel's members.
	 *
	 * @param array<string,string> $parts   The decoded ids.
	 * @param int                  $context Messages on each side.
	 * @param callable             $get     The transport.
	 *
	 * @return array<string,mixed>|null The thread, an error, or null.
	 */
	private function channel(array $parts, int $context, callable $get): ?array {
		$base = '/v1.0/teams/' . rawurlencode($parts['teamId']) . '/channels/' . rawurlencode($parts['channelId']);
		$channel = $this->json(response: $get($base . '?$select=id,displayName'));
		$root = $this->json(response: $get($base . '/messages/' . rawurlencode($parts['rootId'])));
		$members = $this->json(response: $get($base . '/members'));
		$error = $this->firstError(answers: [$channel, $root, $members]);
		if ($error !== null) {
			return $error;
		}

		$replies = $this->pages(first: $base . '/messages/' . rawurlencode($parts['rootId']) . '/replies?$top=' . self::PAGE_SIZE, get: $get, stop: null);
		if (isset($replies['error']) === true) {
			return $replies;
		}

		return $this->thread(
			conversation: ['id' => $parts['channelId'], 'name' => (string)($channel['displayName'] ?? ''), 'kind' => 'channel'],
			members: $members,
			messages: $this->window(raw: array_merge([$root], $replies['value']), hitId: $parts['messageId'], context: $context)
		);
	}//end channel()

	/**
	 * A chat message's thread: the chat's messages around it, newest first, paged at most PAGE_CAP times.
	 *
	 * @param array<string,string> $parts   The decoded ids.
	 * @param int                  $context Messages on each side.
	 * @param callable             $get     The transport.
	 *
	 * @return array<string,mixed>|null The thread, an error, or null.
	 */
	private function chat(array $parts, int $context, callable $get): ?array {
		$base = '/v1.0/chats/' . rawurlencode($parts['chatId']);
		$chat = $this->json(response: $get($base . '?$select=id,topic,chatType'));
		$members = $this->json(response: $get($base . '/members'));
		$error = $this->firstError(answers: [$chat, $members]);
		if ($error !== null) {
			return $error;
		}

		$hitId = $parts['messageId'];
		$page = $this->pages(
			first: $base . '/messages?$top=' . self::PAGE_SIZE . '&$orderby=createdDateTime%20desc',
			get: $get,
			stop: $this->stopOnceContextRead(hitId: $hitId, context: $context)
		);
		if (isset($page['error']) === true) {
			return $page;
		}

		return $this->thread(
			conversation: [
				'id' => $parts['chatId'],
				'name' => (string)($chat['topic'] ?? ''),
				'kind' => $this->chatKind(chatType: (string)($chat['chatType'] ?? '')),
			],
			members: $members,
			messages: $this->window(raw: $page['value'], hitId: $hitId, context: $context)
		);
	}//end chat()

	/**
	 * The paging stop for a newest-first chat: once the hit is seen, `context`
	 * kept messages after it in the list are the older side.
	 *
	 * @param string $hitId   The hit's message id.
	 * @param int    $context Messages on each side.
	 *
	 * @return callable `fn (array $seen): bool`, true when enough was read.
	 */
	private function stopOnceContextRead(string $hitId, int $context): callable {
		return function (array $seen) use ($hitId, $context): bool {
			$kept = array_values(array_filter($seen, [$this, 'isKept']));
			$ids = array_map(static fn (array $message): string => (string)($message['id'] ?? ''), $kept);
			$position = array_search($hitId, $ids, true);

			return $position !== false && (count($kept) - 1 - $position) >= $context;
		};
	}//end stopOnceContextRead()

	/**
	 * The first error answer among several Graph answers.
	 *
	 * @param array<int,array<string,mixed>> $answers The decoded answers, in the order they were asked.
	 *
	 * @return array<string,mixed>|null The first `['error' => code]` answer, or null when none failed.
	 */
	private function firstError(array $answers): ?array {
		foreach ($answers as $answer) {
			if (isset($answer['error']) === true) {
				return $answer;
			}
		}

		return null;
	}//end firstError()

	/**
	 * A `['thread' => …]` answer, or null when the hit was not among the messages.
	 *
	 * @param array<string,string>                $conversation The conversation's id, name and kind.
	 * @param array<string,mixed>                 $members      The decoded members answer.
	 * @param array<int,array<string,mixed>>|null $messages     The windowed messages, or null.
	 *
	 * @return array<string,mixed>|null The thread, or null.
	 */
	private function thread(array $conversation, array $members, ?array $messages): ?array {
		if ($messages === null) {
			return null;
		}

		return ['thread' => [
			'platform' => 'microsoft-teams',
			'conversation' => $conversation,
			'participants' => $this->participants(members: $members),
			'messages' => $messages,
		]];
	}//end thread()

	/**
	 * Read a paged collection, following Graph's next link, at most PAGE_CAP pages.
	 *
	 * @param string        $first The first page's path.
	 * @param callable      $get   The transport.
	 * @param callable|null $stop  `fn (array $seen): bool`, true when enough was read.
	 *
	 * @return array{value?: array<int,array<string,mixed>>, error?: string} The messages read, or an error.
	 */
	private function pages(string $first, callable $get, ?callable $stop): array {
		$seen = [];
		$path = $first;
		for ($page = 0; $page < self::PAGE_CAP && $path !== null; $page++) {
			$answer = $this->json(response: $get($path));
			if (isset($answer['error']) === true) {
				return $answer;
			}

			$seen = array_merge($seen, array_values(array_filter((array)($answer['value'] ?? []), 'is_array')));
			if ($stop !== null && $stop($seen) === true) {
				break;
			}

			$path = $this->nextPath(next: $answer['@odata.nextLink'] ?? null);
		}

		return ['value' => $seen];
	}//end pages()

	/**
	 * The path of a Graph next link, only when it points back at Graph.
	 *
	 * @param mixed $next The `@odata.nextLink` value.
	 *
	 * @return string|null The path, or null to stop paging.
	 */
	private function nextPath(mixed $next): ?string {
		if (is_string($next) === false || str_starts_with($next, self::GRAPH_ORIGIN . '/') === false) {
			return null;
		}

		return substr($next, strlen(self::GRAPH_ORIGIN));
	}//end nextPath()

	/**
	 * Decode a Graph answer, or name why it cannot be used.
	 *
	 * @param array<string,mixed>|null $response The broker's answer.
	 *
	 * @return array<string,mixed> The decoded body, or `['error' => code]`.
	 */
	private function json(?array $response): array {
		$status = (int)($response['status'] ?? 0);
		if (in_array($status, [401, 403], true) === true) {
			return ['error' => 'not-permitted'];
		}

		$decoded = null;
		if ($status >= 200 && $status < 300) {
			$decoded = json_decode((string)($response['body'] ?? ''), true);
		}

		if (is_array($decoded) === false) {
			return ['error' => 'fetch-failed'];
		}

		return $decoded;
	}//end json()

	/**
	 * Whether a Graph message belongs in a thread: a real message, not deleted.
	 *
	 * @param array<string,mixed> $message The Graph chatMessage.
	 *
	 * @return bool True when kept.
	 */
	private function isKept(array $message): bool {
		return ($message['messageType'] ?? null) === 'message' && empty($message['deletedDateTime']) === true;
	}//end isKept()

	/**
	 * The kept messages in time order, cut to `context` on each side of the hit.
	 *
	 * @param array<int,mixed> $raw     The Graph messages, any order.
	 * @param string           $hitId   The hit's message id.
	 * @param int              $context Messages on each side.
	 *
	 * @return array<int,array<string,mixed>>|null The messages, or null when the hit is not among them.
	 */
	private function window(array $raw, string $hitId, int $context): ?array {
		$kept = array_values(array_filter($raw, fn (mixed $message): bool => is_array($message) === true && $this->isKept(message: $message)));
		usort(
			$kept,
			static fn (array $left, array $right): int => strcmp((string)($left['createdDateTime'] ?? ''), (string)($right['createdDateTime'] ?? ''))
		);
		$ids = array_map(static fn (array $message): string => (string)($message['id'] ?? ''), $kept);
		$position = array_search($hitId, $ids, true);
		if ($position === false) {
			return null;
		}

		$start = max(0, $position - $context);
		$slice = array_slice($kept, $start, ($position - $start) + $context + 1);

		return array_map(fn (array $message): array => $this->message(message: $message, hitId: $hitId), $slice);
	}//end window()

	/**
	 * One thread message from a Graph chatMessage.
	 *
	 * @param array<string,mixed> $message The Graph chatMessage.
	 * @param string              $hitId   The hit's message id.
	 *
	 * @return array{id: string, at: string, actorLabel: string, text: string, isHit: bool} The message.
	 */
	private function message(array $message, string $hitId): array {
		$from = (array)($message['from'] ?? []);
		$body = (array)($message['body'] ?? []);
		$text = (string)($body['content'] ?? '');
		if (strtolower((string)($body['contentType'] ?? 'text')) === 'html') {
			$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$label = ($from['user']['displayName'] ?? ($from['application']['displayName'] ?? ''));

		return [
			'id' => (string)($message['id'] ?? ''),
			'at' => (string)($message['createdDateTime'] ?? ''),
			'actorLabel' => (string)$label,
			'text' => trim($text),
			'isHit' => (string)($message['id'] ?? '') === $hitId,
		];
	}//end message()

	/**
	 * The participants from a Graph members answer.
	 *
	 * @param array<string,mixed> $members The decoded members answer.
	 *
	 * @return array<int,array{id: string, label: string}> The participants.
	 */
	private function participants(array $members): array {
		$participants = [];
		foreach ((array)($members['value'] ?? []) as $member) {
			if (is_array($member) === false) {
				continue;
			}

			$participants[] = [
				'id' => (string)($member['userId'] ?? ($member['id'] ?? '')),
				'label' => (string)($member['displayName'] ?? ''),
			];
		}

		return $participants;
	}//end participants()

	/**
	 * The thread kind of a Graph chat type.
	 *
	 * @param string $chatType Graph's `chatType`.
	 *
	 * @return string `one-to-one` or `group`.
	 */
	private function chatKind(string $chatType): string {
		if ($chatType === 'oneOnOne') {
			return 'one-to-one';
		}

		return 'group';
	}//end chatKind()
}//end class
