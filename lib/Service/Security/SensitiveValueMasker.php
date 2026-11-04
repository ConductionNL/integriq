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
	 * in, who may use it, its tolerance and its public client id. They stay
	 * readable, so an exported rule or source still works after re-import.
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
	];

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
			return $this->maskMap(map: $value, extraExactNames: $extraExactNames);
		}

		return array_map(fn (mixed $item): mixed => $this->maskListItem(item: $item), $value);
	}//end mask()

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
