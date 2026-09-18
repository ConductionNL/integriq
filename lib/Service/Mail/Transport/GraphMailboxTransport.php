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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
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
	 */
	public function fetch(array $configuration, ?string $cursor): array {
		$mailbox = trim((string)($configuration['mailbox'] ?? ''));
		$token = trim((string)($configuration['accessToken'] ?? ''));
		if ($mailbox === '' || $token === '') {
			throw new MailboxTransportException('A Graph mailbox needs both a mailbox address and an access token.');
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

		$payload = $this->get($url, $token, $query);
		$messages = [];
		foreach (($payload['value'] ?? []) as $raw) {
			if (is_array($raw) === false) {
				continue;
			}

			$messages[] = $this->toMessage($raw, $url, $token);
		}

		return $messages;

	}//end fetch()

	/**
	 * Map one Graph message onto a parsed message.
	 *
	 * @param array<string,mixed> $raw The Graph message.
	 * @param string $messagesUrl The collection url, used to fetch attachments.
	 * @param string $token The bearer token.
	 *
	 * @return ParsedMessage The message.
	 */
	private function toMessage(array $raw, string $messagesUrl, string $token): ParsedMessage {
		$recipients = [];
		foreach (($raw['toRecipients'] ?? []) as $recipient) {
			$address = (string)($recipient['emailAddress']['address'] ?? '');
			if ($address !== '') {
				$recipients[] = $address;
			}
		}

		$body = (string)($raw['body']['content'] ?? '');
		$isHtml = (strtolower((string)($raw['body']['contentType'] ?? 'text')) === 'html');
		$attachments = [];
		if (($raw['hasAttachments'] ?? false) === true) {
			$attachments = $this->fetchAttachments($messagesUrl, (string)($raw['id'] ?? ''), $token);
		}

		$messageId = trim((string)($raw['internetMessageId'] ?? ''), " <>\t");
		if ($messageId === '') {
			$messageId = (string)($raw['id'] ?? '');
		}

		return new ParsedMessage(
			$messageId,
			(string)($raw['from']['emailAddress']['address'] ?? ''),
			$recipients,
			(string)($raw['subject'] ?? ''),
			(($raw['receivedDateTime'] ?? null) === null ? null : (string)$raw['receivedDateTime']),
			($isHtml === true ? (string)($raw['bodyPreview'] ?? '') : $body),
			($isHtml === true ? HtmlSanitizer::sanitize($body) : ''),
			$attachments,
		);

	}//end toMessage()

	/**
	 * Read one message's attachments.
	 *
	 * @param string $messagesUrl The collection url.
	 * @param string $messageId The Graph message id.
	 * @param string $token The bearer token.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	private function fetchAttachments(string $messagesUrl, string $messageId, string $token): array {
		if ($messageId === '') {
			return [];
		}

		$payload = $this->get($messagesUrl . '/' . rawurlencode($messageId) . '/attachments', $token, []);
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
			throw new MailboxTransportException('Graph refused the mailbox read: ' . $exception->getMessage());
		}

		if (is_array($decoded) === false) {
			throw new MailboxTransportException('Graph answered something that is not JSON.');
		}

		return $decoded;

	}//end get()

}//end class
