<?php

/**
 * One entry of a remote folder listing.
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
 * A file or folder on the partner's server, as a listing reports it.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class RemoteFileEntry {

	/**
	 * Constructor.
	 *
	 * @param string   $name        The entry's name, without its folder.
	 * @param string   $path        The entry's path relative to the source's root path.
	 * @param int|null $size        The size in bytes, when the server reports it.
	 * @param int|null $modifiedAt  The modification time as a Unix timestamp, when the server reports it.
	 * @param bool     $isDirectory Whether the entry is a folder.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $path,
		public readonly ?int $size=null,
		public readonly ?int $modifiedAt=null,
		public readonly bool $isDirectory=false,
	) {

	}//end __construct()

	/**
	 * The entry as a plain array, for a listing response.
	 *
	 * @return array{name: string, path: string, size: int|null, modifiedAt: int|null, isDirectory: bool}
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function toArray(): array {
		return [
			'name'        => $this->name,
			'path'        => $this->path,
			'size'        => $this->size,
			'modifiedAt'  => $this->modifiedAt,
			'isDirectory' => $this->isDirectory,
		];
	}//end toArray()
}//end class
