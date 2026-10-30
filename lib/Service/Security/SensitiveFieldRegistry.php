<?php

/**
 * Shared sensitive-field detection and redaction registry.
 *
 * Single source of truth for "does this field/header/param name look like a
 * secret", used by both CallService's CallLog redaction and every
 * ConfigurationHandler's export-time configuration redaction.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Service\Security;

/**
 * Detects secret-shaped field/header names and redacts matching values from
 * an array, irreversibly (masking, never encryption).
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
class SensitiveFieldRegistry {

	/**
	 * Placeholder written in place of a redacted value. Irreversible masking —
	 * never a reversible transform.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = '***REDACTED***';

	/**
	 * Header names that always carry credentials, matched case-insensitively
	 * as an exact match on the (last dot-segment of the) key name. Lifted
	 * verbatim from CallService::redactSecretsFromConfig().
	 *
	 * @var array<int, string>
	 */
	private const SECRET_HEADER_NAMES = [
		'authorization',
		'proxy-authorization',
		'cookie',
		'set-cookie',
	];

	/**
	 * Exact-match field-name denylist, lifted verbatim from
	 * SourceHandler::export()'s prior ad hoc top-level unset() list. Folded in
	 * as a supplementary exact-match set so fields that don't match the regex
	 * pattern below (e.g. a bare `username` field, not secret-shaped by
	 * pattern but sensitive in the Source context) keep being caught.
	 *
	 * @var array<int, string>
	 */
	private const EXACT_MATCH_NAMES = [
		'authorizationHeader',
		'auth',
		'authenticationConfig',
		'authorizationPassthroughMethod',
		'jwt',
		'jwtId',
		'secret',
		'username',
		'password',
		'apikey',
	];

	/**
	 * Secret-name regex pattern, lifted verbatim from
	 * CallService::isSecretKeyName().
	 *
	 * @var string
	 */
	private const SECRET_NAME_PATTERN = '/(token|key|secret|password|passwd|apikey|api[-_]?key|access[-_]?token'
		. '|bearer|auth|signature|assertion|private[-_]?key|x[-_]?api[-_]?token|client[-_]?secret)/i';

	/**
	 * Extra pattern for header names only: a session header under its own name
	 * (X-Session, X-Session-Id) is a credential on the wire. Kept out of
	 * SECRET_NAME_PATTERN because that pattern also walks payload fields,
	 * where a `sessionId` is a correlation id, not a secret.
	 *
	 * @var string
	 */
	private const SECRET_HEADER_PATTERN = '/session/i';

	/**
	 * Keys of a JSON response body that carry tokens an upstream issued,
	 * matched case-insensitively at any depth.
	 *
	 * @var array<int, string>
	 */
	private const ISSUED_TOKEN_KEYS = [
		'access_token',
		'refresh_token',
		'id_token',
	];

	/**
	 * Returns true when a field/header/param name looks like it carries a
	 * secret: an exact-match secret header name, a member of the exact-match
	 * denylist, or a match against the secret-name pattern. Case-insensitive
	 * throughout.
	 *
	 * @param string $name The key name to test.
	 *
	 * @return boolean Whether the name matches the sensitive-name detection rules.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function isSensitiveName(string $name): bool {
		$lower = strtolower($name);

		if (in_array($lower, self::SECRET_HEADER_NAMES, true) === true) {
			return true;
		}

		foreach (self::EXACT_MATCH_NAMES as $exactName) {
			if ($lower === strtolower($exactName)) {
				return true;
			}
		}

		return (preg_match(self::SECRET_NAME_PATTERN, $name) === 1);
	}//end isSensitiveName()

	/**
	 * Returns true when a header name looks like it carries a credential: every
	 * rule of {@see isSensitiveName()}, plus a session header.
	 *
	 * @param string $name The header name to test.
	 *
	 * @return boolean Whether the header must be masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	public function isSensitiveHeaderName(string $name): bool {
		if ($this->isSensitiveName(name: $name) === true) {
			return true;
		}

		return (preg_match(self::SECRET_HEADER_PATTERN, $name) === 1);
	}//end isSensitiveHeaderName()

	/**
	 * Masks the values of every sensitive header in a header map (name to
	 * value or to a PSR-7 list of values). Other headers are left as they are.
	 *
	 * @param array $headers The headers (walked by value; the caller's array is not mutated).
	 *
	 * @return array The headers with every sensitive value masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	public function redactHeaders(array $headers): array {
		$redacted = $this->redactArray(data: $headers);
		foreach ($redacted as $name => $value) {
			if (is_string($name) === true && $this->isSensitiveHeaderName(name: $name) === true) {
				$redacted[$name] = $this->maskValue(value: $value, extraExactNames: null);
			}
		}

		return $redacted;
	}//end redactHeaders()

	/**
	 * Masks the tokens an upstream issued (access_token, refresh_token,
	 * id_token) in a JSON body, at any depth. A body that is not a JSON object
	 * or array, or holds none of those keys, comes back unchanged.
	 *
	 * @param string $body The response body as text.
	 *
	 * @return string The body with every issued token masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	public function redactIssuedTokensInJson(string $body): string {
		if (preg_match('/(access|refresh|id)_token/i', $body) !== 1) {
			return $body;
		}

		// Decoded without assoc so an empty object stays `{}` when the body is written back.
		$decoded = json_decode($body);
		if (is_array($decoded) === false && is_object($decoded) === false) {
			return $body;
		}

		$changed = false;
		$masked = $this->maskIssuedTokens(data: $decoded, changed: $changed);
		if ($changed === false) {
			return $body;
		}

		$encoded = json_encode($masked, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			return $body;
		}

		return $encoded;
	}//end redactIssuedTokensInJson()

	/**
	 * Recursively walks an array and replaces the value of any key that
	 * matches {@see isSensitiveName()} with the irreversible placeholder. A
	 * list under such a key has every value masked; a map is walked by its own
	 * keys (see maskValue()).
	 *
	 * For a dotted key (e.g. a flat `headers.Authorization` key, as used by
	 * Source's dot-notation `configuration` storage), only the last
	 * dot-segment of the key is matched against the sensitive-name rules.
	 * Genuinely nested arrays (e.g. `['action' => ['headers' => [...]]]`, as
	 * used by Rule's `configuration`) are recursed into regardless of depth.
	 * Array key order and every non-matching key/value are left unchanged.
	 *
	 * @param array $data The array to redact (walked by value; the caller's array is not mutated).
	 * @param array<int, string>|null $extraExactNames Additional exact-match names to redact for this call, beyond the registry's built-in set.
	 *
	 * @return array The redacted array copy.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function redactArray(array $data, ?array $extraExactNames = null): array {
		foreach ($data as $key => $value) {
			if ($this->isSensitiveKey(key: (string)$key, extraExactNames: $extraExactNames) === true) {
				$data[$key] = $this->maskValue(value: $value, extraExactNames: $extraExactNames);
				continue;
			}

			if (is_array($value) === true) {
				$data[$key] = $this->redactArray(data: $value, extraExactNames: $extraExactNames);
			}
		}

		return $data;
	}//end redactArray()

	/**
	 * Whether a key names a secret: the last dot-segment of the key is matched
	 * against the sensitive-name rules and the call's extra exact names.
	 *
	 * @param string                  $key             The array key.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return boolean Whether the key's value must be masked.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	private function isSensitiveKey(string $key, ?array $extraExactNames): bool {
		$segments = explode('.', $key);
		$leafName = (string)end($segments);

		if ($this->isSensitiveName(name: $leafName) === true) {
			return true;
		}

		foreach (($extraExactNames ?? []) as $extraExactName) {
			if (strtolower($leafName) === strtolower($extraExactName)) {
				return true;
			}
		}

		return false;
	}//end isSensitiveKey()

	/**
	 * Masks a value held under a sensitive key.
	 *
	 * A scalar becomes the placeholder. A list (a PSR-7 header's values, a
	 * basic-auth pair) keeps its length and has every value masked at any
	 * depth: its integer keys say nothing, so it inherits its key's
	 * sensitivity. A map is walked like any other array, because its keys name
	 * their values: `authentication.credentialRef.credentialId` is a pointer
	 * that export must keep (REQ-010), `authentication.password` is not.
	 *
	 * @param mixed                   $value           The value under a sensitive key.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return mixed The masked value.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	private function maskValue(mixed $value, ?array $extraExactNames): mixed {
		if (is_array($value) === false) {
			return self::PLACEHOLDER;
		}

		if (array_is_list($value) === false) {
			return $this->redactArray(data: $value, extraExactNames: $extraExactNames);
		}

		return array_map(fn (mixed $item): mixed => $this->maskListItem(item: $item), $value);
	}//end maskValue()

	/**
	 * Masks the value of every issued-token key in a decoded JSON body. Objects
	 * stay objects and lists stay lists, so the body re-encodes as it came.
	 *
	 * @param array|object $data    The decoded body (json_decode without assoc).
	 * @param boolean      $changed Set to true when a value was masked.
	 *
	 * @return array|object The body with issued tokens masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	private function maskIssuedTokens(array|object $data, bool &$changed): array|object {
		if (is_object($data) === true) {
			foreach (get_object_vars($data) as $key => $value) {
				if (in_array(strtolower((string)$key), self::ISSUED_TOKEN_KEYS, true) === true) {
					$data->{$key} = self::PLACEHOLDER;
					$changed = true;
					continue;
				}

				if (is_array($value) === true || is_object($value) === true) {
					$data->{$key} = $this->maskIssuedTokens(data: $value, changed: $changed);
				}
			}

			return $data;
		}

		foreach ($data as $key => $value) {
			if (is_array($value) === true || is_object($value) === true) {
				$data[$key] = $this->maskIssuedTokens(data: $value, changed: $changed);
			}
		}

		return $data;
	}//end maskIssuedTokens()

	/**
	 * Masks one item of a list held under a sensitive key, at any depth.
	 *
	 * @param mixed $item The list item.
	 *
	 * @return mixed The placeholder, or the item's array with every value masked.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	private function maskListItem(mixed $item): mixed {
		if (is_array($item) === false) {
			return self::PLACEHOLDER;
		}

		return array_map(fn (mixed $inner): mixed => $this->maskListItem(item: $inner), $item);
	}//end maskListItem()
}//end class
