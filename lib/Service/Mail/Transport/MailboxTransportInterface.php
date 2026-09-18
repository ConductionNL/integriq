<?php

/**
 * Integriq MailboxTransportInterface.
 *
 * What every mailbox protocol has to answer: which protocol it speaks, and
 * the messages that arrived since a cursor. Everything above this interface
 * works in {@see \OCA\Integriq\Service\Mail\ParsedMessage}, so IMAP, Graph and
 * the mock fixture are interchangeable to intake.
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

use OCA\Integriq\Service\Mail\ParsedMessage;

/**
 * One mailbox protocol binding.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
interface MailboxTransportInterface {

	/**
	 * The `protocol` value this binding answers to.
	 *
	 * @return string The protocol id.
	 */
	public function getProtocol(): string;

	/**
	 * Whether this instance can actually run the protocol.
	 *
	 * @return bool True when the binding's dependencies are present.
	 */
	public function isUsable(): bool;

	/**
	 * Fetch the messages that arrived after the cursor.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 * @param string|null $cursor The stored `sinceCursor`, or null on a first poll.
	 *
	 * @return array<int,ParsedMessage> The messages, oldest first.
	 *
	 * @throws \OCA\Integriq\Exception\MailboxTransportException When the mailbox cannot be read.
	 */
	public function fetch(array $configuration, ?string $cursor): array;

}//end interface
