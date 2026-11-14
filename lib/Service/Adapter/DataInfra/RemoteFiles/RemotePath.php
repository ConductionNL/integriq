<?php

/**
 * Keeps every remote path inside the source's root path.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
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
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

/**
 * Joins a caller's path onto the source's root path and refuses anything that
 * could leave it: a `..` segment, a NUL byte or a backslash. A leading slash on
 * the caller's path is read as relative to the root, never as the server's root.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class RemotePath {

	/**
	 * The absolute server path for a path relative to the root.
	 *
	 * @param string $rootPath The source's root path on the server.
	 * @param string $path     The path the caller asked for, relative to the root.
	 *
	 * @return string The server path, always starting with the root path.
	 *
	 * @throws RemoteFileException When the path could leave the root.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function resolve(string $rootPath, string $path): string {
		$root = self::normaliseRoot(rootPath: $rootPath);
		if (str_contains($path, "\0") === true || str_contains($path, '\\') === true) {
			throw new RemoteFileException('The path "' . self::printable(path: $path) . '" contains a character that is not allowed.');
		}

		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				throw new RemoteFileException('The path "' . self::printable(path: $path) . '" leaves the root path; ".." is not allowed.');
			}

			$segments[] = $segment;
		}

		if ($segments === []) {
			return $root;
		}

		return rtrim($root, '/') . '/' . implode('/', $segments);
	}//end resolve()

	/**
	 * The root path, absolute and without a trailing slash (except the server root itself).
	 *
	 * @param string $rootPath The configured root path.
	 *
	 * @return string
	 *
	 * @throws RemoteFileException When the root path itself contains `..`.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function normaliseRoot(string $rootPath): string {
		$segments = array_values(array_filter(explode('/', trim($rootPath)), fn (string $s): bool => $s !== '' && $s !== '.'));
		if (in_array('..', $segments, true) === true) {
			throw new RemoteFileException('The root path of the source may not contain "..".');
		}

		return '/' . implode('/', $segments);
	}//end normaliseRoot()

	/**
	 * A path safe to show in a message.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	private static function printable(string $path): string {
		return (string)preg_replace('/[^\x20-\x7E]/', '?', $path);
	}//end printable()
}//end class
