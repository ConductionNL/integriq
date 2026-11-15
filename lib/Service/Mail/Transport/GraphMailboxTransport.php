<?php

/**
 * Integriq GraphMailboxTransport.
 *
 * Reads a shared mailbox over Microsoft Graph. Graph answers messages as
 * JSON, so this maps the fields onto {@see ParsedMessage} directly rather
 * than asking for MIME and parsing it again; attachments are fetched per
 * message, and only when the message says it has any.
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
 * @link https://learn.microsoft.com/en-us/graph/api/user-list-messages
 *
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail\Transport;

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\HtmlSanitizer;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Microsoft Graph binding for a mailbox source.
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class GraphMailboxTransport implements MailboxTransportInterface {

	/**
	 * The Graph base url, overridable per source for a sovereign cloud.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://graph.microsoft.com/v1.0';

	/**
	 * How many messages one poll reads at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 */
	public function __construct(private readonly IClientService $clientService) {

	}//end __construct()

	/**
	 * The protocol id.
	 *
	 * @return string Always `graph`.
	 */
	public function getProtocol(): string {
		return 'graph';

	}//end getProtocol()

	/**
	 * Whether this binding can run.
	 *
	 * @return bool Always true: Graph needs only an HTTP client.
	 */
	public function isUsable(): bool {
		return true;

	}//end isUsable()

	/**
	 * Read the messages that arrived after the cursor.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 * @param string|null $cursor The stored `sinceCursor` as an ISO 8601 timestamp.
	 *
	 * @return array<int,ParsedMessage> The messages, oldest first.
	 *
	 * @throws MailboxTransportException When the mailbox is not configured or Graph refuses.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function fetch(array $configuration, ?string $cursor): array {
		$mailbox = trim((string)($configuration['mailbox'] ?? ''));
		$token = trim((string)($configuration['accessToken'] ?? ''));
		if ($mailbox === '' || $token === '') {
			throw new MailboxTransportException(message: 'A Graph mailbox needs both a mailbox address and an access token.');
		}

		$folder = trim((string)($configuration['folder'] ?? 'Inbox'));
		$base = rtrim((string)($configuration['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
		$url = $base . '/users/' . rawurlencode($mailbox) . '/mailFolders/' . rawurlencode($folder) . '/messages';

		$query = [
			'$top' => (string)self::PAGE_SIZE,
			'$orderby' => 'receivedDateTime asc',
		];
		if ($cursor !== null && trim($cursor) !== '') {
			$query['$filter'] = 'receivedDateTime gt ' . trim($cursor);
		}

		$payload = $this->get(url: $url, token: $token, query: $query);
		$run = new GraphMessagesRun(messagesUrl: $url, token: $token);
		$messages = [];
		foreach (($payload['value'] ?? []) as $raw) {
			if (is_array($raw) === false) {
				continue;
			}

			$messages[] = $this->toMessage(raw: $raw, run: $run);
		}

		return $messages;

	}//end fetch()

	/**
	 * Map one Graph message onto a parsed message.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 * @param GraphMessagesRun    $run The collection url, used to fetch attachments, and the token.
	 *
	 * @return ParsedMessage The message.
	 */
	private function toMessage(array $raw, GraphMessagesRun $run): ParsedMessage {
		$attachments = [];
		if (($raw['hasAttachments'] ?? false) === true) {
			$attachments = $this->fetchAttachments(run: $run, messageId: (string)($raw['id'] ?? ''));
		}

		$bodies = self::bodies(raw: $raw);

		return new ParsedMessage(
			messageId: self::messageId(raw: $raw),
			from: (string)($raw['from']['emailAddress']['address'] ?? ''),
			to: self::recipients(raw: $raw),
			subject: (string)($raw['subject'] ?? ''),
			receivedAt: self::receivedAt(raw: $raw),
			bodyText: $bodies['text'],
			bodyHtml: $bodies['html'],
			attachments: $attachments);

	}//end toMessage()

	/**
	 * The recipient addresses of a Graph message, without the empty ones.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 *
	 * @return array<int,string> The addresses.
	 */
	private static function recipients(array $raw): array {
		$recipients = [];
		foreach (($raw['toRecipients'] ?? []) as $recipient) {
			$address = (string)($recipient['emailAddress']['address'] ?? '');
			if ($address !== '') {
				$recipients[] = $address;
			}
		}

		return $recipients;

	}//end recipients()

	/**
	 * The internet message id without its angle brackets, or the Graph id when there is none.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 *
	 * @return string The message id.
	 */
	private static function messageId(array $raw): string {
		$messageId = trim((string)($raw['internetMessageId'] ?? ''), " <>\t");
		if ($messageId === '') {
			$messageId = (string)($raw['id'] ?? '');
		}

		return $messageId;

	}//end messageId()

	/**
	 * When the message arrived, as text, or null when Graph does not say.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 *
	 * @return string|null The received moment.
	 */
	private static function receivedAt(array $raw): ?string {
		$receivedAt = ($raw['receivedDateTime'] ?? null);
		if ($receivedAt !== null) {
			$receivedAt = (string)$receivedAt;
		}

		return $receivedAt;

	}//end receivedAt()

	/**
	 * The text and HTML bodies. A Graph message carries either a preview plus
	 * sanitised HTML, or a plain-text body and no HTML at all.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 *
	 * @return array{text: string, html: string} The bodies.
	 */
	private static function bodies(array $raw): array {
		$body   = (string)($raw['body']['content'] ?? '');
		$isHtml = (strtolower((string)($raw['body']['contentType'] ?? 'text')) === 'html');
		if ($isHtml === true) {
			return ['text' => (string)($raw['bodyPreview'] ?? ''), 'html' => HtmlSanitizer::sanitize($body)];
		}

		return ['text' => $body, 'html' => ''];

	}//end bodies()

	/**
	 * Read one message's attachments.
	 *
	 * @param GraphMessagesRun $run       The collection url and the token.
	 * @param string           $messageId The Graph message id.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	private function fetchAttachments(GraphMessagesRun $run, string $messageId): array {
		if ($messageId === '') {
			return [];
		}

		$payload = $this->get(url: $run->messagesUrl . '/' . rawurlencode($messageId) . '/attachments', token: $run->token, query: []);
		$attachments = [];
		foreach (($payload['value'] ?? []) as $raw) {
			if (is_array($raw) === false) {
				continue;
			}

			$content = (string)base64_decode((string)($raw['contentBytes'] ?? ''), false);
			$attachments[] = [
				'name' => (string)($raw['name'] ?? 'attachment'),
				'mime' => (string)($raw['contentType'] ?? 'application/octet-stream'),
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return $attachments;

	}//end fetchAttachments()

	/**
	 * One authenticated Graph GET.
	 *
	 * @param string $url The url.
	 * @param string $token The bearer token.
	 * @param array<string,string> $query The query parameters.
	 *
	 * @return array<string,mixed> The decoded body.
	 *
	 * @throws MailboxTransportException When the call fails or the body is not JSON.
	 */
	private function get(string $url, string $token, array $query): array {
		try {
			$response = $this->clientService->newClient()->get(
				$url,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept' => 'application/json',
					],
					'query' => $query,
					'timeout' => 30,
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $exception) {
			throw new MailboxTransportException(message: 'Graph refused the mailbox read: ' . $exception->getMessage());
		}

		if (is_array($decoded) === false) {
			throw new MailboxTransportException(message: 'Graph answered something that is not JSON.');
		}

		return $decoded;

	}//end get()

}//end class
