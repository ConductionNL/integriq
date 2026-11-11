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
			$entry = $entries[$childId];
			if ((int)$entry['type'] !== CompoundFileReader::TYPE_STREAM) {
				continue;
			}

			$name = (string)$entry['name'];
			if (str_starts_with($name, self::PROPERTY_PREFIX) === false) {
				continue;
			}

			$tag = substr($name, strlen(self::PROPERTY_PREFIX));
			if (strlen($tag) < 8) {
				continue;
			}

			$propertyId = strtoupper(substr($tag, 0, 4));
			$type = strtoupper(substr($tag, 4, 4));
			$value = $reader->readStream($childId);
			if ($type === '001F') {
				$converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', $value);
				$value = $converted;
				if ($converted === false) {
					$value = '';
				}
			}

			// A property present twice keeps the first, matching the EML reader.
			if (isset($properties[$propertyId]) === false) {
				$properties[$propertyId] = $value;
			}
		}

		return $properties;

	}//end readProperties()

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
			$entry = $entries[$childId];
			if ((int)$entry['type'] !== CompoundFileReader::TYPE_STORAGE) {
				continue;
			}

			if (str_starts_with((string)$entry['name'], self::ATTACHMENT_PREFIX) === false) {
				continue;
			}

			$properties = $this->readProperties(reader: $reader, storageId: $childId);
			$name = $this->property(properties: $properties, propertyId: '3707');
			if ($name === '') {
				$name = $this->property(properties: $properties, propertyId: '3704');
			}

			$content = $this->property(properties: $properties, propertyId: '3701');
			$mime = $this->property(properties: $properties, propertyId: '370E');
			if ($name === '') {
				$name = 'attachment';
			}

			if ($mime === '') {
				$mime = 'application/octet-stream';
			}

			$attachments[] = [
				'name' => $name,
				'mime' => $mime,
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return $attachments;

	}//end readAttachments()

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
		$source = (string)($headers['to'] ?? '');
		if ($displayTo !== '') {
			$source = $displayTo;
		}
		$recipients = [];
		$parts = preg_split('/[;,]/', $source);
		if ($parts === false) {
			$parts = [];
		}

		foreach ($parts as $entry) {
			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}

			if (preg_match('/<([^>]+)>/', $entry, $matches) === 1) {
				$recipients[] = trim($matches[1]);
				continue;
			}

			$recipients[] = $entry;
		}

		return $recipients;

	}//end recipients()

}//end class
