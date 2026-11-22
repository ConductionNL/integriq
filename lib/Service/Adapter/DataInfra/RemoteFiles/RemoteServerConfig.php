<?php

/**
 * Reads the connection settings of an SFTP or FTPS source from its object.
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
 * Reads the server an SFTP or FTPS source points at from its object.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class RemoteServerConfig {

	/**
	 * The server a source object describes.
	 *
	 * @param array<string, mixed> $source The source object.
	 *
	 * @return RemoteServer
	 *
	 * @throws RemoteFileException When the protocol is not SFTP or FTPS, or the host is missing.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function serverOf(array $source): RemoteServer {
		$configuration = (array)($source['configuration'] ?? []);
		$location      = trim((string)($source['location'] ?? ''));
		$parts         = ['host' => $location];
		if (str_contains($location, '://') === true) {
			$parts = (array)parse_url($location);
		}

		$protocol      = strtolower((string)($source['type'] ?? ($parts['scheme'] ?? '')));
		if (array_key_exists($protocol, RemoteServer::PROTOCOLS) === false) {
			throw new RemoteFileException('The source type must be sftp or ftps; plain FTP is not supported because it sends the password in the clear.');
		}

		$host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
		if ($host === '') {
			throw new RemoteFileException('The source has no host: set its location to the server name, for example sftp.example.nl.');
		}

		$port = (int)($configuration['port'] ?? ($parts['port'] ?? RemoteServer::PROTOCOLS[$protocol]));
		if ($port < 1 || $port > 65535) {
			throw new RemoteFileException('The port of the source must be between 1 and 65535.');
		}

		$authentication = (array)($configuration['authentication'] ?? []);
		$credentialRef  = (array)($authentication['credentialRef'] ?? []);

		return new RemoteServer(
			protocol: $protocol,
			host: $host,
			port: $port,
			username: (string)($configuration['username'] ?? ''),
			rootPath: (new RemotePath())->normaliseRoot(rootPath: (string)($configuration['rootPath'] ?? '/')),
			hostKeyFingerprint: (string)($configuration['hostKeyFingerprint'] ?? ''),
			authMode: (string)($authentication['mode'] ?? 'password'),
			credentialId: (string)($credentialRef['credentialId'] ?? ''),
			organisation: (string)($source['@self']['organisation'] ?? ($source['organisation'] ?? '')),
		);
	}//end serverOf()
}//end class
