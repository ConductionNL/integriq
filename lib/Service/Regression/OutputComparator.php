<?php

/**
 * Integriq OutputComparator.
 *
 * Compares an expected output with an actual one, path by path, and names
 * every path whose value differs. A path in the ignored list, or below one,
 * is left out of the comparison, so a generated timestamp does not fail a
 * case.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Regression
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
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Regression;

/**
 * Path-by-path difference of two outputs.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */
final class OutputComparator {

	/**
	 * Marker for a path one side does not have.
	 *
	 * @var string
	 */
	public const ABSENT = '(absent)';

	/**
	 * Every path whose value differs, in sorted path order.
	 *
	 * @param array<mixed>      $expected The expected output.
	 * @param array<mixed>      $actual   The actual output.
	 * @param array<int,string> $ignored  Dot paths left out, with everything below them.
	 *
	 * @return array<int,array{path:string,expected:mixed,actual:mixed}> The differences; empty when the outputs match.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
	 */
	public static function differences(array $expected, array $actual, array $ignored = []): array {
		$want = self::flatten(value: $expected, prefix: '');
		$have = self::flatten(value: $actual, prefix: '');
		$paths = array_unique(array_merge(array_keys($want), array_keys($have)));
		sort($paths, SORT_STRING);

		$differences = [];
		foreach ($paths as $path) {
			$path = (string)$path;
			if (self::isIgnored(path: $path, ignored: $ignored) === true) {
				continue;
			}

			$wanted = self::ABSENT;
			if (array_key_exists($path, $want) === true) {
				$wanted = $want[$path];
			}

			$got = self::ABSENT;
			if (array_key_exists($path, $have) === true) {
				$got = $have[$path];
			}

			if ($wanted !== $got) {
				$differences[] = ['path' => $path, 'expected' => $wanted, 'actual' => $got];
			}
		}

		return $differences;
	}//end differences()

	/**
	 * The leaf values of a value by dot path; an empty array is a leaf.
	 *
	 * @param mixed  $value  The value.
	 * @param string $prefix The path so far.
	 *
	 * @return array<string,mixed>
	 */
	private static function flatten(mixed $value, string $prefix): array {
		if (is_array($value) === false || ($value === [] && $prefix !== '')) {
			return [$prefix => $value];
		}

		$leaves = [];
		foreach ($value as $key => $item) {
			$path = (string)$key;
			if ($prefix !== '') {
				$path = $prefix . '.' . $key;
			}

			$leaves = array_merge($leaves, self::flatten(value: $item, prefix: $path));
		}

		return $leaves;
	}//end flatten()

	/**
	 * Whether a path is an ignored path or lies below one.
	 *
	 * @param string            $path    The path.
	 * @param array<int,string> $ignored The ignored paths.
	 *
	 * @return bool
	 */
	private static function isIgnored(string $path, array $ignored): bool {
		foreach ($ignored as $prefix) {
			$prefix = trim((string)$prefix);
			if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '.') === true)) {
				return true;
			}
		}

		return false;
	}//end isIgnored()
}//end class
