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
 * Walks a configuration before SensitiveFieldRegistry redacts it by name, and
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
	 * The `scheme://userinfo@` part of a URL anywhere in a text; group 1 is the
	 * scheme with its `://`, group 2 the userinfo up to the last `@` of the
	 * authority, so a password that holds an `@` is taken whole.
	 *
	 * @var string
	 */
	private const URL_USERINFO = '#([a-z][a-z0-9+.\-]*://)([^/?\#\s]*)@#i';

	/**
	 * One `name=value` pair of a query string, separated by `&` or `;`.
	 *
	 * @var string
	 */
	private const QUERY_PAIR = '/(^|[&;])([^=&;#]+)=([^&;#]*)/';

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
		$encoded = $this->redactEncodedUrl(value: $url);
		if ($encoded !== null) {
			return $encoded;
		}

		$url = (string)preg_replace_callback(
			self::URL_USERINFO,
			fn (array $userinfo): string => $this->maskedUserinfo(userinfo: $userinfo),
			$url
		);

		foreach (['#', '?'] as $separator) {
			$start = strpos($url, $separator);
			if ($start !== false) {
				$url = substr($url, 0, ($start + 1)) . $this->maskedPairs(pairs: substr($url, ($start + 1)));
			}
		}

		return $url;
	}//end redactUrl()

	/**
	 * The pairs of a query string or fragment, each secret-named value masked.
	 *
	 * Text from a `#` on is left to the fragment pass.
	 *
	 * @param string $pairs The text after `?` or `#`.
	 *
	 * @return string The pairs.
	 */
	private function maskedPairs(string $pairs): string {
		$fragment = strpos($pairs, '#');
		$tail = '';
		if ($fragment !== false) {
			$tail = substr($pairs, $fragment);
			$pairs = substr($pairs, 0, $fragment);
		}

		return (string)preg_replace_callback(
			self::QUERY_PAIR,
			fn (array $pair): string => $this->maskedQueryPair(pair: $pair),
			$pairs
		) . $tail;
	}//end maskedPairs()

	/**
	 * A percent-encoded URL with its secrets masked, encoded again; null when the
	 * value is not an encoded URL or holds no secret.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null The masked text, or null.
	 */
	private function redactEncodedUrl(string $value): ?string {
		if (str_contains($value, '://') === true || stripos($value, '%3A%2F%2F') === false) {
			return null;
		}

		$decoded = rawurldecode($value);
		$masked = $this->redactUrl(url: $decoded);
		if ($masked === $decoded) {
			return null;
		}

		return rawurlencode($masked);
	}//end redactEncodedUrl()

	/**
	 * One query pair as text, its value masked when its name is secret-shaped.
	 *
	 * @param array<int, string> $pair The match: separator, name and value.
	 *
	 * @return string The pair.
	 */
	private function maskedQueryPair(array $pair): string {
		if ($this->registry->isSensitiveName(name: urldecode($pair[2])) === false) {
			$nested = $this->redactEncodedUrl(value: $pair[3]);
			if ($nested === null) {
				return $pair[0];
			}

			return $pair[1] . $pair[2] . '=' . $nested;
		}

		if ($this->registry->isPureTemplate(value: urldecode($pair[3])) === true) {
			return $pair[0];
		}

		return $pair[1] . $pair[2] . '=' . SensitiveFieldRegistry::PLACEHOLDER;
	}//end maskedQueryPair()

	/**
	 * A URL's `scheme://userinfo@` part with the password masked, or the whole
	 * userinfo when it has no password (a token written as the user), unless the
	 * masked part is a template.
	 *
	 * @param array<int, string> $userinfo The match: the part, the scheme with `://`, and the userinfo.
	 *
	 * @return string The part.
	 */
	private function maskedUserinfo(array $userinfo): string {
		$colon = strpos($userinfo[2], ':');
		$user = '';
		$secret = $userinfo[2];
		if ($colon !== false) {
			$user = substr($userinfo[2], 0, $colon) . ':';
			$secret = substr($userinfo[2], ($colon + 1));
		}

		if ($secret === '' || $this->registry->isPureTemplate(value: $secret) === true) {
			return $userinfo[0];
		}

		return $userinfo[1] . $user . SensitiveFieldRegistry::PLACEHOLDER . '@';
	}//end maskedUserinfo()

	/**
	 * Walks an array and redacts what its structure shows to be secret.
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
		if (is_string($key) === true && $this->namesASecretHeader(key: $key) === true) {
			$leaf = substr((string)strrchr('.' . $key, '.'), 1);
			return $this->registry->redactHeaders(headers: [$leaf => $value])[$leaf];
		}

		if (is_string($value) === true) {
			return $this->redactUrl(url: $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		if (str_ends_with(strtolower((string)$key), 'headers') === true) {
			$value = $this->redactHeaderSet(headers: $value);
		}

		return $this->redact(data: $value);
	}//end redactEntry()

	/**
	 * Whether a key names a secret header: written as a header name
	 * (`X-Session-Id`), or as a dotted path whose earlier part is a header set
	 * (`headers.X-Session-Id`, `headers.Session`).
	 *
	 * @param string $key The key.
	 *
	 * @return boolean
	 */
	private function namesASecretHeader(string $key): bool {
		$segments = explode('.', $key);
		$leaf = (string)array_pop($segments);
		foreach ($segments as $segment) {
			if (str_ends_with(strtolower($segment), 'headers') === true) {
				return $this->registry->isSensitiveHeaderName(name: $leaf);
			}
		}

		return $this->isSensitiveHeaderShapedName(name: $leaf);
	}//end namesASecretHeader()

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
	 * One header pair (`{name, value}`, `{Name, Value}`, `{key, value}`), its value
	 * masked when the name is a secret header.
	 *
	 * @param mixed $item The list item.
	 *
	 * @return mixed The item.
	 */
	private function redactHeaderPair(mixed $item): mixed {
		if (is_array($item) === false) {
			return $item;
		}

		$fields = $this->pairFields(item: $item);
		if ($fields === null) {
			return $item;
		}

		[$nameKey, $valueKey] = $fields;
		$item[$valueKey] = $this->registry->redactHeaders(headers: [$item[$nameKey] => $item[$valueKey]])[$item[$nameKey]];
		return $item;
	}//end redactHeaderPair()

	/**
	 * The keys of a header pair's name and value, whatever their case, or null
	 * when the item is not a pair with a text name.
	 *
	 * @param array $item The list item.
	 *
	 * @return array{0: int|string, 1: int|string}|null
	 */
	private function pairFields(array $item): ?array {
		$fields = [];
		foreach (array_keys($item) as $field) {
			$fields[strtolower((string)$field)] = $field;
		}

		$nameKey = ($fields['name'] ?? $fields['key'] ?? null);
		if ($nameKey === null || isset($fields['value']) === false || is_string($item[$nameKey]) === false) {
			return null;
		}

		return [$nameKey, $fields['value']];
	}//end pairFields()
}//end class
