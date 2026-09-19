<?php

/**
 * Integriq EmlParser.
 *
 * An RFC 5322 / MIME reader with no network and no shell-outs: headers are
 * unfolded, encoded words decoded, multipart bodies walked, and every part
 * that is not a body becomes an attachment. Nothing is dropped; a part the
 * reader does not understand is kept as an attachment with its raw bytes.
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

use DateTimeImmutable;
use Throwable;

/**
 * Parses RFC 5322 messages into a {@see ParsedMessage}.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class EmlParser {

	/**
	 * Parse one raw RFC 5322 message.
	 *
	 * @param string $raw The raw message bytes.
	 *
	 * @return ParsedMessage The parsed message.
	 */
	public function parse(string $raw): ParsedMessage {
		$normalised = str_replace(["\r\n", "\r"], "\n", $raw);
		[$headerBlock, $body] = $this->split(message: $normalised);
		$headers = $this->parseHeaders(headerBlock: $headerBlock);

		$collected = [
			'text' => '',
			'html' => '',
			'attachments' => [],
		];
		$this->walkPart(headers: $headers, body: $body, collected: $collected);

		$messageId = trim((string)($headers['message-id'] ?? ''), " <>\t");
		if ($messageId === '') {
			$messageId = 'sha256:' . hash('sha256', $raw);
		}

		return new ParsedMessage(
			$messageId,
			$this->firstAddress(header: (string)($headers['from'] ?? '')),
			$this->addressList(header: (string)($headers['to'] ?? '')),
			$this->decodeWords(value: (string)($headers['subject'] ?? '')),
			$this->parseDate(header: (string)($headers['date'] ?? '')),
			$collected['text'],
			HtmlSanitizer::sanitize($collected['html']),
			$collected['attachments'],
		);

	}//end parse()

	/**
	 * Split a message into its header block and its body.
	 *
	 * @param string $message The LF-normalised message.
	 *
	 * @return array{0:string,1:string} Header block and body.
	 */
	private function split(string $message): array {
		$position = strpos($message, "\n\n");
		if ($position === false) {
			return [$message, ''];
		}

		return [substr($message, 0, $position), substr($message, ($position + 2))];

	}//end split()

	/**
	 * Parse and unfold a header block.
	 *
	 * Repeated headers keep the first occurrence, which is what a reader wants
	 * for `Received` and friends.
	 *
	 * @param string $headerBlock The raw header block.
	 *
	 * @return array<string,string> Lower-cased header names to values.
	 */
	private function parseHeaders(string $headerBlock): array {
		$headers = [];
		$current = null;
		foreach (explode("\n", $headerBlock) as $line) {
			if ($line === '') {
				continue;
			}

			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
				continue;
			}

			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}

			$name = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, ($colon + 1)));
			if (isset($headers[$name]) === false) {
				$headers[$name] = $value;
			}

			$current = $name;
		}

		return $headers;

	}//end parseHeaders()

	/**
	 * Walk one MIME part, appending bodies and attachments to the collector.
	 *
	 * @param array<string,string> $headers The part headers.
	 * @param string $body The part body.
	 * @param array<string,mixed> $collected The collector, by reference.
	 *
	 * @return void
	 */
	private function walkPart(array $headers, string $body, array &$collected): void {
		$contentType = strtolower((string)($headers['content-type'] ?? 'text/plain'));
		$mime = trim(explode(';', $contentType)[0]);
		$disposition = strtolower((string)($headers['content-disposition'] ?? ''));
		$filename = $this->parameter(headerValue: (string)($headers['content-disposition'] ?? ''), name: 'filename');
		if ($filename === null) {
			$filename = $this->parameter(headerValue: (string)($headers['content-type'] ?? ''), name: 'name');
		}

		if (str_starts_with($mime, 'multipart/') === true) {
			$boundary = $this->parameter(headerValue: (string)($headers['content-type'] ?? ''), name: 'boundary');
			if ($boundary === null) {
				return;
			}

			foreach ($this->splitMultipart(body: $body, boundary: $boundary) as $rawPart) {
				[$partHeaders, $partBody] = $this->split(message: $rawPart);
				$this->walkPart(headers: $this->parseHeaders(headerBlock: $partHeaders), body: $partBody, collected: $collected);
			}

			return;
		}

		$decoded = $this->decodeBody(body: $body, encoding: strtolower((string)($headers['content-transfer-encoding'] ?? '7bit')));
		$isAttachment = (str_contains($disposition, 'attachment') === true || $filename !== null);

		if ($isAttachment === false && $mime === 'text/plain') {
			$collected['text'] .= $decoded;
			return;
		}

		if ($isAttachment === false && $mime === 'text/html') {
			$collected['html'] .= $decoded;
			return;
		}

		$mimeType = $mime;
		if ($mime === '') {
			$mimeType = 'application/octet-stream';
		}

		$collected['attachments'][] = [
			'name' => ($filename ?? 'attachment'),
			'mime' => $mimeType,
			'size' => strlen($decoded),
			'content' => $decoded,
		];

	}//end walkPart()

	/**
	 * Split a multipart body on its boundary.
	 *
	 * @param string $body The multipart body.
	 * @param string $boundary The boundary without dashes.
	 *
	 * @return array<int,string> The raw parts, preamble and epilogue removed.
	 */
	private function splitMultipart(string $body, string $boundary): array {
		$marker = '--' . $boundary;
		$chunks = explode($marker, $body);
		array_shift($chunks);

		$parts = [];
		foreach ($chunks as $chunk) {
			if (str_starts_with($chunk, '--') === true) {
				break;
			}

			$parts[] = ltrim($chunk, "\n");
		}

		return $parts;

	}//end splitMultipart()

	/**
	 * Decode a transfer-encoded body.
	 *
	 * @param string $body The encoded body.
	 * @param string $encoding The `Content-Transfer-Encoding` value.
	 *
	 * @return string The decoded body.
	 */
	private function decodeBody(string $body, string $encoding): string {
		$encoding = trim($encoding);
		if (str_contains($encoding, 'base64') === true) {
			return (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '', false);
		}

		if (str_contains($encoding, 'quoted-printable') === true) {
			return quoted_printable_decode($body);
		}

		return $body;

	}//end decodeBody()

	/**
	 * Read one parameter out of a structured header value.
	 *
	 * @param string $headerValue The header value.
	 * @param string $name The parameter name.
	 *
	 * @return string|null The parameter value, or null when absent.
	 */
	private function parameter(string $headerValue, string $name): ?string {
		$pattern = '/;\s*' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|([^;\s]+))/i';
		if (preg_match($pattern, $headerValue, $matches) !== 1) {
			return null;
		}

		$value = ($matches[3] ?? '');
		if ($matches[2] !== '') {
			$value = $matches[2];
		}

		return $this->decodeWords(value: trim($value));

	}//end parameter()

	/**
	 * Decode RFC 2047 encoded words in a header value.
	 *
	 * @param string $value The header value.
	 *
	 * @return string The decoded value.
	 */
	private function decodeWords(string $value): string {
		if (str_contains($value, '=?') === false) {
			return $value;
		}

		return (string)preg_replace_callback(
			'/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
			function (array $matches): string {
				$text = $matches[3];
				if (strtolower($matches[2]) === 'b') {
					$text = (string)base64_decode($text, false);
				} else {
					$text = quoted_printable_decode(str_replace('_', ' ', $text));
				}

				$charset = strtoupper($matches[1]);
				if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding') === true) {
					$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
					if (is_string($converted) === true) {
						return $converted;
					}
				}

				return $text;
			},
			$value
		);

	}//end decodeWords()

	/**
	 * The first address in an address header.
	 *
	 * @param string $header The header value.
	 *
	 * @return string The bare address, or an empty string.
	 */
	private function firstAddress(string $header): string {
		$addresses = $this->addressList(header: $header);
		return ($addresses[0] ?? '');

	}//end firstAddress()

	/**
	 * Every bare address in an address header.
	 *
	 * @param string $header The header value.
	 *
	 * @return array<int,string> The addresses.
	 */
	private function addressList(string $header): array {
		$header = $this->decodeWords(value: $header);
		$addresses = [];
		foreach (explode(',', $header) as $entry) {
			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}

			if (preg_match('/<([^>]+)>/', $entry, $matches) === 1) {
				$addresses[] = trim($matches[1]);
				continue;
			}

			$addresses[] = $entry;
		}

		return $addresses;

	}//end addressList()

	/**
	 * Parse a `Date` header into an ISO 8601 timestamp.
	 *
	 * @param string $header The header value.
	 *
	 * @return string|null The timestamp, or null when the header is absent or unreadable.
	 */
	private function parseDate(string $header): ?string {
		if (trim($header) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($header))->format('c');
		} catch (Throwable) {
			return null;
		}

	}//end parseDate()

}//end class
