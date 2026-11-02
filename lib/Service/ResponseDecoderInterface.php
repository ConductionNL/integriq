<?php

/**
 * Contract of the ResponseDecoder.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ResponseDecodeException;

/**
 * Public operations of {@see ResponseDecoder} that other components call.
 */
interface ResponseDecoderInterface {
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
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public function decode(string $body, string $mode=self::MODE_AUTO, ?string $contentType=null): mixed;

	/**
	 * Whether a Content-Type header announces YAML.
	 *
	 * @param string|null $contentType The header value, parameters included.
	 *
	 * @return boolean Whether it is one of {@see ResponseDecoder::YAML_CONTENT_TYPES}.
	 *
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public function isYamlContentType(?string $contentType): bool;

	/**
	 * Find a response's Content-Type in its header map, ignoring case.
	 *
	 * @param array<string, mixed> $headers The header map; values may be lists.
	 *
	 * @return string|null The first Content-Type value, or null when it is absent.
	 *
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
	 */
	public function contentType(array $headers): ?string;
}
