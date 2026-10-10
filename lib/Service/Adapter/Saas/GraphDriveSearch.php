<?php

/**
 * Microsoft Graph drive search: the request, the answer and the fetch.
 *
 * The pure half of the Microsoft 365 document search
 * (connectors-graph-document-search, D1 and D2): which entity types run, the
 * `/search/query` body, how a Graph answer becomes REQ-DCC-004 hits, how a
 * handle becomes an item path, and how a fetched item becomes a file. The
 * adapter does the brokered calls and hands their answers here.
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
 * Builds the Graph search request and reads its answers.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
final class GraphDriveSearch {

	/**
	 * The entity types a document search covers (D1).
	 */
	public const ENTITY_TYPES = ['driveItem', 'message', 'chatMessage'];

	/**
	 * The entity types Graph only searches with the person's own delegated grant (D2).
	 */
	private const DELEGATED_TYPES = ['message', 'chatMessage'];

	/**
	 * Which entity types run, and the notices for the ones that cannot.
	 *
	 * @param string            $terms       The search terms.
	 * @param array<int,string> $entityTypes The asked types; empty for all.
	 *
	 * @return array{searchDrive: bool, notices: array<int, string>} Whether drive items are searched.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function plan(string $terms, array $entityTypes): array {
		$asked = $entityTypes;
		if ($asked === []) {
			$asked = self::ENTITY_TYPES;
		}

		$types = array_values(array_intersect(self::ENTITY_TYPES, $asked));
		$notices = [];
		if (array_intersect($types, self::DELEGATED_TYPES) !== []) {
			$notices[] = 'delegated-grant-missing';
		}

		return [
			'searchDrive' => (in_array('driveItem', $types, true) === true && trim($terms) !== ''),
			'notices' => $notices,
		];
	}//end plan()

	/**
	 * The `/search/query` body for drive items.
	 *
	 * @param string      $terms  The terms.
	 * @param string|null $from   Start, Y-m-d.
	 * @param string|null $to     End, Y-m-d.
	 * @param int         $limit  At most this many hits.
	 * @param string      $region The Graph search region an application grant needs.
	 *
	 * @return string The JSON body.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function requestBody(string $terms, ?string $from, ?string $to, int $limit, string $region): string {
		$query = trim($terms);
		foreach (['>=' => $from, '<=' => $to] as $operator => $date) {
			if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
				$query .= ' LastModifiedTime' . $operator . $date;
			}
		}

		$request = [
			'entityTypes' => ['driveItem'],
			'query' => ['queryString' => $query],
			'from' => 0,
			'size' => $this->clamp(limit: $limit),
			'region' => $region,
		];

		return (string)json_encode(['requests' => [$request]]);
	}//end requestBody()

	/**
	 * A brokered Graph answer as hits, the count of the rest, and notices.
	 *
	 * @param array<string, mixed>|null $response   The broker's answer, null when it refused.
	 * @param string                    $sourceSlug The adapter id.
	 * @param int                       $limit      At most this many hits.
	 * @param array<int, string>        $notices    Notices so far.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>}
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function answer(?array $response, string $sourceSlug, int $limit, array $notices): array {
		$status = (int)($response['status'] ?? 403);
		if ($status === 401 || $status === 403) {
			$notices[] = 'not-permitted';
			return ['hits' => [], 'moreCount' => 0, 'notices' => $notices];
		}

		if ($status < 200 || $status >= 300) {
			$notices[] = 'search-failed';
			return ['hits' => [], 'moreCount' => 0, 'notices' => $notices];
		}

		$decoded = json_decode((string)($response['body'] ?? ''), true);
		$container = [];
		if (is_array($decoded) === true) {
			$container = (array)($decoded['value'][0]['hitsContainers'][0] ?? []);
		}

		$rows = array_values(array_filter((array)($container['hits'] ?? []), 'is_array'));
		$kept = array_slice($rows, 0, $this->clamp(limit: $limit));
		$hits = array_map(fn (array $hit): array => $this->hit(hit: $hit, sourceSlug: $sourceSlug), $kept);
		$total = max(count($hits), (int)($container['total'] ?? 0));

		return ['hits' => $hits, 'moreCount' => ($total - count($hits)), 'notices' => $notices];
	}//end answer()

	/**
	 * The Graph item path a drive item handle names, or null.
	 *
	 * @param string $handle `driveItem:{driveId}:{itemId}`.
	 *
	 * @return string|null The path.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function itemPath(string $handle): ?string {
		$parts = explode(':', $handle);
		if (count($parts) !== 3 || $parts[0] !== 'driveItem' || $parts[1] === '' || $parts[2] === '') {
			return null;
		}

		return '/v1.0/drives/' . rawurlencode($parts[1]) . '/items/' . rawurlencode($parts[2]);
	}//end itemPath()

	/**
	 * A fetched drive item as a file, or null.
	 *
	 * @param array<string, mixed>|null $meta    The item's metadata answer.
	 * @param array<string, mixed>|null $content The item's content answer.
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function file(?array $meta, ?array $content): ?array {
		if ((int)($meta['status'] ?? 0) !== 200 || (int)($content['status'] ?? 0) !== 200) {
			return null;
		}

		$decoded = json_decode((string)($meta['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			return null;
		}

		$name = basename(trim((string)($decoded['name'] ?? '')));
		if (in_array($name, ['', '.', '..'], true) === true) {
			return null;
		}

		return [
			'fileName' => $name,
			'mimeType' => (string)($decoded['file']['mimeType'] ?? 'application/octet-stream'),
			'content' => (string)($content['body'] ?? ''),
		];
	}//end file()

	/**
	 * A limit between 1 and 500.
	 *
	 * @param int $limit The asked limit.
	 *
	 * @return int The limit Graph is asked for.
	 */
	private function clamp(int $limit): int {
		return max(1, min(500, $limit));
	}//end clamp()

	/**
	 * One Graph drive item hit as a REQ-DCC-004 envelope.
	 *
	 * @param array<string, mixed> $hit        The Graph hit.
	 * @param string               $sourceSlug The adapter id.
	 *
	 * @return array<string, mixed> The envelope with `entityType`.
	 */
	private function hit(array $hit, string $sourceSlug): array {
		$resource = (array)($hit['resource'] ?? []);
		$parent = (array)($resource['parentReference'] ?? []);
		$size = ($resource['size'] ?? null);
		if (is_int($size) === false) {
			$size = null;
		}

		return [
			'sourceSlug' => $sourceSlug,
			'remoteId' => 'driveItem:' . (string)($parent['driveId'] ?? '') . ':' . (string)($resource['id'] ?? ($hit['hitId'] ?? '')),
			'title' => (string)($resource['name'] ?? ''),
			'mimeType' => (string)($resource['file']['mimeType'] ?? 'application/octet-stream'),
			'sizeBytes' => $size,
			'modifiedAt' => (string)($resource['lastModifiedDateTime'] ?? ''),
			'modifiedByLabel' => ($resource['lastModifiedBy']['user']['displayName'] ?? null),
			'path' => ($parent['path'] ?? ($resource['webUrl'] ?? null)),
			'previewUrl' => ($resource['webUrl'] ?? null),
			'snippet' => trim(strip_tags((string)($hit['summary'] ?? ''))),
			'score' => null,
			'entityType' => 'driveItem',
		];
	}//end hit()
}//end class
