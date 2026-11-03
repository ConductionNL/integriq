<?php

/**
 * Redacts secrets that a configuration's structure shows: header sets and URLs.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Walks an array that SensitiveFieldRegistry already redacted by name, and
 * masks session headers in header maps, header-shaped keys and name/value
 * header lists, and the password and secret query values of URLs in any text.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
final class StructuredSecretRedactor {
	/**
	 * A name written the way HTTP header names are (`X-Session-Id`, `Content-Type`).
	 *
	 * @var string
	 */
	private const HEADER_SHAPED_NAME = '/^[A-Za-z0-9]+(-[A-Za-z0-9]+)+$/';

	/**
	 * The `scheme://user:password@` start of a URL; group 1 is everything before the password.
	 *
	 * @var string
	 */
	private const URL_USERINFO = '#^([a-z][a-z0-9+.\-]*://[^/@:?\#\s]*):[^/@?\#\s]*@#i';

	/**
	 * One `name=value` pair of a query string.
	 *
	 * @var string
	 */
	private const QUERY_PAIR = '/(^|&)([^=&#]+)=([^&#]*)/';

	/**
	 * Constructor.
	 *
	 * @param SensitiveFieldRegistry $registry The registry that knows secret-shaped names.
	 */
	public function __construct(
		private readonly SensitiveFieldRegistry $registry,
	) {
	}//end __construct()

	/**
	 * Masks a URL's password and the values of its secret-named query parameters.
	 *
	 * Text without a query string or a password is returned unchanged; the other
	 * query pairs keep their exact text.
	 *
	 * @param string $url The text that may hold a URL or a path with a query.
	 *
	 * @return string The text with its URL secrets masked.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function redactUrl(string $url): string {
		$url = (string)preg_replace(self::URL_USERINFO, '$1:' . SensitiveFieldRegistry::PLACEHOLDER . '@', $url);
		$queryStart = strpos($url, '?');
		if ($queryStart === false) {
			return $url;
		}

		$query = (string)preg_replace_callback(
			self::QUERY_PAIR,
			fn (array $pair): string => $this->maskedQueryPair(pair: $pair),
			substr($url, ($queryStart + 1))
		);

		return substr($url, 0, ($queryStart + 1)) . $query;
	}//end redactUrl()

	/**
	 * One query pair as text, its value masked when its name is secret-shaped.
	 *
	 * @param array<int, string> $pair The match: separator, name and value.
	 *
	 * @return string The pair.
	 */
	private function maskedQueryPair(array $pair): string {
		if ($this->registry->isSensitiveName(name: urldecode($pair[2])) === false) {
			return $pair[0];
		}

		return $pair[1] . $pair[2] . '=' . SensitiveFieldRegistry::PLACEHOLDER;
	}//end maskedQueryPair()

	/**
	 * Walks a name-redacted array and redacts what its structure shows to be secret.
	 *
	 * @param array $data The array.
	 *
	 * @return array The array with header and URL secrets masked.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function redact(array $data): array {
		foreach ($data as $key => $value) {
			$data[$key] = $this->redactEntry(key: $key, value: $value);
		}

		return $data;
	}//end redact()

	/**
	 * One redacted entry: a secret header, a header set, text that may hold a URL, or an array walked further.
	 *
	 * @param int|string $key   The entry key.
	 * @param mixed      $value The entry value.
	 *
	 * @return mixed The redacted value.
	 */
	private function redactEntry(int|string $key, mixed $value): mixed {
		if (is_string($key) === true && $this->isSensitiveHeaderShapedName(name: $key) === true) {
			return $this->registry->redactHeaders(headers: [$key => $value])[$key];
		}

		if (is_string($value) === true) {
			return $this->redactUrl(url: $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		if (strtolower((string)$key) === 'headers') {
			$value = $this->redactHeaderSet(headers: $value);
		}

		return $this->redact(data: $value);
	}//end redactEntry()

	/**
	 * Whether a name is written as an HTTP header name and names a secret header.
	 *
	 * @param string $name The key name.
	 *
	 * @return boolean
	 */
	private function isSensitiveHeaderShapedName(string $name): bool {
		return preg_match(self::HEADER_SHAPED_NAME, $name) === 1 && $this->registry->isSensitiveHeaderName(name: $name) === true;
	}//end isSensitiveHeaderShapedName()

	/**
	 * Redacts a header set: a name-to-values map, or a list of name/value pairs.
	 *
	 * @param array $headers The header set.
	 *
	 * @return array The header set with secret header values masked.
	 */
	private function redactHeaderSet(array $headers): array {
		if (array_is_list($headers) === false) {
			return $this->registry->redactHeaders(headers: $headers);
		}

		return array_map(fn (mixed $item): mixed => $this->redactHeaderPair(item: $item), $headers);
	}//end redactHeaderSet()

	/**
	 * One `{name, value}` header pair, its value masked when the name is a secret header.
	 *
	 * @param mixed $item The list item.
	 *
	 * @return mixed The item.
	 */
	private function redactHeaderPair(mixed $item): mixed {
		if (is_array($item) === false || is_string($item['name'] ?? null) === false || array_key_exists('value', $item) === false) {
			return $item;
		}

		$item['value'] = $this->registry->redactHeaders(headers: [$item['name'] => $item['value']])[$item['name']];
		return $item;
	}//end redactHeaderPair()
}//end class
