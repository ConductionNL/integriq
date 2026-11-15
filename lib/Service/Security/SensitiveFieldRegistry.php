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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\Security;

/**
 * Detects secret-shaped field/header names and redacts matching values from
 * an array, irreversibly (masking, never encryption).
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
class SensitiveFieldRegistry implements SecretRedactorInterface {

	/**
	 * Placeholder written in place of a redacted value. Irreversible masking —
	 * never a reversible transform.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = '***REDACTED***';

	/**
	 * Keys whose map is an API-key table (`keys: {"<api key>": "<user>"}`):
	 * its own keys are the secret, whatever their shape.
	 *
	 * @var array<int, string>
	 */
	private const CREDENTIAL_TABLE_KEYS = ['keys', 'apiKeys'];

	/**
	 * The only keys a map under a CREDENTIAL_TABLE_KEYS key may hold and still be
	 * read per key: a reference to a stored key (`apiKeys: {"id": "<uuid>"}`).
	 *
	 * @var array<int, string>
	 */
	private const KEY_REFERENCE_KEYS = ['id', 'uuid', 'value', 'credentialRef'];

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
	 * Secret-name regex pattern: CallService::isSecretKeyName()'s names, plus the
	 * names a client certificate and its passphrase are stored under.
	 *
	 * @var string
	 */
	private const SENSITIVE_NAME_PATTERN = '/(token|key|secret|password|passwd|apikey|api[-_]?key|access[-_]?token'
		. '|bearer|auth|signature|assertion|private[-_]?key|x[-_]?api[-_]?token|client[-_]?secret'
		. '|pass[-_]?phrase|certificate|pkcs12|pfx)/i';

	/**
	 * Extra pattern for header names only: a session header under its own name
	 * (X-Session, X-Session-Id) is a credential on the wire. Kept out of
	 * SENSITIVE_NAME_PATTERN because that pattern also walks payload fields,
	 * where a `sessionId` is a correlation id, not a secret.
	 *
	 * @var string
	 */
	private const SENSITIVE_HEADER_PATTERN = '/session/i';

	/**
	 * A value that is only a bare `{{ variable.path }}` template, resolved at call time
	 * and holding no secret itself. A template with a literal or a filter is not one.
	 *
	 * @var string
	 */
	private const PURE_TEMPLATE = '/^\{\{\s*[A-Za-z_][A-Za-z0-9_.]*\s*\}\}$/';

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

		return (preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1);
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

		return (preg_match(self::SENSITIVE_HEADER_PATTERN, $name) === 1);
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
				$redacted[$name] = (new SensitiveValueMasker(registry: $this, parentKey: $name, forExport: false))->mask(value: $value, extraExactNames: null);
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

		$encoded = json_encode($masked, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
		if ($encoded === false) {
			return $body;
		}

		return $encoded;
	}//end redactIssuedTokensInJson()

	/**
	 * Recursively walks an array and replaces the value of any key that
	 * matches {@see isSensitiveName()} with the irreversible placeholder. A
	 * list under such a key has every value masked; a map is walked by its own
	 * keys (see SensitiveValueMasker::mask()).
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
		return $this->redactEntries(data: $data, options: ['extraExactNames' => $extraExactNames, 'forExport' => false]);
	}//end redactArray()

	/**
	 * Redact every entry of a map, at any depth.
	 *
	 * @param array<string|int, mixed>                                     $data    The data to redact.
	 * @param array{extraExactNames: array<int, string>|null, forExport: bool} $options Extra names, and whether an export reads the result.
	 *
	 * @return array<string|int, mixed> The redacted copy.
	 */
	private function redactEntries(array $data, array $options): array {
		$extraExactNames = $options['extraExactNames'];
		foreach ($data as $key => $value) {
			$masker = new SensitiveValueMasker(registry: $this, parentKey: (string)$key, forExport: $options['forExport']);
			if ($masker->isPublicParameter(key: (string)$key, value: $value) === true) {
				continue;
			}

			if ($this->isSensitiveKey(key: (string)$key, extraExactNames: $extraExactNames) === true) {
				$data[$key] = $masker->mask(value: $value, extraExactNames: $extraExactNames);
				continue;
			}

			if (is_array($value) === true) {
				$data[$key] = $this->redactEntries(data: $value, options: $options);
			}
		}

		return $data;
	}//end redactEntries()

	/**
	 * Redacts a stored configuration by name and by structure, for export.
	 *
	 * On top of redactArray(): a session header is masked in a header map, as a
	 * header-shaped key and in a name/value header list, and a URL's password and
	 * secret query values are masked inside any text. Everything else is kept byte
	 * for byte.
	 *
	 * @param array $data The configuration to redact. Not modified; a copy is returned.
	 *
	 * @return array The redacted copy.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function redactConfiguration(array $data): array {
		// The structure is read first, while header pairs still carry their header names.
		return $this->redactEntries(
			data: (new StructuredSecretRedactor(registry: $this))->redact(data: $data),
			options: ['extraExactNames' => null, 'forExport' => true]
		);
	}//end redactConfiguration()

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
	 * Whether a value is only a bare `{{ variable.path }}` template, resolved at call time and holding no secret itself.
	 *
	 * @param string $value The value.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function isPureTemplate(string $value): bool {
		return preg_match(self::PURE_TEMPLATE, trim($value)) === 1;
	}//end isPureTemplate()

	/**
	 * A copy of imported data without the values an export masked.
	 *
	 * A placeholder is a public string, so storing it would turn a secret into a
	 * known value. It is left out instead, as is an array that held nothing but
	 * placeholders; what is left out is kept by OpenRegister when it is
	 * write-only, and has to be entered again otherwise.
	 *
	 * @param array $data The imported data.
	 *
	 * @return array The data without masked values.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function withoutRedactedValues(array $data): array {
		return (new RedactedValueFilter())->filter(data: $data);
	}//end withoutRedactedValues()

	/**
	 * Whether a value is a map under an API-key table key that is not a key reference.
	 *
	 * @param int|string $key   The key the value sits under.
	 * @param mixed      $value The value.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function isCredentialTable(int|string $key, mixed $value): bool {
		if (is_array($value) === false || array_is_list($value) === true || in_array($key, self::CREDENTIAL_TABLE_KEYS, true) === false) {
			return false;
		}

		return array_diff(array_map('strval', array_keys($value)), self::KEY_REFERENCE_KEYS) !== [];
	}//end isCredentialTable()
}//end class
