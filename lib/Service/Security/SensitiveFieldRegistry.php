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
	 * Recursively walks an array and replaces the value of any key that
	 * matches {@see isSensitiveName()} with the irreversible placeholder.
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
			if (is_array($value) === true) {
				$data[$key] = $this->redactArray(data: $value, extraExactNames: $extraExactNames);
				continue;
			}

			$segments = explode('.', (string)$key);
			$leafName = (string)end($segments);

			$isSensitive = $this->isSensitiveName(name: $leafName);
			if ($isSensitive === false && $extraExactNames !== null) {
				foreach ($extraExactNames as $extraExactName) {
					if (strtolower($leafName) === strtolower($extraExactName)) {
						$isSensitive = true;
						break;
					}
				}
			}

			if ($isSensitive === true) {
				$data[$key] = self::PLACEHOLDER;
			}
		}//end foreach

		return $data;
	}//end redactArray()
}//end class
