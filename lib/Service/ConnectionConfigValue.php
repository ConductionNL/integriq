<?php

/**
 * Integriq ConnectionConfigValue.
 *
 * What a value read from an app's config means for the connection status
 * resolver (hydra umbrella design D2 and D4): whether it counts as filled,
 * whether it equals one of a declared list of values, and how a scalar reads
 * as text. Reading the value lives in {@see ConnectionConfigReader}; this class
 * only judges it, so neither class grows past the complexity limit.
 *
 * A value is empty when it reads as `""`, `false` or `0` after trimming,
 * case-insensitively. A JSON `false`, `0` or `null`, an empty JSON array or
 * object, and a missing path read that way too. Everything else is filled.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Judges a config value for the D4 rules.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */
class ConnectionConfigValue {

	/**
	 * The values, lowercased and trimmed, that leave a required setting empty.
	 *
	 * @var string[]
	 */
	public const EMPTY_VALUES = ['', 'false', '0'];

	/**
	 * Whether a value counts as filled.
	 *
	 * A list or an object holds a value unless it is empty. Text that decodes
	 * as an empty JSON array or object, such as `[]`, `{}` or `[ ]`, is empty
	 * too, so a list setting stored as a string reads the same as one stored
	 * under the array type. Every other value is compared as text against
	 * EMPTY_VALUES, so `false`, `0` and `null` read as empty in any form they
	 * are stored in.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-empty-json-list-is-not-filled
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-switch-stored-as-false-is-not-filled
	 */
	public function isFilled(mixed $value): bool {
		if (is_array($value) === true) {
			return $value !== [];
		}

		$text = $this->text(value: $value);
		if ($this->isEmptyJsonText(text: $text) === true) {
			return false;
		}

		return in_array(mb_strtolower($text), self::EMPTY_VALUES, true) === false;
	}//end isFilled()

	/**
	 * Whether a value equals one of the candidates, as trimmed text and case-insensitively.
	 *
	 * A non-empty list or object never equals a candidate. An empty one, like
	 * null and a missing path, reads as the empty string.
	 *
	 * @param mixed $value The value.
	 * @param array<int|string,mixed> $candidates The declared values; non-strings never match.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-off-value-switches-the-connection-off
	 */
	public function isOneOf(mixed $value, array $candidates): bool {
		if (is_array($value) === true && $value !== []) {
			return false;
		}

		$needle = mb_strtolower($this->text(value: $value));
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && mb_strtolower(trim($candidate)) === $needle) {
				return true;
			}
		}

		return false;
	}//end isOneOf()

	/**
	 * A scalar as trimmed text.
	 *
	 * Booleans read as `true` or `false`, so a declaration can list them.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text, or '' when the value is null, an object or a list.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-json-path-reads-inside-a-settings-blob
	 */
	public function text(mixed $value): string {
		return match (true) {
			$value === true => 'true',
			$value === false => 'false',
			is_string($value), is_int($value), is_float($value) => trim((string)$value),
			default => '',
		};
	}//end text()

	/**
	 * Whether trimmed text is an empty JSON array or object.
	 *
	 * Only text that starts with `[` or `{` is decoded, so `null`, `0` and
	 * `false` keep the meaning EMPTY_VALUES gives them.
	 *
	 * @param string $text The trimmed text.
	 *
	 * @return bool
	 */
	private function isEmptyJsonText(string $text): bool {
		if (str_starts_with($text, '[') === false && str_starts_with($text, '{') === false) {
			return false;
		}

		return json_decode($text, true) === [];
	}//end isEmptyJsonText()
}//end class
