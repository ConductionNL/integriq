<?php

/**
 * GraphDelegatedSearch: the mail and chat half of the Microsoft 365 document search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Builds the Graph search request for mail and chat and reads its answer.
 *
 * Mail and chat are searched with the person's own delegated grant (design
 * D2), in one `/search/query` request for both types (D1: once per grant
 * kind). A message hit's handle is `message:{id}`; a Teams hit's handle is the
 * one TeamsThreadReader reads, so a fetched chat hit comes with its thread.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
final class GraphDelegatedSearch {

	/**
	 * The entity types Graph only searches with the person's own delegated grant.
	 */
	public const ENTITY_TYPES = ['message', 'chatMessage'];

	/**
	 * The delegated types among the asked ones, in search order.
	 *
	 * @param array<int,string> $entityTypes The asked types; empty for all.
	 *
	 * @return array<int,string> The delegated types to search.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function typesOf(array $entityTypes): array {
		if ($entityTypes === []) {
			return self::ENTITY_TYPES;
		}

		return array_values(array_intersect(self::ENTITY_TYPES, $entityTypes));
	}//end typesOf()

	/**
	 * The `/search/query` body for mail and chat.
	 *
	 * @param DriveSearchQuery  $query The terms, dates and limit; the region is not sent, a delegated search needs none.
	 * @param array<int,string> $types The delegated types to search.
	 *
	 * @return string The JSON body.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function requestBody(DriveSearchQuery $query, array $types): string {
		$request = [
			'entityTypes' => array_values($types),
			'query' => ['queryString' => trim($query->terms) . $this->dateFilters(query: $query)],
			'from' => 0,
			'size' => max(1, min(500, $query->limit)),
		];

		return (string)json_encode(['requests' => [$request]]);
	}//end requestBody()

	/**
	 * A brokered Graph answer as hits, the count of the rest, and notices.
	 *
	 * @param array<string, mixed>|null $response   The broker's answer, null when it refused.
	 * @param string                    $sourceSlug The adapter id.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, total: int, notice: string|null} The notice is
	 *         `not-permitted` or `search-failed` when the search did not answer.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function answer(?array $response, string $sourceSlug): array {
		$status = (int)($response['status'] ?? 403);
		if ($status === 401 || $status === 403) {
			return ['hits' => [], 'total' => 0, 'notice' => 'not-permitted'];
		}

		if ($status < 200 || $status >= 300) {
			return ['hits' => [], 'total' => 0, 'notice' => 'search-failed'];
		}

		$decoded    = json_decode((string)($response['body'] ?? ''), true);
		$containers = [];
		if (is_array($decoded) === true) {
			$containers = (array)($decoded['value'][0]['hitsContainers'] ?? []);
		}

		$hits  = [];
		$total = 0;
		foreach (array_filter($containers, 'is_array') as $container) {
			$rows   = array_values(array_filter((array)($container['hits'] ?? []), 'is_array'));
			$total += max(count($rows), (int)($container['total'] ?? 0));
			$hits   = array_merge($hits, $this->hitsOf(rows: $rows, sourceSlug: $sourceSlug));
		}

		return ['hits' => $hits, 'total' => max($total, count($hits)), 'notice' => null];
	}//end answer()

	/**
	 * The envelopes of the mail and chat rows; any other resource is left out.
	 *
	 * @param array<int, array<string, mixed>> $rows       The Graph hits.
	 * @param string                           $sourceSlug The adapter id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function hitsOf(array $rows, string $sourceSlug): array {
		$hits = [];
		foreach ($rows as $row) {
			$hit = $this->hit(hit: $row, sourceSlug: $sourceSlug);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}//end hitsOf()

	/**
	 * The received/created date filters for each Y-m-d bound; any other shape is left out.
	 *
	 * @param DriveSearchQuery $query The search.
	 *
	 * @return string The filters, each with a leading space.
	 */
	private function dateFilters(DriveSearchQuery $query): string {
		$filters = '';
		foreach (['>=' => $query->from, '<=' => $query->to] as $operator => $date) {
			if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
				$filters .= ' received' . $operator . $date;
			}
		}

		return $filters;
	}//end dateFilters()

	/**
	 * One Graph mail or chat hit as a REQ-DCC-004 envelope, or null for another resource.
	 *
	 * @param array<string, mixed> $hit        The Graph hit.
	 * @param string               $sourceSlug The adapter id.
	 *
	 * @return array<string, mixed>|null The envelope with `entityType`.
	 */
	private function hit(array $hit, string $sourceSlug): ?array {
		$resource = (array)($hit['resource'] ?? []);
		$type     = (string)($resource['@odata.type'] ?? '');
		$id       = (string)($resource['id'] ?? ($hit['hitId'] ?? ''));
		if ($id === '') {
			return null;
		}

		$envelope = [
			'sourceSlug' => $sourceSlug,
			'snippet' => trim(strip_tags((string)($hit['summary'] ?? ''))),
			'sizeBytes' => null,
			'score' => null,
			'previewUrl' => ($resource['webLink'] ?? ($resource['webUrl'] ?? null)),
		];

		if ($type === '#microsoft.graph.chatMessage') {
			return $envelope + [
				'remoteId' => $this->chatHandle(resource: $resource, id: $id),
				'title' => trim(strip_tags((string)($resource['subject'] ?? ($resource['body']['content'] ?? '')))),
				'mimeType' => 'text/plain',
				'modifiedAt' => (string)($resource['lastModifiedDateTime'] ?? ($resource['createdDateTime'] ?? '')),
				'modifiedByLabel' => ($resource['from']['user']['displayName'] ?? ($resource['from']['emailAddress']['name'] ?? null)),
				'path' => $this->chatPath(resource: $resource),
				'entityType' => 'chatMessage',
			];
		}

		if ($type === '#microsoft.graph.message') {
			return $envelope + [
				'remoteId' => 'message:' . $id,
				'title' => (string)($resource['subject'] ?? ''),
				'mimeType' => 'message/rfc822',
				'modifiedAt' => (string)($resource['receivedDateTime'] ?? ($resource['lastModifiedDateTime'] ?? '')),
				'modifiedByLabel' => ($resource['from']['emailAddress']['name'] ?? ($resource['sender']['emailAddress']['name'] ?? null)),
				'path' => null,
				'entityType' => 'message',
			];
		}

		return null;
	}//end hit()

	/**
	 * The fetch handle of a Teams hit: a channel or chat handle TeamsThreadReader reads, or `chatMessage:{id}`.
	 *
	 * @param array<string, mixed> $resource The chat message resource.
	 * @param string               $id       Its id.
	 *
	 * @return string The handle.
	 */
	private function chatHandle(array $resource, string $id): string {
		$channel = (array)($resource['channelIdentity'] ?? []);
		$teamId  = (string)($channel['teamId'] ?? '');
		$channelId = (string)($channel['channelId'] ?? '');
		if ($teamId !== '' && $channelId !== '') {
			$rootId = (string)($resource['replyToId'] ?? '');
			if ($rootId === '') {
				$rootId = $id;
			}

			return TeamsThreadReader::HANDLE_PREFIX . 'channel:' . rawurlencode($teamId) . ':' . rawurlencode($channelId)
				. ':' . rawurlencode($rootId) . ':' . rawurlencode($id);
		}

		$chatId = (string)($resource['chatId'] ?? '');
		if ($chatId !== '') {
			return TeamsThreadReader::HANDLE_PREFIX . 'chat:' . rawurlencode($chatId) . ':' . rawurlencode($id);
		}

		return 'chatMessage:' . $id;
	}//end chatHandle()

	/**
	 * Where a Teams hit was said: its team and channel, or null for a chat.
	 *
	 * @param array<string, mixed> $resource The chat message resource.
	 *
	 * @return string|null The path.
	 */
	private function chatPath(array $resource): ?string {
		$channel = (array)($resource['channelIdentity'] ?? []);
		if (($channel['teamId'] ?? '') === '' || ($channel['channelId'] ?? '') === '') {
			return null;
		}

		return (string)$channel['teamId'] . '/' . (string)$channel['channelId'];
	}//end chatPath()
}//end class
