<?php

/**
 * Masks a value held under a sensitive key.
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
 * The masking half of SensitiveFieldRegistry: what a value under a sensitive
 * key becomes, scalar, list or map.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
final class SensitiveValueMasker {
	/**
	 * The key under a sensitive map whose content is a credential pointer, not a secret (REQ-010).
	 *
	 * @var string
	 */
	private const CREDENTIAL_POINTER_KEY = 'credentialRef';

	/**
	 * Keys under a sensitive map that name how a credential is used, not the
	 * credential: the auth or signature type and scheme, the header it travels
	 * in, who may use it, its tolerance and its public client id, and the
	 * non-secret call parameters the source schema documents next to its
	 * write-only paths (99-source-nested-auth-writeonly.json: grant_type,
	 * scope, algorithm, payload, x5t, tokenUrl, tokenLocation, passwordType,
	 * scheme, mode, username, client_id) plus the token and signature
	 * parameters that sit beside them. They stay readable, so an exported rule
	 * or source still works after re-import. No write-only leaf of that schema
	 * may be listed here (SensitiveValueMaskerFollowUpTest checks it).
	 *
	 * @var array<int, string>
	 */
	private const NON_SECRET_SETTING_KEYS = [
		'type',
		'scheme',
		'mode',
		'header',
		'users',
		'groups',
		'grant_type',
		'toleranceSeconds',
		'client_id',
		'id',
		'uuid',
		'username',
		'scope',
		'audience',
		'algorithm',
		'payload',
		'x5t',
		'tokenUrl',
		'tokenLocation',
		'passwordType',
		'publicKey',
		'keyId',
		'signatureAlgorithm',
		'bodyKey',
	];

	/**
	 * The call parameters among NON_SECRET_SETTING_KEYS whose names look
	 * sensitive (`tokenUrl`, `keyId`): a scalar under one of them stays
	 * readable wherever it sits, also at the top of a configuration.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_PARAMETER_KEYS = [
		'username',
		'tokenUrl',
		'tokenLocation',
		'passwordType',
		'publicKey',
		'keyId',
		'signatureAlgorithm',
		'bodyKey',
	];

	/**
	 * The source schema fragment whose write-only paths name the secret auth leaves.
	 *
	 * @var string
	 */
	private const WRITE_ONLY_FRAGMENT = __DIR__ . '/../../Settings/register.d/99-source-nested-auth-writeonly.json';

	/**
	 * A key that names a setting: letters and underscores, no digits or
	 * punctuation, so a generated credential used as a map key never passes.
	 *
	 * @var string
	 */
	private const SETTING_NAME = '/^[A-Za-z][A-Za-z_]{0,39}$/';

	/**
	 * The write-only leaves, read once from WRITE_ONLY_FRAGMENT.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $writeOnlyLeaves = null;

	/**
	 * Constructor.
	 *
	 * @param SensitiveFieldRegistry $registry The registry that redacts by name.
	 */
	public function __construct(
		private readonly SensitiveFieldRegistry $registry,
	) {
	}//end __construct()

	/**
	 * Masks a value held under a sensitive key.
	 *
	 * A scalar becomes the placeholder. A list (a PSR-7 header's values, a
	 * basic-auth pair) keeps its length and has every value masked at any
	 * depth: its integer keys say nothing, so it inherits its key's
	 * sensitivity. A map has every value masked at any depth too, whatever its
	 * own key is called (`auth.pass`, `auth.value`), except a credentialRef
	 * (a pointer export must keep, REQ-010) and the NON_SECRET_SETTING_KEYS:
	 * those are only redacted by name.
	 *
	 * @param mixed                   $value           The value under a sensitive key.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return mixed The masked value.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function mask(mixed $value, ?array $extraExactNames): mixed {
		if (is_array($value) === false) {
			return $this->maskScalar(value: $value);
		}

		if (array_is_list($value) === false) {
			return $this->maskSettingsMap(map: $value, extraExactNames: $extraExactNames);
		}

		return array_map(fn (mixed $item): mixed => $this->maskListItem(item: $item), $value);
	}//end mask()

	/**
	 * The leaf names of the source schema's write-only auth paths
	 * (`configuration.authentication.client_secret` gives `client_secret`).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public static function writeOnlyLeaves(): array {
		if (self::$writeOnlyLeaves === null) {
			$fragment = json_decode((string)file_get_contents(self::WRITE_ONLY_FRAGMENT), true);
			$paths    = ($fragment['components']['schemas']['source']['x-openregister-writeonly-paths'] ?? []);
			$leaves   = array_map(static fn (string $path): string => substr(strrchr('.' . $path, '.'), 1), $paths);
			self::$writeOnlyLeaves = array_values(array_unique($leaves));
		}

		return self::$writeOnlyLeaves;
	}//end writeOnlyLeaves()

	/**
	 * Whether a scalar under this key is a public call parameter, not a secret.
	 *
	 * @param string $key The key.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function namesAPublicParameter(string $key): bool {
		return in_array($key, self::PUBLIC_PARAMETER_KEYS, true);
	}//end namesAPublicParameter()

	/**
	 * Mask a map: per key when every key names a setting, whole otherwise.
	 *
	 * A map whose keys are not setting names is a credential table
	 * (`keys: {"<api key>": "<user>"}`): its keys are the secret, so it becomes the placeholder.
	 *
	 * @param array<string, mixed>    $map             The map under a sensitive key.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return mixed The masked map, or the placeholder.
	 */
	private function maskSettingsMap(array $map, ?array $extraExactNames): mixed {
		foreach (array_keys($map) as $key) {
			if ($this->namesASetting(key: $key) === false) {
				return SensitiveFieldRegistry::PLACEHOLDER;
			}
		}

		return $this->maskMap(map: $map, extraExactNames: $extraExactNames);
	}//end maskSettingsMap()

	/**
	 * Whether a map key names a setting rather than being a credential itself.
	 *
	 * @param int|string $key The map key.
	 *
	 * @return boolean
	 */
	private function namesASetting(int|string $key): bool {
		if (is_string($key) === false) {
			return false;
		}

		if ($key === self::CREDENTIAL_POINTER_KEY || in_array($key, self::NON_SECRET_SETTING_KEYS, true) === true) {
			return true;
		}

		return preg_match(self::SETTING_NAME, $key) === 1;
	}//end namesASetting()

	/**
	 * Masks every value of a map held under a sensitive key, keeping a credentialRef pointer.
	 *
	 * @param array                   $map             The map under a sensitive key.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return array The masked map, keys and order unchanged.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	private function maskMap(array $map, ?array $extraExactNames): array {
		foreach ($map as $key => $value) {
			if ($this->keepsReadable(key: $key, value: $value) === true) {
				$map[$key] = $this->redactReadable(value: $value, extraExactNames: $extraExactNames);
				continue;
			}

			$map[$key] = $this->mask(value: $value, extraExactNames: $extraExactNames);
		}

		return $map;
	}//end maskMap()

	/**
	 * Whether an entry of a sensitive map stays readable: a credentialRef
	 * pointer, or a non-secret setting.
	 *
	 * @param int|string $key   The entry key.
	 * @param mixed      $value The entry value.
	 *
	 * @return boolean
	 */
	private function keepsReadable(int|string $key, mixed $value): bool {
		if ($key === self::CREDENTIAL_POINTER_KEY) {
			return is_array($value);
		}

		return in_array($key, self::NON_SECRET_SETTING_KEYS, true);
	}//end keepsReadable()

	/**
	 * A readable entry of a sensitive map, still redacted by name when it is an array.
	 *
	 * @param mixed                   $value           The entry value.
	 * @param array<int, string>|null $extraExactNames Additional exact-match names for this call.
	 *
	 * @return mixed The value.
	 */
	private function redactReadable(mixed $value, ?array $extraExactNames): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		return $this->registry->redactArray(data: $value, extraExactNames: $extraExactNames);
	}//end redactReadable()

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
			return $this->maskScalar(value: $item);
		}

		// A map in a list under a sensitive key is a credential entry whose own
		// keys can be the secret (`keys: [{"<api key>": "<user>"}]`), so it is masked whole.
		if (array_is_list($item) === false) {
			return SensitiveFieldRegistry::PLACEHOLDER;
		}

		return array_map(fn (mixed $inner): mixed => $this->maskListItem(item: $inner), $item);
	}//end maskListItem()

	/**
	 * The placeholder for a scalar under a sensitive key, unless the scalar is a pure template.
	 *
	 * @param mixed $value The scalar.
	 *
	 * @return mixed The placeholder, or the template.
	 */
	private function maskScalar(mixed $value): mixed {
		if (is_string($value) === true && $this->registry->isPureTemplate(value: $value) === true) {
			return $value;
		}

		return SensitiveFieldRegistry::PLACEHOLDER;
	}//end maskScalar()
}//end class
