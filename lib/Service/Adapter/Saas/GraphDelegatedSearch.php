<?php

/**
 * Microsoft Graph mail and chat search: the request, the answer and the fetch.
 *
 * The pure half of the Microsoft 365 search over the entity types Graph only
 * searches with the person's own delegated grant (connectors-graph-document-search
 * D2): mail messages and chat messages. One `/search/query` request per type,
 * without a region (a delegated search runs in the person's own tenant), how
 * its answer becomes REQ-DCC-004 hits, how a mail or chat handle becomes a
 * Graph path, and how a fetched mail or chat message becomes a file. The
 * adapter does the brokered calls with the person's own credential.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Builds the delegated Graph search requests and reads their answers.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
final class GraphDelegatedSearch {

	/**
	 * The entity types searched with the person's own grant.
	 */
	public const ENTITY_TYPES = ['message', 'chatMessage'];

	/**
	 * The KQL date property per entity type.
	 */
	private const DATE_PROPERTY = ['message' => 'received', 'chatMessage' => 'sent'];

	/**
	 * The asked delegated types, in search order.
	 *
	 * @param array<int,string> $entityTypes The asked types; empty for all.
	 *
	 * @return list<string> The delegated types to search.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function types(array $entityTypes): array {
		if ($entityTypes === []) {
			return self::ENTITY_TYPES;
		}

		return array_values(array_intersect(self::ENTITY_TYPES, $entityTypes));
	}//end types()

	/**
	 * The `/search/query` body for one delegated entity type.
	 *
	 * @param string           $entityType `message` or `chatMessage`.
	 * @param DriveSearchQuery $query      The terms, dates and limit; the region is not sent.
	 *
	 * @return string The JSON body.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function requestBody(string $entityType, DriveSearchQuery $query): string {
		$filters = '';
		$property = (self::DATE_PROPERTY[$entityType] ?? 'received');
		foreach (['>=' => $query->from, '<=' => $query->to] as $operator => $date) {
			if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
				$filters .= ' ' . $property . $operator . $date;
			}
		}

		$request = [
			'entityTypes' => [$entityType],
			'query' => ['queryString' => trim($query->terms) . $filters],
			'from' => 0,
			'size' => max(1, min(500, $query->limit)),
		];

		return (string)json_encode(['requests' => [$request]]);
	}//end requestBody()

	/**
	 * A brokered Graph answer for one delegated type as hits and the total.
	 *
	 * @param array<string, mixed>|null $response   The broker's answer, null when it refused.
	 * @param string                    $entityType The type searched.
	 * @param string                    $sourceSlug The adapter id.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, total: int, notice: string|null} The notice is
	 *         `not-permitted` for a refusal, `search-failed` for another failure.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function answer(?array $response, string $entityType, string $sourceSlug): array {
		$status = (int)($response['status'] ?? 403);
		if ($status === 401 || $status === 403) {
			return ['hits' => [], 'total' => 0, 'notice' => 'not-permitted'];
		}

		if ($status < 200 || $status >= 300) {
			return ['hits' => [], 'total' => 0, 'notice' => 'search-failed'];
		}

		$decoded = json_decode((string)($response['body'] ?? ''), true);
		$container = [];
		if (is_array($decoded) === true) {
			$container = (array)($decoded['value'][0]['hitsContainers'][0] ?? []);
		}

		$hits = [];
		foreach ((array)($container['hits'] ?? []) as $hit) {
			if (is_array($hit) === false) {
				continue;
			}

			$envelope = $this->hit(hit: $hit, entityType: $entityType, sourceSlug: $sourceSlug);
			if ($envelope !== null) {
				$hits[] = $envelope;
			}
		}

		return ['hits' => $hits, 'total' => max(count($hits), (int)($container['total'] ?? 0)), 'notice' => null];
	}//end answer()

	/**
	 * The Graph paths a mail or chat handle names, or null.
	 *
	 * A mail handle `message:{id}` gives the message and its MIME content; a
	 * chat handle `chatMessage:{chatId}:{id}` gives the chat message twice,
	 * because its content is the message itself. The ids in a handle are
	 * URL-encoded, because a Teams chat id contains colons.
	 *
	 * @param string $handle The hit's `remoteId`.
	 *
	 * @return array{entityType: string, meta: string, content: string}|null The paths.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function paths(string $handle): ?array {
		$parts = array_map('rawurldecode', explode(':', $handle));
		if (count($parts) === 2 && $parts[0] === 'message' && self::isSegment(value: $parts[1]) === true) {
			$message = '/v1.0/me/messages/' . rawurlencode($parts[1]);
			return ['entityType' => 'message', 'meta' => $message . '?$select=subject', 'content' => $message . '/$value'];
		}

		$isChat = (count($parts) === 3 && $parts[0] === 'chatMessage');
		if ($isChat === true && self::isSegment(value: $parts[1]) === true && self::isSegment(value: $parts[2]) === true) {
			$message = '/v1.0/chats/' . rawurlencode($parts[1]) . '/messages/' . rawurlencode($parts[2]);
			return ['entityType' => 'chatMessage', 'meta' => $message, 'content' => $message];
		}

		return null;
	}//end paths()

	/**
	 * A fetched mail or chat message as a file, or null.
	 *
	 * Mail is the message's MIME source (`.eml`); a chat message is its Graph
	 * JSON, so the sender, time and body travel together.
	 *
	 * @param string                    $entityType `message` or `chatMessage`.
	 * @param array<string, mixed>|null $meta       The message's metadata answer.
	 * @param array<string, mixed>|null $content    The message's content answer.
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function file(string $entityType, ?array $meta, ?array $content): ?array {
		if ((int)($meta['status'] ?? 0) !== 200 || (int)($content['status'] ?? 0) !== 200) {
			return null;
		}

		$decoded = json_decode((string)($meta['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			return null;
		}

		if ($entityType === 'message') {
			return [
				'fileName' => self::fileName(name: (string)($decoded['subject'] ?? ''), fallback: 'message') . '.eml',
				'mimeType' => 'message/rfc822',
				'content' => (string)($content['body'] ?? ''),
			];
		}

		return [
			'fileName' => self::fileName(name: 'chat-message-' . (string)($decoded['id'] ?? ''), fallback: 'chat-message') . '.json',
			'mimeType' => 'application/json',
			'content' => (string)($content['body'] ?? ''),
		];
	}//end file()

	/**
	 * One Graph mail or chat hit as a REQ-DCC-004 envelope, or null without a handle.
	 *
	 * @param array<string, mixed> $hit        The Graph hit.
	 * @param string               $entityType The type searched.
	 * @param string               $sourceSlug The adapter id.
	 *
	 * @return array<string, mixed>|null The envelope with `entityType`.
	 */
	private function hit(array $hit, string $entityType, string $sourceSlug): ?array {
		$resource = (array)($hit['resource'] ?? []);
		$id = (string)($resource['id'] ?? ($hit['hitId'] ?? ''));
		$from = (array)($resource['from']['emailAddress'] ?? []);
		$sender = ($from['name'] ?? ($from['address'] ?? ($resource['from']['user']['displayName'] ?? null)));
		$snippet = trim(strip_tags((string)($hit['summary'] ?? ($resource['bodyPreview'] ?? ''))));

		if ($entityType === 'message') {
			if (self::isSegment(value: $id) === false) {
				return null;
			}

			return [
				'sourceSlug' => $sourceSlug,
				'remoteId' => 'message:' . rawurlencode($id),
				'title' => (string)($resource['subject'] ?? ''),
				'mimeType' => 'message/rfc822',
				'sizeBytes' => null,
				'modifiedAt' => (string)($resource['receivedDateTime'] ?? ($resource['sentDateTime'] ?? '')),
				'modifiedByLabel' => $sender,
				'path' => 'Mail',
				'previewUrl' => ($resource['webLink'] ?? null),
				'snippet' => $snippet,
				'score' => null,
				'entityType' => 'message',
			];
		}

		$chatId = (string)($resource['chatId'] ?? '');
		if (self::isSegment(value: $chatId) === false || self::isSegment(value: $id) === false) {
			return null;
		}

		$title = 'Teams';
		if ($snippet !== '') {
			$title = mb_substr($snippet, 0, 80);
		}

		return [
			'sourceSlug' => $sourceSlug,
			'remoteId' => 'chatMessage:' . rawurlencode($chatId) . ':' . rawurlencode($id),
			'title' => $title,
			'mimeType' => 'application/json',
			'sizeBytes' => null,
			'modifiedAt' => (string)($resource['createdDateTime'] ?? ''),
			'modifiedByLabel' => $sender,
			'path' => 'Teams',
			'previewUrl' => ($resource['webLink'] ?? null),
			'snippet' => $snippet,
			'score' => null,
			'entityType' => 'chatMessage',
		];
	}//end hit()

	/**
	 * Whether a value can stand as one Graph path segment.
	 *
	 * Dots survive rawurlencode, so `.` and `..` would climb out of the path
	 * once Graph normalises it.
	 *
	 * @param string $value The id.
	 *
	 * @return bool True when it can.
	 */
	private static function isSegment(string $value): bool {
		return $value !== '' && in_array($value, ['.', '..'], true) === false;
	}//end isSegment()

	/**
	 * A safe file name stem.
	 *
	 * @param string $name     The wanted name.
	 * @param string $fallback The stem when nothing usable is left.
	 *
	 * @return string The stem.
	 */
	private static function fileName(string $name, string $fallback): string {
		$stem = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', ' ', $name)));
		$stem = trim($stem, " .");
		if ($stem === '') {
			return $fallback;
		}

		return mb_substr($stem, 0, 120);
	}//end fileName()
}//end class
