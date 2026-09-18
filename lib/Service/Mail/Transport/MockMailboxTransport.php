<?php

/**
 * Integriq MockMailboxTransport.
 *
 * The mock-mode binding required of every source type: the mailbox's fixture
 * stands in for the mail server, so the reader, the reference detector and the
 * intake pipeline under test are the production ones. A fixture entry is
 * either a raw message (`raw`, parsed by the real readers) or the fields
 * directly.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail\Transport
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail\Transport;

use OCA\Integriq\Service\Mail\HtmlSanitizer;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\ParsedMessage;

/**
 * Serves a mailbox source's fixture messages.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class MockMailboxTransport implements MailboxTransportInterface {

	/**
	 * Constructor.
	 *
	 * @param MessageParser $messageParser Parses a fixture that carries raw bytes.
	 */
	public function __construct(private readonly MessageParser $messageParser) {

	}//end __construct()

	/**
	 * The protocol id.
	 *
	 * @return string Always `mock`.
	 */
	public function getProtocol(): string {
		return 'mock';

	}//end getProtocol()

	/**
	 * Whether this binding can run.
	 *
	 * @return bool Always true: a fixture needs nothing installed.
	 */
	public function isUsable(): bool {
		return true;

	}//end isUsable()

	/**
	 * Read the fixture messages.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 * @param string|null $cursor The stored cursor; a fixture message with an earlier
	 *                            `receivedAt` is skipped, so a cursor behaves as it does live.
	 *
	 * @return array<int,ParsedMessage> The fixture messages.
	 */
	public function fetch(array $configuration, ?string $cursor): array {
		$fixture = ($configuration['fixture'] ?? []);
		$rawMessages = ($fixture['messages'] ?? []);
		if (is_array($rawMessages) === false) {
			return [];
		}

		$messages = [];
		foreach ($rawMessages as $index => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$message = $this->toMessage($entry, (int)$index);
			if ($this->isAfterCursor($message, $cursor) === false) {
				continue;
			}

			$messages[] = $message;
		}

		return $messages;

	}//end fetch()

	/**
	 * Turn one fixture entry into a parsed message.
	 *
	 * @param array<string,mixed> $entry The fixture entry.
	 * @param int $index The entry's position, used when it names no id.
	 *
	 * @return ParsedMessage The message.
	 */
	private function toMessage(array $entry, int $index): ParsedMessage {
		$raw = (string)($entry['raw'] ?? '');
		if ($raw !== '') {
			return $this->messageParser->parse((string)($entry['filename'] ?? 'fixture.eml'), $raw);
		}

		$to = ($entry['to'] ?? []);
		if (is_array($to) === false) {
			$to = [(string)$to];
		}

		$attachments = [];
		foreach (($entry['attachments'] ?? []) as $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$content = (string)($attachment['content'] ?? '');
			$attachments[] = [
				'name' => (string)($attachment['name'] ?? 'attachment'),
				'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return new ParsedMessage(
			(string)($entry['messageId'] ?? ('fixture-' . $index)),
			(string)($entry['from'] ?? ''),
			array_map('strval', $to),
			(string)($entry['subject'] ?? ''),
			($entry['receivedAt'] ?? null) === null ? null : (string)$entry['receivedAt'],
			(string)($entry['bodyText'] ?? ''),
			HtmlSanitizer::sanitize((string)($entry['bodyHtml'] ?? '')),
			$attachments,
		);

	}//end toMessage()

	/**
	 * Whether a fixture message is newer than the cursor.
	 *
	 * @param ParsedMessage $message The message.
	 * @param string|null $cursor The cursor.
	 *
	 * @return bool True when the message should be served.
	 */
	private function isAfterCursor(ParsedMessage $message, ?string $cursor): bool {
		if ($cursor === null || trim($cursor) === '' || $message->getReceivedAt() === null) {
			return true;
		}

		return (strtotime($message->getReceivedAt()) > strtotime($cursor));

	}//end isAfterCursor()

}//end class
