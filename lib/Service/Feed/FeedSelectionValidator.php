<?php

/**
 * Integriq FeedSelectionValidator.
 *
 * Checks what a declared feed selects: its fields, its fixed filter and its
 * scope binding, plus the unknown keys of any object in a declaration. Part of
 * the mirror of `lib/Settings/feeds.schema.json` FeedDeclarationValidator runs.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Feed;

/**
 * Validates the fields, filter and scope of a feed; one message per problem.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class FeedSelectionValidator {

	/**
	 * A field or parameter name.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * The problems of a feed's field list.
	 *
	 * @param mixed  $fields The list.
	 * @param string $where  The feed's position.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function fieldErrors(mixed $fields, string $where): array {
		if ($this->isList(value: $fields) === false || $fields === []) {
			return [$where . '.fields must be a non-empty list'];
		}

		$errors = [];
		foreach ($fields as $field) {
			if ($this->matches(value: $field, pattern: self::NAME_PATTERN) === false) {
				$errors[] = sprintf('%s.fields holds "%s", which is not a field name', $where, $this->shown(value: $field));
			}
		}

		if (count($fields) !== count(array_unique(array_map('strval', array_filter($fields, 'is_scalar'))))) {
			$errors[] = $where . '.fields lists a field twice';
		}

		return $errors;
	}//end fieldErrors()

	/**
	 * The problems of a feed's fixed filter.
	 *
	 * @param mixed  $filter The filter.
	 * @param string $where  The feed's position.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function filterErrors(mixed $filter, string $where): array {
		if (is_array($filter) === false || ($filter !== [] && array_is_list($filter) === true)) {
			return [$where . '.filter must be an object'];
		}

		$errors = [];
		foreach ($filter as $field => $value) {
			// A string, a number or a boolean: exactly the scalars.
			if (is_scalar($value) === false) {
				$errors[] = sprintf('%s.filter.%s must be a string, number or boolean', $where, (string)$field);
			}
		}

		return $errors;
	}//end filterErrors()

	/**
	 * The problems of a feed's scope.
	 *
	 * @param mixed  $scope The scope.
	 * @param string $where The feed's position.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function scopeErrors(mixed $scope, string $where): array {
		if (is_array($scope) === false) {
			return [$where . '.scope must be an object'];
		}

		$errors = [];
		foreach (['parameter', 'field'] as $key) {
			if ($this->matches(value: ($scope[$key] ?? null), pattern: self::NAME_PATTERN) === false) {
				$errors[] = sprintf('%s.scope.%s must be a field name', $where, $key);
			}
		}

		return array_merge($errors, $this->unknownKeyErrors(data: $scope, known: ['parameter', 'field'], prefix: $where . '.scope has an '));
	}//end scopeErrors()

	/**
	 * One problem per key that is not one of the known ones.
	 *
	 * @param array              $data   The object.
	 * @param array<int, string> $known  The keys it may carry.
	 * @param string             $prefix The start of the message, before `unknown key`.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function unknownKeyErrors(array $data, array $known, string $prefix): array {
		$errors = [];
		foreach (array_keys($data) as $key) {
			if (in_array($key, $known, true) === false) {
				$errors[] = sprintf('%sunknown key "%s"', $prefix, (string)$key);
			}
		}

		return $errors;
	}//end unknownKeyErrors()

	/**
	 * Whether a value is a string matching a pattern.
	 *
	 * @param mixed  $value   The value.
	 * @param string $pattern The pattern.
	 *
	 * @return bool
	 */
	private function matches(mixed $value, string $pattern): bool {
		return is_string($value) === true && preg_match($pattern, $value) === 1;
	}//end matches()

	/**
	 * Whether a value is a JSON list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isList(mixed $value): bool {
		return is_array($value) === true && array_is_list($value) === true;
	}//end isList()

	/**
	 * A value as a message shows it: a scalar as itself, anything else by its type.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function shown(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end shown()
}//end class
