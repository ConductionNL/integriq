<?php

/**
 * Integriq ImapMailboxTransport.
 *
 * Reads a shared mailbox over IMAP through PHP's imap extension. The
 * extension is optional on a Nextcloud host, so this binding says out loud
 * whether it can run: a mailbox configured for IMAP on a host without the
 * extension refuses the poll instead of reporting an empty mailbox.
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

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\ParsedMessage;
use Throwable;

/**
 * IMAP binding for a mailbox source.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- the imap extension is a function API.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class ImapMailboxTransport implements MailboxTransportInterface {

	/**
	 * Constructor.
	 *
	 * @param MessageParser $messageParser Parses the raw messages IMAP hands back.
	 */
	public function __construct(private readonly MessageParser $messageParser) {

	}//end __construct()

	/**
	 * The protocol id.
	 *
	 * @return string Always `imap`.
	 */
	public function getProtocol(): string {
		return 'imap';

	}//end getProtocol()

	/**
	 * Whether the imap extension is present on this host.
	 *
	 * @return bool True when IMAP can be spoken here.
	 */
	public function isUsable(): bool {
		return function_exists('imap_open');

	}//end isUsable()

	/**
	 * Read the messages that arrived after the cursor.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 * @param string|null $cursor The stored `sinceCursor` as an ISO 8601 timestamp.
	 *
	 * @return array<int,ParsedMessage> The messages.
	 *
	 * @throws MailboxTransportException When the extension is absent, the mailbox is
	 *                                   misconfigured, or the server refuses.
	 */
	public function fetch(array $configuration, ?string $cursor): array {
		if ($this->isUsable() === false) {
			throw new MailboxTransportException(
				message: 'This host has no imap extension, so an IMAP mailbox cannot be polled here.'
			);
		}

		$host = trim((string)($configuration['host'] ?? ''));
		$username = trim((string)($configuration['username'] ?? ''));
		$password = (string)($configuration['password'] ?? '');
		if ($host === '' || $username === '') {
			throw new MailboxTransportException(message: 'An IMAP mailbox needs a host and a username.');
		}

		$connection = @imap_open($this->mailboxString(configuration: $configuration), $username, $password, 0, 1);
		if ($connection === false) {
			throw new MailboxTransportException(message: 'The IMAP server refused the connection or the credentials.');
		}

		try {
			return $this->readMessages(connection: $connection, cursor: $cursor);
		} catch (Throwable $exception) {
			throw new MailboxTransportException(message: 'The IMAP mailbox could not be read: ' . $exception->getMessage());
		} finally {
			@imap_close($connection);
		}

	}//end fetch()

	/**
	 * Read and parse the matching messages.
	 *
	 * @param mixed $connection The open IMAP connection.
	 * @param string|null $cursor The stored cursor.
	 *
	 * @return array<int,ParsedMessage> The messages.
	 */
	private function readMessages(mixed $connection, ?string $cursor): array {
		$criteria = 'ALL';
		if ($cursor !== null && trim($cursor) !== '') {
			$timestamp = strtotime($cursor);
			if ($timestamp !== false) {
				$criteria = 'SINCE "' . date('d-M-Y', $timestamp) . '"';
			}
		}

		$numbers = @imap_search($connection, $criteria);
		if ($numbers === false) {
			return [];
		}

		$messages = [];
		foreach ($numbers as $number) {
			$header = (string)@imap_fetchheader($connection, (int)$number);
			$body = (string)@imap_body($connection, (int)$number);
			if ($header === '' && $body === '') {
				continue;
			}

			$messages[] = $this->messageParser->parse('imap-' . $number . '.eml', $header . "\r\n" . $body);
		}

		return $messages;

	}//end readMessages()

	/**
	 * Build the IMAP mailbox string.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 *
	 * @return string The mailbox string.
	 */
	private function mailboxString(array $configuration): string {
		$host = trim((string)($configuration['host'] ?? ''));
		$port = (int)($configuration['port'] ?? 993);
		$encryption = strtolower((string)($configuration['encryption'] ?? 'ssl'));
		$folder = trim((string)($configuration['folder'] ?? 'INBOX'));

		$flags = '/imap';
		if ($encryption === 'ssl') {
			$flags .= '/ssl';
		} elseif ($encryption === 'tls') {
			$flags .= '/tls';
		} else {
			$flags .= '/notls';
		}

		if (($configuration['validateCert'] ?? true) === false) {
			$flags .= '/novalidate-cert';
		}

		return '{' . $host . ':' . $port . $flags . '}' . $folder;

	}//end mailboxString()

}//end class
