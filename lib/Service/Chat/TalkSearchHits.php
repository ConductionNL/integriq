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
		$hits = [];
		foreach ($entries as $entry) {
			$hit = $this->hit(entry: $entry, from: $from, to: $to);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}//end fromEntries()

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
		if (($entry instanceof JsonSerializable) === false) {
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
	 * Whether a message time falls inside the period (both ends whole days, UTC).
	 *
	 * @param int         $timestamp The message time.
	 * @param string|null $from      Start of the period, Y-m-d.
	 * @param string|null $to        End of the period, Y-m-d.
	 *
	 * @return bool True when inside.
	 */
	private function inPeriod(int $timestamp, ?string $from, ?string $to): bool {
		$start = $this->dayStart(day: $from, offsetDays: 0);
		$end = $this->dayStart(day: $to, offsetDays: 1);

		return ($start === null || $timestamp >= $start) && ($end === null || $timestamp < $end);
	}//end inPeriod()

	/**
	 * The start of a day, UTC, moved by whole days.
	 *
	 * @param string|null $day        The day, Y-m-d; null or unreadable for no bound.
	 * @param int         $offsetDays Days to add.
	 *
	 * @return int|null The timestamp, or null for no bound.
	 */
	private function dayStart(?string $day, int $offsetDays): ?int {
		if (is_string($day) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
			return null;
		}

		$start = strtotime($day . 'T00:00:00+00:00');
		if ($start === false) {
			return null;
		}

		return $start + ($offsetDays * 86400);
	}//end dayStart()
}//end class
