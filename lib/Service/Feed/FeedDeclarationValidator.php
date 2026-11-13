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
		if (is_array($data) === false || (array_is_list($data) === true && $data !== [])) {
			return ['the file is not a JSON object'];
		}

		$errors = [];
		foreach (array_keys($data) as $key) {
			if (in_array($key, ['$schema', 'app', 'feeds'], true) === false) {
				$errors[] = sprintf('unknown key "%s"', $key);
			}
		}

		if (array_key_exists('app', $data) === true && is_string($data['app']) === false) {
			$errors[] = '"app" is not a string';
		}

		if (is_array(($data['feeds'] ?? null)) === false || array_is_list($data['feeds']) === false) {
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
		if (is_array($feed) === false || (array_is_list($feed) === true && $feed !== [])) {
			return [$where . ' is not an object'];
		}

		$errors = [];
		foreach (self::REQUIRED_FEED_KEYS as $key) {
			if (array_key_exists($key, $feed) === false) {
				$errors[] = sprintf('%s misses "%s"', $where, $key);
			}
		}

		foreach (array_keys($feed) as $key) {
			if (in_array($key, self::FEED_KEYS, true) === false) {
				$errors[] = sprintf('%s has an unknown key "%s"', $where, $key);
			}
		}

		return array_merge($errors, $this->valueErrors(feed: $feed, where: $where));
	}//end feedErrors()

	/**
	 * The problems of a feed's values.
	 *
	 * @param array  $feed  The feed entry.
	 * @param string $where Its position, for the message.
	 *
	 * @return array<int, string> The problems.
	 */
	private function valueErrors(array $feed, string $where): array {
		$errors = [];
		if (array_key_exists('key', $feed) === true && (is_string($feed['key']) === false || preg_match(self::KEY_PATTERN, $feed['key']) !== 1)) {
			$errors[] = $where . '.key must be lower-case words joined by hyphens';
		}

		foreach (['title', 'register', 'schema'] as $key) {
			if (array_key_exists($key, $feed) === true && (is_string($feed[$key]) === false || trim($feed[$key]) === '')) {
				$errors[] = sprintf('%s.%s must be a non-empty string', $where, $key);
			}
		}

		if (array_key_exists('description', $feed) === true && is_string($feed['description']) === false) {
			$errors[] = $where . '.description must be a string';
		}

		if (array_key_exists('fields', $feed) === true) {
			$errors = array_merge($errors, $this->fieldErrors(fields: $feed['fields'], where: $where));
		}

		if (array_key_exists('filter', $feed) === true) {
			$errors = array_merge($errors, $this->filterErrors(filter: $feed['filter'], where: $where));
		}

		if (array_key_exists('scope', $feed) === true) {
			$errors = array_merge($errors, $this->scopeErrors(scope: $feed['scope'], where: $where));
		}

		$pageSize = ($feed['pageSize'] ?? 1);
		if (is_int($pageSize) === false || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
			$errors[] = sprintf('%s.pageSize must be a whole number from 1 to %d', $where, self::MAX_PAGE_SIZE);
		}

		return $errors;
	}//end valueErrors()

	/**
	 * The problems of a feed's field list.
	 *
	 * @param mixed  $fields The list.
	 * @param string $where  The feed's position.
	 *
	 * @return array<int, string> The problems.
	 */
	private function fieldErrors(mixed $fields, string $where): array {
		if (is_array($fields) === false || array_is_list($fields) === false || $fields === []) {
			return [$where . '.fields must be a non-empty list'];
		}

		$errors = [];
		foreach ($fields as $field) {
			if (is_string($field) === false || preg_match(self::NAME_PATTERN, $field) !== 1) {
				$shown = gettype($field);
				if (is_scalar($field) === true) {
					$shown = (string)$field;
				}

				$errors[] = sprintf('%s.fields holds "%s", which is not a field name', $where, $shown);
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
			if (is_string($value) === false && is_int($value) === false && is_float($value) === false && is_bool($value) === false) {
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
			if (is_string(($scope[$key] ?? null)) === false || preg_match(self::NAME_PATTERN, $scope[$key]) !== 1) {
				$errors[] = sprintf('%s.scope.%s must be a field name', $where, $key);
			}
		}

		foreach (array_keys($scope) as $key) {
			if (in_array($key, ['parameter', 'field'], true) === false) {
				$errors[] = sprintf('%s.scope has an unknown key "%s"', $where, (string)$key);
			}
		}

		return $errors;
	}//end scopeErrors()
}//end class
