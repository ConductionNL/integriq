<?php

/**
 * Integriq Talk search hits.
 *
 * Turns the entries of Talk's unified search provider into REQ-DCC-004 hits,
 * inside the asked period (connectors-graph-document-search-chat-threads,
 * design D5).
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

use JsonSerializable;

/**
 * Each entry's attributes give the conversation token, the message id, the
 * author and the time; the period is applied to that time.
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */
class TalkSearchHits {

	/**
	 * The hits of Talk's search entries that fall inside the period.
	 *
	 * @param array<int,mixed> $entries The `SearchResultEntry` objects.
	 * @param string|null      $from    Start of the period, Y-m-d.
	 * @param string|null      $to      End of the period, Y-m-d.
	 *
	 * @return array<int,array<string,mixed>> The hits, in Talk's order.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function fromEntries(array $entries, ?string $from, ?string $to): array {
		$start = $this->dayStart(day: $from, offsetDays: 0, unbounded: PHP_INT_MIN);
		$end = $this->dayStart(day: $to, offsetDays: 1, unbounded: PHP_INT_MAX);
		$hits = [];
		foreach ($entries as $entry) {
			$hit = $this->hit(entry: $entry, start: $start, end: $end);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}//end fromEntries()

	/**
	 * A REQ-DCC-004 hit from one of Talk's search entries, inside the period.
	 *
	 * @param mixed $entry The `SearchResultEntry`.
	 * @param int   $start Start of the period (inclusive), a UTC timestamp.
	 * @param int   $end   End of the period (exclusive), a UTC timestamp.
	 *
	 * @return array<string,mixed>|null The hit, or null when outside the period or unreadable.
	 */
	private function hit(mixed $entry, int $start, int $end): ?array {
		$data = $this->entryData(entry: $entry);
		$attributes = (array)($data['attributes'] ?? []);
		$token = (string)($attributes['conversation'] ?? '');
		$messageId = (string)($attributes['messageId'] ?? '');
		$timestamp = (int)($attributes['timestamp'] ?? 0);
		if (in_array('', [$token, $messageId], true) === true || $timestamp < $start || $timestamp >= $end) {
			return null;
		}

		return [
			'sourceSlug' => TalkMessageSource::PLATFORM,
			'remoteId' => TalkMessageSource::HANDLE_PREFIX . $token . ':' . $messageId,
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
			'platform' => TalkMessageSource::PLATFORM,
			'conversationId' => $token,
		];
	}//end hit()

	/**
	 * The serialised data of a search entry.
	 *
	 * @param mixed $entry The `SearchResultEntry`.
	 *
	 * @return array<string,mixed> Its data, or none when the entry cannot be serialised.
	 */
	private function entryData(mixed $entry): array {
		if (($entry instanceof JsonSerializable) === false) {
			return [];
		}

		return (array)$entry->jsonSerialize();
	}//end entryData()

	/**
	 * The start of a day, UTC, moved by whole days.
	 *
	 * @param mixed $day        The day, Y-m-d; null or unreadable for no bound.
	 * @param int   $offsetDays Days to add.
	 * @param int   $unbounded  The value for no bound.
	 *
	 * @return int The timestamp, or `unbounded` for no bound.
	 */
	private function dayStart(mixed $day, int $offsetDays, int $unbounded): int {
		$start = false;
		if (is_string($day) === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
			$start = strtotime($day . 'T00:00:00+00:00');
		}

		if ($start === false) {
			return $unbounded;
		}

		return $start + ($offsetDays * 86400);
	}//end dayStart()
}//end class
