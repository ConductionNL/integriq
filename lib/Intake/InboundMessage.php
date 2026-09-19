<?php

/**
 * Integriq InboundMessage.
 *
 * The one shape every intake channel normalises into. A consumer reads this
 * and never the raw payload, which is what keeps channel-specific code out of
 * every app downstream. The raw payload travels beside it so a channel can be
 * debugged without a second integration.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

/**
 * One normalised inbound message.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
final class InboundMessage {

	/**
	 * Constructor.
	 *
	 * @param string $channelId The channel that delivered the message.
	 * @param string $externalId The channel's own id for it, unique per channel.
	 * @param array<string,mixed> $correspondent Who wrote: `{id, name, address, phone}`, whatever the channel knows.
	 * @param string $text The message text.
	 * @param array<int,array<string,mixed>> $attachments Attachments as `{name, mime, size, content}`.
	 * @param array<string,mixed>|null $location `{latitude, longitude, address}` when the channel supplies one, else null.
	 * @param array<int,array<string,mixed>> $media Ordered media, same shape as an attachment.
	 * @param array<string,mixed> $rawPayload What the channel actually sent.
	 * @param string|null $receivedAt ISO 8601 receive timestamp, or null.
	 * @param array<string,mixed> $fields Channel-supplied structured fields, for a form submission.
	 */
	public function __construct(
		private readonly string $channelId,
		private readonly string $externalId,
		private readonly array $correspondent,
		private readonly string $text,
		private readonly array $attachments = [],
		private readonly ?array $location = null,
		private readonly array $media = [],
		private readonly array $rawPayload = [],
		private readonly ?string $receivedAt = null,
		private readonly array $fields = [],
	) {

	}//end __construct()

	/**
	 * The channel that delivered the message.
	 *
	 * @return string The channel id.
	 */
	public function getChannelId(): string {
		return $this->channelId;

	}//end getChannelId()

	/**
	 * The channel's own id for this message.
	 *
	 * @return string The external id.
	 */
	public function getExternalId(): string {
		return $this->externalId;

	}//end getExternalId()

	/**
	 * Who wrote the message.
	 *
	 * @return array<string,mixed> The correspondent.
	 */
	public function getCorrespondent(): array {
		return $this->correspondent;

	}//end getCorrespondent()

	/**
	 * The message text.
	 *
	 * @return string The text.
	 */
	public function getText(): string {
		return $this->text;

	}//end getText()

	/**
	 * The attachments.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	public function getAttachments(): array {
		return $this->attachments;

	}//end getAttachments()

	/**
	 * The location, when the channel supplied one.
	 *
	 * A channel with no location writes none: an invented coordinate looks
	 * exactly like a measured one to everyone downstream.
	 *
	 * @return array<string,mixed>|null The location, or null.
	 */
	public function getLocation(): ?array {
		return $this->location;

	}//end getLocation()

	/**
	 * The media, in the order the channel sent them.
	 *
	 * @return array<int,array<string,mixed>> The media.
	 */
	public function getMedia(): array {
		return $this->media;

	}//end getMedia()

	/**
	 * What the channel actually sent.
	 *
	 * @return array<string,mixed> The raw payload.
	 */
	public function getRawPayload(): array {
		return $this->rawPayload;

	}//end getRawPayload()

	/**
	 * When the message arrived.
	 *
	 * @return string|null The timestamp, or null.
	 */
	public function getReceivedAt(): ?string {
		return $this->receivedAt;

	}//end getReceivedAt()

	/**
	 * The structured fields a submission carries.
	 *
	 * @return array<string,mixed> The fields.
	 */
	public function getFields(): array {
		return $this->fields;

	}//end getFields()

	/**
	 * The message as the `intake_message` schema stores it.
	 *
	 * Attachment and media bytes are not part of the object: the object
	 * carries the metadata, the bytes travel with the hand-off.
	 *
	 * @return array<string,mixed> The object payload.
	 */
	public function toObject(): array {
		return [
			'channelId' => $this->channelId,
			'externalId' => $this->externalId,
			'correspondent' => $this->correspondent,
			'text' => $this->text,
			'attachments' => $this->describeFiles(files: $this->attachments),
			'media' => $this->describeFiles(files: $this->media),
			'location' => $this->location,
			'fields' => $this->fields,
			'rawPayload' => $this->rawPayload,
			'receivedAt' => $this->receivedAt,
		];

	}//end toObject()

	/**
	 * Rebuild a message from the object that stored it.
	 *
	 * The bytes are gone by then, which is the point: a reply reads the
	 * channel and the correspondent, never the attachments.
	 *
	 * @param array<string,mixed> $object The stored `intake_message` payload.
	 *
	 * @return self The message.
	 */
	public static function fromObject(array $object): self {
		// A stored payload is whatever was written, so every list-shaped field
		// is narrowed to an array before it reaches the constructor. Written as
		// statements because the coding standard allows neither `?:` nor a
		// ternary; `location` keeps null rather than [] because "no location"
		// and "an empty location" are different answers.
		$correspondent = self::arrayOr(value: ($object['correspondent'] ?? []), fallback: []);
		$attachments = self::arrayOr(value: ($object['attachments'] ?? null), fallback: []);
		$media = self::arrayOr(value: ($object['media'] ?? null), fallback: []);
		$rawPayload = self::arrayOr(value: ($object['rawPayload'] ?? null), fallback: []);
		$fields = self::arrayOr(value: ($object['fields'] ?? null), fallback: []);
		$location = self::arrayOr(value: ($object['location'] ?? null), fallback: null);

		$receivedAt = ($object['receivedAt'] ?? null);
		if ($receivedAt !== null) {
			$receivedAt = (string)$receivedAt;
		}

		return new self(
			(string)($object['channelId'] ?? ''),
			(string)($object['externalId'] ?? ''),
			$correspondent,
			(string)($object['text'] ?? ''),
			$attachments,
			$location,
			$media,
			$rawPayload,
			$receivedAt,
			$fields,
		);

	}//end fromObject()

	/**
	 * The value when it is an array, the fallback when it is anything else.
	 *
	 * @param mixed $value The stored value.
	 * @param array<string,mixed>|null $fallback What a non-array becomes.
	 *
	 * @return array<string,mixed>|null The narrowed value.
	 */
	private static function arrayOr(mixed $value, ?array $fallback): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return $fallback;
	}//end arrayOr()

	/**
	 * Strip the bytes out of a file list.
	 *
	 * @param array<int,array<string,mixed>> $files The files.
	 *
	 * @return array<int,array<string,mixed>> The metadata.
	 */
	private function describeFiles(array $files): array {
		$described = [];
		foreach ($files as $file) {
			$content = (string)($file['content'] ?? '');
			$described[] = [
				'name' => (string)($file['name'] ?? 'attachment'),
				'mime' => (string)($file['mime'] ?? 'application/octet-stream'),
				'size' => (int)($file['size'] ?? strlen($content)),
			];
		}

		return $described;

	}//end describeFiles()

}//end class
