<?php

/**
 * Confines a remote path to a source's root path.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\FileTransfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\FileTransfer;

use InvalidArgumentException;

/**
 * Paths stay inside the source's root path; `..` is refused (REQ-SFTP-001).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class RemotePath {

	/**
	 * Resolve a path under the root path.
	 *
	 * A relative path is taken from the root; an absolute one must already
	 * lie under it. Any `..` segment, a NUL byte or a backslash is refused
	 * outright rather than normalised, so no spelling escapes the root.
	 *
	 * @param string $rootPath The source's root path, `/` when unset.
	 * @param string $path     The path asked for.
	 *
	 * @return string The absolute path, without a trailing slash (except `/`).
	 *
	 * @throws InvalidArgumentException When the path leaves the root or is malformed.
	 */
	public static function resolve(string $rootPath, string $path): string {
		$root = self::normalise(path: ($rootPath === '' ? '/' : $rootPath));

		foreach ([$rootPath, $path] as $candidate) {
			if (str_contains($candidate, "\0") === true || str_contains($candidate, '\\') === true) {
				throw new InvalidArgumentException(message: 'The path contains a character that is not allowed.');
			}

			if (in_array('..', explode('/', $candidate), true) === true) {
				throw new InvalidArgumentException(message: 'The path may not contain "..".');
			}
		}

		if (str_starts_with($path, '/') === false) {
			$path = rtrim($root, '/') . '/' . $path;
		}

		$resolved = self::normalise(path: $path);
		if ($root !== '/' && $resolved !== $root && str_starts_with($resolved, $root . '/') === false) {
			throw new InvalidArgumentException(message: 'The path lies outside the source\'s root path.');
		}

		return $resolved;
	}//end resolve()

	/**
	 * Collapse duplicate slashes and `.` segments.
	 *
	 * @param string $path An absolute or relative path without `..`.
	 *
	 * @return string The absolute normalised path.
	 */
	private static function normalise(string $path): string {
		$segments = array_filter(
			explode('/', $path),
			static fn (string $segment): bool => $segment !== '' && $segment !== '.'
		);

		return '/' . implode('/', $segments);
	}//end normalise()
}//end class
