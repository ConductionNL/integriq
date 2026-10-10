<?php

/**
 * Hands out a fresh file-server connection per operation.
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
 * Each adapter call opens and closes its own connection (design D3).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class RemoteFileClientFactory {

	/**
	 * A new, unopened client for a protocol.
	 *
	 * @param string $protocol `sftp` or `ftps`.
	 *
	 * @return RemoteFileClient The client.
	 *
	 * @throws InvalidArgumentException For any other protocol, plain FTP included.
	 */
	public function create(string $protocol): RemoteFileClient {
		return match ($protocol) {
			'sftp' => new SftpClient(),
			'ftps' => new FtpsClient(),
			default => throw new InvalidArgumentException(message: 'Unsupported file transfer protocol "' . $protocol . '".'),
		};

	}//end create()
}//end class
