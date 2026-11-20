<?php

/**
 * Integriq chat thread transcript.
 *
 * Renders the thread of a fetched chat message as a plain text transcript, so
 * the case file reads on its own (connectors-graph-document-search-chat-threads,
 * design D4).
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

/**
 * Turns a `thread` (platform, conversation, participants, messages) into the
 * fetch answer: `fileName`, `mimeType` `text/plain`, the transcript as
 * `content`, and the thread itself.
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */
class ThreadTranscript {

	/**
	 * The marker in front of the hit's line.
	 */
	public const HIT_MARKER = '>> ';

	/**
	 * The indent in front of every other message's line.
	 */
	private const PLAIN_INDENT = '   ';

	/**
	 * The fetch answer for a thread.
	 *
	 * @param array<string,mixed> $thread    The thread (platform, conversation, participants, messages).
	 * @param string              $fetchedAt When it was read, ISO 8601.
	 *
	 * @return array{fileName: string, mimeType: string, content: string, thread: array<string,mixed>} The answer.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function answer(array $thread, string $fetchedAt): array {
		$conversation = (array)($thread['conversation'] ?? []);
		$id = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($conversation['id'] ?? 'conversation'));

		return [
			'fileName' => 'chat-' . trim((string)$id, '-') . '.txt',
			'mimeType' => 'text/plain',
			'content' => $this->render(thread: $thread, fetchedAt: $fetchedAt),
			'thread' => $thread,
		];
	}//end answer()

	/**
	 * The plain text transcript: a header, then the messages in order with the hit marked.
	 *
	 * @param array<string,mixed> $thread    The thread.
	 * @param string              $fetchedAt When it was read, ISO 8601.
	 *
	 * @return string The transcript.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function render(array $thread, string $fetchedAt): string {
		$conversation = (array)($thread['conversation'] ?? []);
		$labels = array_map(
			static fn (array $participant): string => (string)($participant['label'] ?? $participant['id'] ?? ''),
			array_values(array_filter((array)($thread['participants'] ?? []), 'is_array'))
		);

		$lines = [
			'Platform: ' . (string)($thread['platform'] ?? ''),
			'Conversation: ' . (string)($conversation['name'] ?? '') . ' (' . (string)($conversation['kind'] ?? '') . ')',
			'Participants: ' . implode(', ', $labels),
			'Fetched at: ' . $fetchedAt,
			'',
		];
		foreach ((array)($thread['messages'] ?? []) as $message) {
			if (is_array($message) === false) {
				continue;
			}

			$lines[] = $this->line(message: $message);
		}

		return implode("\n", $lines) . "\n";
	}//end render()

	/**
	 * One message's line: marker, time, author and text on one line.
	 *
	 * @param array<string,mixed> $message The message.
	 *
	 * @return string The line.
	 */
	private function line(array $message): string {
		$prefix = self::PLAIN_INDENT;
		if (($message['isHit'] ?? false) === true) {
			$prefix = self::HIT_MARKER;
		}

		$text = preg_replace('/\s+/u', ' ', (string)($message['text'] ?? ''));

		return $prefix . '[' . (string)($message['at'] ?? '') . '] ' . (string)($message['actorLabel'] ?? '') . ': ' . trim((string)$text);
	}//end line()
}//end class
