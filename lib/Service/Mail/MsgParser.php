<?php

/**
 * Integriq MsgParser.
 *
 * Reads a saved Outlook message (`.msg`) into the same {@see ParsedMessage}
 * an `.eml` produces. A `.msg` is a compound file whose property streams are
 * named after MAPI property tags, so this maps the tags it needs and walks
 * the `__attach_version1.0_#*` storages for attachments.
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
 * @link https://learn.microsoft.com/en-us/openspecs/office_file_formats/ms-oxmsg/
 *
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

/**
 * Parses `.msg` compound files into a {@see ParsedMessage}.
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MsgParser {

	/**
	 * Prefix of every MAPI property stream.
	 *
	 * @var string
	 */
	private const PROPERTY_PREFIX = '__substg1.0_';

	/**
	 * Prefix of every attachment storage.
	 *
	 * @var string
	 */
	private const ATTACHMENT_PREFIX = '__attach_version1.0_';

	/**
	 * Parse one `.msg` file.
	 *
	 * @param string $raw The compound file bytes.
	 *
	 * @return ParsedMessage The parsed message.
	 *
	 * @throws \OCA\Integriq\Service\Mail\MessageParseException When the bytes are not a readable compound file.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function parse(string $raw): ParsedMessage {
		$reader = new CompoundFileReader($raw);
		$properties = $this->readProperties(reader: $reader, storageId: 0);
		$headers = $this->transportHeaders(properties: $properties);

		return new ParsedMessage(
			$this->messageIdFor(properties: $properties, raw: $raw),
			$this->senderOf(properties: $properties, headers: $headers),
			$this->recipients(displayTo: $this->property(properties: $properties, propertyId: '0E04'), headers: $headers),
			$this->firstProperty(properties: $properties, propertyIds: ['0037', '0E1D']),
			MailHeaders::date(header: (string)($headers['date'] ?? '')),
			$this->property(properties: $properties, propertyId: '1000'),
			HtmlSanitizer::sanitize($this->property(properties: $properties, propertyId: '1013')),
			$this->readAttachments(reader: $reader),
		);

	}//end parse()

	/**
	 * The stored internet headers of the message.
	 *
	 * @param array<string,string> $properties The message's properties.
	 *
	 * @return array<string,string> Lower-cased header names to values.
	 */
	private function transportHeaders(array $properties): array {
		return $this->parseTransportHeaders(block: $this->property(properties: $properties, propertyId: '007D'));

	}//end transportHeaders()

	/**
	 * The message id: the stored property, else the stored header, else a
	 * hash of the bytes.
	 *
	 * @param array<string,string> $properties The message's properties.
	 * @param string               $raw        The compound file bytes.
	 *
	 * @return string
	 */
	private function messageIdFor(array $properties, string $raw): string {
		$messageId = trim($this->property(properties: $properties, propertyId: '1035'), " <>\t");
		if ($messageId === '') {
			$messageId = trim((string)($this->transportHeaders(properties: $properties)['message-id'] ?? ''), " <>\t");
		}

		if ($messageId === '') {
			$messageId = 'sha256:' . hash('sha256', $raw);
		}

		return $messageId;

	}//end messageIdFor()

	/**
	 * The sender: the sender's address property, else the sender name
	 * property, else the stored From header.
	 *
	 * @param array<string,string> $properties The message's properties.
	 * @param array<string,string> $headers    The stored internet headers.
	 *
	 * @return string
	 */
	private function senderOf(array $properties, array $headers): string {
		$from = $this->firstProperty(properties: $properties, propertyIds: ['5D01', '0C1F']);
		if ($from === '') {
			$from = (string)($headers['from'] ?? '');
		}

		return $from;

	}//end senderOf()

	/**
	 * The first of the properties that is not empty.
	 *
	 * @param array<string,string> $properties  The properties read from a storage.
	 * @param array<int,string>    $propertyIds The 4 hex character property ids, in order.
	 *
	 * @return string The value, or an empty string when all are empty.
	 */
	private function firstProperty(array $properties, array $propertyIds): string {
		foreach ($propertyIds as $propertyId) {
			$value = $this->property(properties: $properties, propertyId: $propertyId);
			if ($value !== '') {
				return $value;
			}
		}

		return '';

	}//end firstProperty()

	/**
	 * Read every property stream of one storage.
	 *
	 * @param CompoundFileReader $reader The open compound file.
	 * @param int $storageId The storage's directory id.
	 *
	 * @return array<string,string> Property tag id (4 hex chars) to decoded value.
	 */
	private function readProperties(CompoundFileReader $reader, int $storageId): array {
		$entries = $reader->getEntries();
		$properties = [];
		foreach ($reader->getChildren($storageId) as $childId) {
			$tag = $this->propertyTag(entry: $entries[$childId]);
			if ($tag === null) {
				continue;
			}

			$value = $this->decodeProperty(tag: $tag, value: $reader->readStream($childId));
			// A property present twice keeps the first, matching the EML reader.
			$properties += [strtoupper(substr($tag, 0, 4)) => $value];
		}

		return $properties;

	}//end readProperties()

	/**
	 * The tag (property id and type, 8+ hex characters) of a property
	 * stream, or null for any other entry.
	 *
	 * @param array<string,mixed> $entry The directory entry.
	 *
	 * @return string|null The tag.
	 */
	private function propertyTag(array $entry): ?string {
		$name = (string)$entry['name'];
		if ((int)$entry['type'] !== CompoundFileReader::TYPE_STREAM || str_starts_with($name, self::PROPERTY_PREFIX) === false) {
			return null;
		}

		$tag = substr($name, strlen(self::PROPERTY_PREFIX));
		if (strlen($tag) < 8) {
			return null;
		}

		return $tag;

	}//end propertyTag()

	/**
	 * Decode a property stream: unicode strings (type 001F) to UTF-8,
	 * every other type as the raw bytes.
	 *
	 * @param string $tag   The property tag.
	 * @param string $value The stream bytes.
	 *
	 * @return string The value.
	 */
	private function decodeProperty(string $tag, string $value): string {
		if (strtoupper(substr($tag, 4, 4)) !== '001F') {
			return $value;
		}

		$converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', $value);
		if ($converted === false) {
			return '';
		}

		return $converted;

	}//end decodeProperty()

	/**
	 * Read every attachment storage.
	 *
	 * @param CompoundFileReader $reader The open compound file.
	 *
	 * @return array<int,array<string,mixed>> The attachments as `{name, mime, size, content}`.
	 */
	private function readAttachments(CompoundFileReader $reader): array {
		$entries = $reader->getEntries();
		$attachments = [];
		foreach ($reader->getChildren(0) as $childId) {
			if ($this->isAttachmentStorage(entry: $entries[$childId]) === true) {
				$attachments[] = $this->attachment(properties: $this->readProperties(reader: $reader, storageId: $childId));
			}
		}

		return $attachments;

	}//end readAttachments()

	/**
	 * Whether a directory entry is an attachment storage.
	 *
	 * @param array<string,mixed> $entry The directory entry.
	 *
	 * @return boolean
	 */
	private function isAttachmentStorage(array $entry): bool {
		return (int)$entry['type'] === CompoundFileReader::TYPE_STORAGE
			&& str_starts_with((string)$entry['name'], self::ATTACHMENT_PREFIX) === true;

	}//end isAttachmentStorage()

	/**
	 * One attachment from its storage's properties: the long file name, else
	 * the short one, else "attachment", and an unknown mime type as
	 * application/octet-stream.
	 *
	 * @param array<string,string> $properties The attachment's properties.
	 *
	 * @return array<string,mixed> The attachment as `{name, mime, size, content}`.
	 */
	private function attachment(array $properties): array {
		$name = $this->property(properties: $properties, propertyId: '3707');
		if ($name === '') {
			$name = $this->property(properties: $properties, propertyId: '3704');
		}

		$content = $this->property(properties: $properties, propertyId: '3701');

		return [
			'name' => $this->orDefault(value: $name, default: 'attachment'),
			'mime' => $this->orDefault(value: $this->property(properties: $properties, propertyId: '370E'), default: 'application/octet-stream'),
			'size' => strlen($content),
			'content' => $content,
		];

	}//end attachment()

	/**
	 * A value, or the default when it is empty.
	 *
	 * @param string $value   The value.
	 * @param string $default The default.
	 *
	 * @return string
	 */
	private function orDefault(string $value, string $default): string {
		if ($value === '') {
			return $default;
		}

		return $value;

	}//end orDefault()

	/**
	 * One property's value.
	 *
	 * @param array<string,string> $properties The properties read from a storage.
	 * @param string $propertyId The 4 hex character property id.
	 *
	 * @return string The value, or an empty string when absent.
	 */
	private function property(array $properties, string $propertyId): string {
		return (string)($properties[strtoupper($propertyId)] ?? '');

	}//end property()

	/**
	 * Parse the stored internet headers block.
	 *
	 * @param string $block The `PR_TRANSPORT_MESSAGE_HEADERS` value.
	 *
	 * @return array<string,string> Lower-cased header names to values.
	 */
	private function parseTransportHeaders(string $block): array {
		if (trim($block) === '') {
			return [];
		}

		return MailHeaders::parse(headerBlock: str_replace(["\r\n", "\r"], "\n", $block));

	}//end parseTransportHeaders()

	/**
	 * The recipient list, from the display-to property or the stored headers.
	 *
	 * @param string $displayTo The `PR_DISPLAY_TO` value.
	 * @param array<string,string> $headers The stored internet headers.
	 *
	 * @return array<int,string> The recipients.
	 */
	private function recipients(string $displayTo, array $headers): array {
		$recipients = [];
		foreach ($this->recipientEntries(displayTo: $displayTo, headers: $headers) as $entry) {
			$address = $this->recipientAddress(entry: $entry);
			if ($address !== null) {
				$recipients[] = $address;
			}
		}

		return $recipients;

	}//end recipients()

	/**
	 * The raw recipient entries: the display-to property split on ; and ,
	 * or, without it, the stored To header.
	 *
	 * @param string               $displayTo The `PR_DISPLAY_TO` value.
	 * @param array<string,string> $headers   The stored internet headers.
	 *
	 * @return array<int,string> The entries, untrimmed.
	 */
	private function recipientEntries(string $displayTo, array $headers): array {
		$source = (string)($headers['to'] ?? '');
		if ($displayTo !== '') {
			$source = $displayTo;
		}

		$parts = preg_split('/[;,]/', $source);
		if ($parts === false) {
			return [];
		}

		return $parts;

	}//end recipientEntries()

	/**
	 * One recipient's address: the part inside angle brackets when present,
	 * otherwise the trimmed entry; null for an empty entry.
	 *
	 * @param string $entry The raw entry.
	 *
	 * @return string|null The address.
	 */
	private function recipientAddress(string $entry): ?string {
		$entry = trim($entry);
		if ($entry === '') {
			return null;
		}

		if (preg_match('/<([^>]+)>/', $entry, $matches) === 1) {
			return trim($matches[1]);
		}

		return $entry;

	}//end recipientAddress()

}//end class
