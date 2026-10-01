<?php

/**
 * Integriq Response Decoder.
 *
 * Turns a response body into data, in a mode the caller names or the content
 * type implies. One class for every response path that reads a body for a
 * flow: the `openconnector.source-call` step and the synchronization page
 * fetch both call it, so a YAML file reads the same whichever path fetched it.
 *
 * WHY YAML IS EXPLICIT FOR text/plain
 * -----------------------------------
 * `raw.githubusercontent.com` serves every file as `text/plain`. Sniffing YAML
 * there would be wrong for everything else: plain text is valid YAML, so any
 * text reply would decode to a string scalar and look like success. A caller
 * that reads a raw file says `yaml`; `auto` only treats a body as YAML when
 * the server says it is YAML.
 *
 * WHY THE FLAGS ARE WHAT THEY ARE
 * -------------------------------
 * The body comes from an upstream, so it is untrusted. Symfony's parser builds
 * PHP objects for `!php/object` only with `PARSE_OBJECT`, reads constants and
 * enums only with `PARSE_CONSTANT`, and accepts other tags only with
 * `PARSE_CUSTOM_TAGS`. None of those is passed. `PARSE_EXCEPTION_ON_INVALID_TYPE`
 * is, because without it Symfony quietly turns a refused `!php/object` into
 * null, and a value that vanished is the failure this class exists to stop.
 * `PARSE_DATETIME` builds only `DateTimeImmutable`, which is converted back to
 * an ISO 8601 string before the data leaves here.
 *
 * WHY A FAILURE THROWS
 * --------------------
 * A body that cannot be read raises {@see ResponseDecodeException}. Returning
 * an empty array would make an unreadable file and an empty one the same
 * thing, and the step after this one would map nothing and report success.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeInterface;
use OCA\Integriq\Exception\ResponseDecodeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

/**
 * Decodes response bodies as JSON, YAML or base64-wrapped JSON or YAML.
 *
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
 */
class ResponseDecoder {

	/**
	 * Today's behaviour: JSON when it parses, YAML when the server says YAML,
	 * otherwise the string as it came.
	 *
	 * @var string
	 */
	public const MODE_AUTO = 'auto';

	/**
	 * The body is JSON.
	 *
	 * @var string
	 */
	public const MODE_JSON = 'json';

	/**
	 * The body is YAML.
	 *
	 * @var string
	 */
	public const MODE_YAML = 'yaml';

	/**
	 * The body, or its `content` field, is base64-encoded YAML.
	 *
	 * @var string
	 */
	public const MODE_BASE64_YAML = 'base64+yaml';

	/**
	 * The body, or its `content` field, is base64-encoded JSON.
	 *
	 * @var string
	 */
	public const MODE_BASE64_JSON = 'base64+json';

	/**
	 * The body is kept as text, unparsed.
	 *
	 * @var string
	 */
	public const MODE_TEXT = 'text';

	/**
	 * Every mode a caller may name.
	 *
	 * @var array<int, string>
	 */
	public const MODES = [
		self::MODE_AUTO,
		self::MODE_JSON,
		self::MODE_YAML,
		self::MODE_BASE64_YAML,
		self::MODE_BASE64_JSON,
		self::MODE_TEXT,
	];

	/**
	 * Content types that announce YAML.
	 *
	 * @var array<int, string>
	 */
	public const YAML_CONTENT_TYPES = [
		'application/yaml',
		'application/x-yaml',
		'text/yaml',
		'text/x-yaml',
	];

	/**
	 * The largest body this class will parse, in bytes.
	 *
	 * A publiccode.yml is a few kilobytes. A body a thousand times that size is
	 * not a configuration file, and parsing it would hold the worker's memory
	 * for nothing.
	 *
	 * @var integer
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Decode a body in the given mode.
	 *
	 * @param string      $body        The response body as received.
	 * @param string      $mode        One of {@see self::MODES}.
	 * @param string|null $contentType The response's Content-Type header, when known.
	 *
	 * @return mixed The decoded value, or the string for `text` and an unparsed `auto`.
	 *
	 * @throws ResponseDecodeException When the body cannot be decoded in that mode.
	 *
	 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public function decode(string $body, string $mode=self::MODE_AUTO, ?string $contentType=null): mixed {
		$mode = strtolower(trim($mode));
		if ($mode === '') {
			$mode = self::MODE_AUTO;
		}

		if (in_array($mode, self::MODES, true) === false) {
			throw new ResponseDecodeException(mode: $mode, reason: 'this is not a decode mode');
		}

		return match ($mode) {
			self::MODE_TEXT => $body,
			self::MODE_AUTO => $this->decodeAuto(body: $body, contentType: $contentType),
			self::MODE_JSON => $this->parseJson(body: $body, mode: $mode),
			self::MODE_YAML => $this->parseYaml(body: $body, mode: $mode),
			self::MODE_BASE64_YAML => $this->parseYaml(body: $this->unwrapBase64(body: $body, mode: $mode), mode: $mode),
			self::MODE_BASE64_JSON => $this->parseJson(body: $this->unwrapBase64(body: $body, mode: $mode), mode: $mode),
		};

	}//end decode()

	/**
	 * Whether a Content-Type header announces YAML.
	 *
	 * @param string|null $contentType The header value, parameters included.
	 *
	 * @return boolean Whether it is one of {@see self::YAML_CONTENT_TYPES}.
	 *
	 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public function isYamlContentType(?string $contentType): bool {
		if ($contentType === null) {
			return false;
		}

		$type = strtolower(trim(explode(';', $contentType)[0]));

		return in_array($type, self::YAML_CONTENT_TYPES, true);

	}//end isYamlContentType()

	/**
	 * Find a header's value in a response header map, ignoring case.
	 *
	 * Guzzle keeps a header's case as the server sent it, and HTTP/2 servers
	 * send lower case. A lookup by exact key misses one or the other.
	 *
	 * @param array<string, mixed> $headers The header map; values may be lists.
	 * @param string               $name    The header name.
	 *
	 * @return string|null The first value, or null when the header is absent.
	 *
	 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public static function headerValue(array $headers, string $name): ?string {
		foreach ($headers as $key => $value) {
			if (strcasecmp((string)$key, $name) !== 0) {
				continue;
			}

			if (is_array($value) === true) {
				$value = (reset($value) ?? null);
			}

			if ($value === null || $value === false) {
				return null;
			}

			return (string)$value;
		}

		return null;

	}//end headerValue()

	/**
	 * Decode without a named mode, the way responses always were.
	 *
	 * @param string      $body        The body.
	 * @param string|null $contentType The Content-Type header.
	 *
	 * @return mixed The decoded value, or the body unchanged.
	 *
	 * @throws ResponseDecodeException When the server said YAML and the body is not.
	 */
	private function decodeAuto(string $body, ?string $contentType): mixed {
		if (trim($body) === '') {
			return $body;
		}

		if ($this->isYamlContentType(contentType: $contentType) === true) {
			return $this->parseYaml(body: $body, mode: self::MODE_YAML);
		}

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $body;
		}

		return $decoded;

	}//end decodeAuto()

	/**
	 * Parse JSON, or fail with the parser's reason.
	 *
	 * @param string $body The JSON text.
	 * @param string $mode The mode, for the failure message.
	 *
	 * @return mixed The decoded value.
	 *
	 * @throws ResponseDecodeException When the body is empty, too large or not JSON.
	 */
	private function parseJson(string $body, string $mode): mixed {
		$this->assertReadable(body: $body, mode: $mode);

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new ResponseDecodeException(mode: $mode, reason: json_last_error_msg());
		}

		return $decoded;

	}//end parseJson()

	/**
	 * Parse YAML safely, or fail with the parser's reason.
	 *
	 * @param string $body The YAML text.
	 * @param string $mode The mode, for the failure message.
	 *
	 * @return mixed The decoded value, with every date as an ISO 8601 string.
	 *
	 * @throws ResponseDecodeException When the body is empty, too large, not YAML or carries a tag.
	 */
	private function parseYaml(string $body, string $mode): mixed {
		$this->assertReadable(body: $body, mode: $mode);

		try {
			$parsed = (new Parser())->parse($body, (Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE));
		} catch (ParseException $exception) {
			throw new ResponseDecodeException(mode: $mode, reason: $exception->getMessage(), previous: $exception);
		}

		return $this->normaliseDates(value: $parsed);

	}//end parseYaml()

	/**
	 * Take the base64 payload out of a body and decode it.
	 *
	 * The payload is either the whole body, or the `content` field of a JSON
	 * object, which is how GitHub's contents API and most file APIs wrap it.
	 * Whitespace is stripped first: GitHub breaks its base64 every 60 characters.
	 *
	 * @param string $body The body.
	 * @param string $mode The mode, for the failure message.
	 *
	 * @return string The decoded bytes.
	 *
	 * @throws ResponseDecodeException When there is no payload or it is not base64.
	 */
	private function unwrapBase64(string $body, string $mode): string {
		$this->assertReadable(body: $body, mode: $mode);

		$payload = $body;
		$envelope = json_decode($body, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($envelope) === true) {
			if (is_string($envelope['content'] ?? null) === false) {
				throw new ResponseDecodeException(
					mode: $mode,
					reason: 'the body is a JSON object without a string "content" field'
				);
			}

			$encoding = strtolower((string)($envelope['encoding'] ?? 'base64'));
			if ($encoding !== 'base64') {
				throw new ResponseDecodeException(
					mode: $mode,
					reason: sprintf('the "content" field is encoded as "%s", not base64', $encoding)
				);
			}

			$payload = $envelope['content'];
		}

		$decoded = base64_decode((string)preg_replace('/\s+/', '', $payload), true);
		if ($decoded === false) {
			throw new ResponseDecodeException(mode: $mode, reason: 'the payload is not valid base64');
		}

		return $decoded;

	}//end unwrapBase64()

	/**
	 * Refuse a body that is empty or too large to parse.
	 *
	 * @param string $body The body.
	 * @param string $mode The mode, for the failure message.
	 *
	 * @return void
	 *
	 * @throws ResponseDecodeException When the body is empty or over {@see self::MAX_BYTES}.
	 */
	private function assertReadable(string $body, string $mode): void {
		if (trim($body) === '') {
			throw new ResponseDecodeException(mode: $mode, reason: 'the body is empty');
		}

		if (strlen($body) > self::MAX_BYTES) {
			throw new ResponseDecodeException(
				mode: $mode,
				reason: sprintf('the body is %d bytes, over the limit of %d', strlen($body), self::MAX_BYTES)
			);
		}

	}//end assertReadable()

	/**
	 * Turn every date the YAML parser built back into a string.
	 *
	 * A date at midnight UTC, which is how Symfony reads a bare `2024-01-31`,
	 * becomes `Y-m-d`. Anything else becomes ISO 8601 with its offset.
	 *
	 * @param mixed $value The parsed value.
	 *
	 * @return mixed The value with no DateTimeInterface left in it.
	 */
	private function normaliseDates(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			if ($value->format('H:i:s') === '00:00:00' && $value->getOffset() === 0) {
				return $value->format('Y-m-d');
			}

			return $value->format(DateTimeInterface::ATOM);
		}

		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->normaliseDates(value: $item);
			}
		}

		return $value;

	}//end normaliseDates()
}//end class
