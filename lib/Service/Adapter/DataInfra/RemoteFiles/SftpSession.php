<?php

/**
 * An SFTP connection through phpseclib 3.
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
 * Wraps a connected and logged-in `phpseclib3\Net\SFTP`. The object is taken
 * as given rather than typed, because phpseclib is not a dependency of every
 * install: {@see RemoteFileSessionFactory} only builds one when the class exists.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class SftpSession implements RemoteFileSession {

	/**
	 * The phpseclib file type of a directory (NET_SFTP_TYPE_DIRECTORY).
	 */
	private const TYPE_DIRECTORY = 2;

	/**
	 * Constructor.
	 *
	 * @param object $sftp A logged-in phpseclib3\Net\SFTP.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly object $sftp,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $directory The server path of the folder.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function listDirectory(string $directory): array {
		$raw = $this->sftp->rawlist($directory);
		if (is_array($raw) === false) {
			throw new RemoteFileException('The folder "' . $directory . '" cannot be read on the server.');
		}

		$entries = [];
		foreach ($raw as $name => $attributes) {
			$name = (string)$name;
			if ($name === '.' || $name === '..') {
				continue;
			}

			$attributes = (array)$attributes;
			$size       = null;
			if (isset($attributes['size']) === true) {
				$size = (int)$attributes['size'];
			}

			$modified = null;
			if (isset($attributes['mtime']) === true) {
				$modified = (int)$attributes['mtime'];
			}

			$entries[] = [
				'name'        => $name,
				'size'        => $size,
				'modifiedAt'  => $modified,
				'isDirectory' => (int)($attributes['type'] ?? 0) === self::TYPE_DIRECTORY,
			];
		}

		return $entries;
	}//end listDirectory()

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $target The stream to write to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function fetch(string $path, $target): void {
		if ($this->sftp->get($path, $target) === false) {
			throw new RemoteFileException('The file "' . $path . '" cannot be read on the server.');
		}
	}//end fetch()

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $source The stream to read from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function deliver(string $path, $source): void {
		if ($this->sftp->put($path, $source) !== true) {
			throw new RemoteFileException('The file "' . $path . '" cannot be written on the server.');
		}
	}//end deliver()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $from The current server path.
	 * @param string $to   The new server path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function move(string $from, string $to): void {
		if ($this->sftp->rename($from, $to) !== true) {
			throw new RemoteFileException('The file "' . $from . '" cannot be moved to "' . $to . '" on the server.');
		}
	}//end move()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path The server path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function delete(string $path): void {
		if ($this->sftp->delete($path, false) !== true) {
			throw new RemoteFileException('The file "' . $path . '" cannot be deleted on the server.');
		}
	}//end delete()

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function close(): void {
		$this->sftp->disconnect();
	}//end close()
}//end class
