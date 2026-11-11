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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

/**
 * Parses RFC 5322 messages into a {@see ParsedMessage}.
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class EmlParser {

	/**
	 * Parse one raw RFC 5322 message.
	 *
	 * @param string $raw The raw message bytes.
	 *
	 * @return ParsedMessage The parsed message.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function parse(string $raw): ParsedMessage {
		$normalised = str_replace(["\r\n", "\r"], "\n", $raw);
		[$headerBlock, $body] = $this->split(message: $normalised);
		$headers = MailHeaders::parse(headerBlock: $headerBlock);

		$collected = new MimeCollection();
		$this->walkPart(part: new MimePart(headers: $headers, body: $body), collected: $collected);

		$messageId = trim((string)($headers['message-id'] ?? ''), " <>\t");
		if ($messageId === '') {
			$messageId = 'sha256:' . hash('sha256', $raw);
		}

		return new ParsedMessage(
			$messageId,
			$this->firstAddress(header: (string)($headers['from'] ?? '')),
			$this->addressList(header: (string)($headers['to'] ?? '')),
			$this->decodeWords(value: (string)($headers['subject'] ?? '')),
			MailHeaders::date(header: (string)($headers['date'] ?? '')),
			$collected->text,
			HtmlSanitizer::sanitize($collected->html),
			$collected->attachments,
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
	 * Walk one MIME part, appending bodies and attachments to the collection.
	 *
	 * @param MimePart       $part      The part.
	 * @param MimeCollection $collected The collection.
	 *
	 * @return void
	 */
	private function walkPart(MimePart $part, MimeCollection $collected): void {
		if (str_starts_with($this->mimeType(headers: $part->headers), 'multipart/') === true) {
			$this->walkMultipart(part: $part, collected: $collected);
			return;
		}

		$this->collectLeaf(part: $part, collected: $collected);

	}//end walkPart()

	/**
	 * Walk every sub-part of a multipart part; one without a boundary yields nothing.
	 *
	 * @param MimePart       $part      The multipart part.
	 * @param MimeCollection $collected The collection.
	 *
	 * @return void
	 */
	private function walkMultipart(MimePart $part, MimeCollection $collected): void {
		$boundary = $this->parameter(headerValue: (string)($part->headers['content-type'] ?? ''), name: 'boundary');
		if ($boundary === null) {
			return;
		}

		foreach ($this->splitMultipart(body: $part->body, boundary: $boundary) as $rawPart) {
			[$partHeaders, $partBody] = $this->split(message: $rawPart);
			$this->walkPart(part: new MimePart(headers: MailHeaders::parse(headerBlock: $partHeaders), body: $partBody), collected: $collected);
		}

	}//end walkMultipart()

	/**
	 * Collect a leaf part: an inline text or HTML body, or else an attachment.
	 *
	 * @param MimePart       $part      The leaf part.
	 * @param MimeCollection $collected The collection.
	 *
	 * @return void
	 */
	private function collectLeaf(MimePart $part, MimeCollection $collected): void {
		$mime = $this->mimeType(headers: $part->headers);
		$decoded = $this->decodeBody(body: $part->body, encoding: strtolower((string)($part->headers['content-transfer-encoding'] ?? '7bit')));
		$isAttachment = $this->isAttachment(headers: $part->headers);

		if ($isAttachment === false && $mime === 'text/plain') {
			$collected->text .= $decoded;
			return;
		}

		if ($isAttachment === false && $mime === 'text/html') {
			$collected->html .= $decoded;
			return;
		}

		$collected->attachments[] = $this->attachment(part: $part, decoded: $decoded);

	}//end collectLeaf()

	/**
	 * The part's lower-cased MIME type without parameters; `text/plain` when absent.
	 *
	 * @param array<string,string> $headers The part headers.
	 *
	 * @return string The MIME type, empty when the header names none.
	 */
	private function mimeType(array $headers): string {
		$contentType = strtolower((string)($headers['content-type'] ?? 'text/plain'));

		return trim(explode(';', $contentType)[0]);

	}//end mimeType()

	/**
	 * The part's file name: the disposition's `filename`, else the content type's `name`.
	 *
	 * @param array<string,string> $headers The part headers.
	 *
	 * @return string|null The file name, or null when it has none.
	 */
	private function filename(array $headers): ?string {
		$filename = $this->parameter(headerValue: (string)($headers['content-disposition'] ?? ''), name: 'filename');
		if ($filename === null) {
			$filename = $this->parameter(headerValue: (string)($headers['content-type'] ?? ''), name: 'name');
		}

		return $filename;

	}//end filename()

	/**
	 * Whether the part is an attachment: disposed as one, or carrying a file name.
	 *
	 * @param array<string,string> $headers The part headers.
	 *
	 * @return bool True for an attachment.
	 */
	private function isAttachment(array $headers): bool {
		$disposition = strtolower((string)($headers['content-disposition'] ?? ''));

		return (str_contains($disposition, 'attachment') === true || $this->filename(headers: $headers) !== null);

	}//end isAttachment()

	/**
	 * The attachment a leaf part becomes; a part without a MIME type is `application/octet-stream`.
	 *
	 * @param MimePart $part    The leaf part.
	 * @param string   $decoded The decoded body.
	 *
	 * @return array{name: string, mime: string, size: int, content: string} The attachment.
	 */
	private function attachment(MimePart $part, string $decoded): array {
		$mime = $this->mimeType(headers: $part->headers);
		if ($mime === '') {
			$mime = 'application/octet-stream';
		}

		return [
			'name' => ($this->filename(headers: $part->headers) ?? 'attachment'),
			'mime' => $mime,
			'size' => strlen($decoded),
			'content' => $decoded,
		];

	}//end attachment()

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

}//end class
