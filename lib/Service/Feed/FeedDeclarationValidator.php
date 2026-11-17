<?php

/**
 * Integriq FeedDeclarationValidator.
 *
 * Checks a decoded `feeds.json` against the rules of
 * `lib/Settings/feeds.schema.json`. The JSON Schema library is only a dev
 * dependency of this app, so the runtime cannot lean on it; this class
 * mirrors the schema, and FeedDeclarationValidatorTest runs both over the
 * same fixtures so they cannot drift apart unnoticed.
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
 * Validates a feed declaration; one message per problem, naming where it is.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class FeedDeclarationValidator {

	/**
	 * A feed key.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * A field or parameter name.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * The keys a feed may carry.
	 *
	 * @var array<int, string>
	 */
	private const FEED_KEYS = ['key', 'title', 'description', 'register', 'schema', 'fields', 'filter', 'scope', 'pageSize'];

	/**
	 * The keys a feed must carry.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FEED_KEYS = ['key', 'title', 'register', 'schema', 'fields', 'scope', 'pageSize'];

	/**
	 * The largest page a feed may declare.
	 *
	 * @var integer
	 */
	public const MAX_PAGE_SIZE = 5000;

	/**
	 * The problems of a whole declaration file: the top level only.
	 *
	 * A problem inside one feed is reported by {@see feedErrors()}, so one
	 * bad dataset does not cost the app its other feeds.
	 *
	 * @param mixed $data The decoded file.
	 *
	 * @return array<int, string> The problems; empty when the file can be read.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function fileErrors(mixed $data): array {
		if ($this->isObject(value: $data) === false) {
			return ['the file is not a JSON object'];
		}

		$errors = $this->unknownKeyErrors(data: $data, known: ['$schema', 'app', 'feeds'], prefix: '');
		if (array_key_exists('app', $data) === true && is_string($data['app']) === false) {
			$errors[] = '"app" is not a string';
		}

		if ($this->isList(value: ($data['feeds'] ?? null)) === false) {
			$errors[] = '"feeds" is not a list';
		}

		return $errors;
	}//end fileErrors()

	/**
	 * The problems of one feed.
	 *
	 * @param mixed   $feed  The feed entry.
	 * @param integer $index Its position, for the message.
	 *
	 * @return array<int, string> The problems; empty when the feed is well formed.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function feedErrors(mixed $feed, int $index): array {
		$where = sprintf('feeds[%d]', $index);
		if ($this->isObject(value: $feed) === false) {
			return [$where . ' is not an object'];
		}

		$errors = [];
		foreach (self::REQUIRED_FEED_KEYS as $key) {
			if (array_key_exists($key, $feed) === false) {
				$errors[] = sprintf('%s misses "%s"', $where, $key);
			}
		}

		return array_merge(
			$errors,
			$this->unknownKeyErrors(data: $feed, known: self::FEED_KEYS, prefix: $where . ' has an '),
			$this->valueErrors(feed: $feed, where: $where)
		);
	}//end feedErrors()

	/**
	 * The problems of a feed's values, in the order of its keys' meaning:
	 * the key, the texts, the field list, the filter, the scope, the page size.
	 *
	 * @param array  $feed  The feed entry.
	 * @param string $where Its position, for the message.
	 *
	 * @return array<int, string> The problems.
	 */
	private function valueErrors(array $feed, string $where): array {
		$errors = array_merge($this->keyErrors(feed: $feed, where: $where), $this->textErrors(feed: $feed, where: $where));
		foreach (['fields' => 'fieldErrors', 'filter' => 'filterErrors', 'scope' => 'scopeErrors'] as $key => $check) {
			if (array_key_exists($key, $feed) === true) {
				$errors = array_merge($errors, $this->$check($feed[$key], $where));
			}
		}

		return array_merge($errors, $this->pageSizeErrors(pageSize: ($feed['pageSize'] ?? 1), where: $where));
	}//end valueErrors()

	/**
	 * The problem of a feed's key, when it has one that is not lower-case words joined by hyphens.
	 *
	 * @param array  $feed  The feed entry.
	 * @param string $where Its position, for the message.
	 *
	 * @return array<int, string> The problem, or none.
	 */
	private function keyErrors(array $feed, string $where): array {
		if (array_key_exists('key', $feed) === true && $this->matches(value: $feed['key'], pattern: self::KEY_PATTERN) === false) {
			return [$where . '.key must be lower-case words joined by hyphens'];
		}

		return [];
	}//end keyErrors()

	/**
	 * The problems of a feed's title, register, schema and description.
	 *
	 * @param array  $feed  The feed entry.
	 * @param string $where Its position, for the message.
	 *
	 * @return array<int, string> The problems.
	 */
	private function textErrors(array $feed, string $where): array {
		$errors = [];
		foreach (['title', 'register', 'schema'] as $key) {
			if (array_key_exists($key, $feed) === true && $this->isFilledString(value: $feed[$key]) === false) {
				$errors[] = sprintf('%s.%s must be a non-empty string', $where, $key);
			}
		}

		if (array_key_exists('description', $feed) === true && is_string($feed['description']) === false) {
			$errors[] = $where . '.description must be a string';
		}

		return $errors;
	}//end textErrors()

	/**
	 * The problem of a feed's page size, when it is not a whole number from 1 to MAX_PAGE_SIZE.
	 *
	 * @param mixed  $pageSize The page size.
	 * @param string $where    The feed's position.
	 *
	 * @return array<int, string> The problem, or none.
	 */
	private function pageSizeErrors(mixed $pageSize, string $where): array {
		if (is_int($pageSize) === false || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
			return [sprintf('%s.pageSize must be a whole number from 1 to %d', $where, self::MAX_PAGE_SIZE)];
		}

		return [];
	}//end pageSizeErrors()

	/**
	 * The problems of a feed's field list.
	 *
	 * @param mixed  $fields The list.
	 * @param string $where  The feed's position.
	 *
	 * @return array<int, string> The problems.
	 */
	private function fieldErrors(mixed $fields, string $where): array {
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
	 */
	private function filterErrors(mixed $filter, string $where): array {
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
	 */
	private function scopeErrors(mixed $scope, string $where): array {
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
	 */
	private function unknownKeyErrors(array $data, array $known, string $prefix): array {
		$errors = [];
		foreach (array_keys($data) as $key) {
			if (in_array($key, $known, true) === false) {
				$errors[] = sprintf('%sunknown key "%s"', $prefix, (string)$key);
			}
		}

		return $errors;
	}//end unknownKeyErrors()

	/**
	 * Whether a value is a JSON object: an array that is not a non-empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isObject(mixed $value): bool {
		return is_array($value) === true && (array_is_list($value) === false || $value === []);
	}//end isObject()

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
	 * Whether a value is a string with more than white space.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isFilledString(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end isFilledString()

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
