<?php

/**
 * Integriq Nested Value Path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Reads and writes a value at a key path inside nested object data.
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 */
final class NestedValuePath {

	/**
	 * The value at a path, or null.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param list<string>         $path The keys.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function get(array $data, array $path): mixed {
		$node = $data;
		foreach ($path as $key) {
			if (is_array($node) === false || array_key_exists($key, $node) === false) {
				return null;
			}

			$node = $node[$key];
		}

		return $node;

	}//end get()

	/**
	 * The data with a value set at a path, creating missing levels.
	 *
	 * @param array<string, mixed> $data  The data.
	 * @param list<string>         $path  The keys.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function set(array $data, array $path, mixed $value): array {
		$key = array_shift($path);
		if ($path === []) {
			$data[$key] = $value;
			return $data;
		}

		$child = ($data[$key] ?? []);
		if (is_array($child) === false) {
			$child = [];
		}

		$data[$key] = $this->set(data: $child, path: $path, value: $value);

		return $data;

	}//end set()

	/**
	 * Whether a stored value counts as not set.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function isEmpty(mixed $value): bool {
		return $value === null || $value === [] || (is_string($value) === true && trim($value) === '');

	}//end isEmpty()
}//end class
