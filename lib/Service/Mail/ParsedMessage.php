<?php

/**
 * Integriq ParsedMessage.
 *
 * One mail message in the shape every intake route produces: a mailbox poll,
 * an `.eml` upload and an `.msg` upload all end here, so the rest of the
 * intake pipeline never learns which door a message came through.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
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

namespace OCA\Integriq\Service\Mail;

/**
 * Immutable value object for one parsed mail message.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
final class ParsedMessage {

	/**
	 * Constructor.
	 *
	 * @param string $messageId Per-source unique id (RFC 5322 Message-ID, Graph id, or a content hash).
	 * @param string $from Sender address.
	 * @param array<int,string> $to Recipient addresses.
	 * @param string $subject Subject line, already decoded.
	 * @param string|null $receivedAt ISO 8601 receive timestamp, null when the message carries none.
	 * @param string $bodyText Plain-text body.
	 * @param string $bodyHtml Sanitized HTML body.
	 * @param array<int,array<string,mixed>> $attachments Attachments as `{name, mime, size, content}`.
	 * @param string|null $warning A non-fatal parse warning, null when the parse was clean.
	 */
	public function __construct(
		private readonly string $messageId,
		private readonly string $from,
		private readonly array $to,
		private readonly string $subject,
		private readonly ?string $receivedAt,
		private readonly string $bodyText,
		private readonly string $bodyHtml,
		private readonly array $attachments = [],
		private readonly ?string $warning = null,
	) {

	}//end __construct()

	/**
	 * The per-source unique message id.
	 *
	 * @return string The message id.
	 */
	public function getMessageId(): string {
		return $this->messageId;

	}//end getMessageId()

	/**
	 * The sender address.
	 *
	 * @return string The sender.
	 */
	public function getFrom(): string {
		return $this->from;

	}//end getFrom()

	/**
	 * The recipient addresses.
	 *
	 * @return array<int,string> The recipients.
	 */
	public function getTo(): array {
		return $this->to;

	}//end getTo()

	/**
	 * The decoded subject line.
	 *
	 * @return string The subject.
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

	/**
	 * The receive timestamp.
	 *
	 * @return string|null ISO 8601 timestamp, or null.
	 */
	public function getReceivedAt(): ?string {
		return $this->receivedAt;

	}//end getReceivedAt()

	/**
	 * The plain-text body.
	 *
	 * @return string The text body.
	 */
	public function getBodyText(): string {
		return $this->bodyText;

	}//end getBodyText()

	/**
	 * The sanitized HTML body.
	 *
	 * @return string The HTML body.
	 */
	public function getBodyHtml(): string {
		return $this->bodyHtml;

	}//end getBodyHtml()

	/**
	 * The attachments.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	public function getAttachments(): array {
		return $this->attachments;

	}//end getAttachments()

	/**
	 * The non-fatal parse warning, when the parser had to fall back.
	 *
	 * @return string|null The warning, or null.
	 */
	public function getWarning(): ?string {
		return $this->warning;

	}//end getWarning()

	/**
	 * The message as the `message` schema stores it.
	 *
	 * Attachment bodies are not part of the object: the object carries the
	 * metadata, the bytes travel to the file store or to filinq.
	 *
	 * @return array<string,mixed> The object payload without `sourceId` or `status`.
	 */
	public function toObject(): array {
		$attachments = [];
		foreach ($this->attachments as $attachment) {
			$attachments[] = [
				'name' => (string)($attachment['name'] ?? 'attachment'),
				'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
				'size' => (int)($attachment['size'] ?? strlen((string)($attachment['content'] ?? ''))),
				'fileRef' => (string)($attachment['fileRef'] ?? ''),
			];
		}

		return [
			'messageId' => $this->messageId,
			'from' => $this->from,
			'to' => $this->to,
			'subject' => $this->subject,
			'receivedAt' => $this->receivedAt,
			'bodyText' => $this->bodyText,
			'bodyHtml' => $this->bodyHtml,
			'attachments' => $attachments,
			'warning' => (string)$this->warning,
		];

	}//end toObject()

	/**
	 * A copy of this message carrying a warning.
	 *
	 * @param string $warning The warning to record.
	 *
	 * @return self The copy.
	 */
	public function withWarning(string $warning): self {
		return new self(
			$this->messageId,
			$this->from,
			$this->to,
			$this->subject,
			$this->receivedAt,
			$this->bodyText,
			$this->bodyHtml,
			$this->attachments,
			$warning,
		);

	}//end withWarning()

}//end class
