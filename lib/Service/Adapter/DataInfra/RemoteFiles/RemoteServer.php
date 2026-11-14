<?php

/**
 * The connection settings of an SFTP or FTPS source.
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
 * Read from a source object: `type` (`sftp` or `ftps`), `location` (the host,
 * optionally `sftp://host:port`), `configuration.port`, `configuration.username`,
 * `configuration.rootPath`, `configuration.hostKeyFingerprint` and the
 * credential under `configuration.authentication.credentialRef.credentialId`.
 * The password or private key itself is never stored here.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class RemoteServer {

	/**
	 * The protocols this adapter speaks. Plain FTP is refused: it sends the password in the clear.
	 */
	public const PROTOCOLS = ['sftp' => 22, 'ftps' => 21];

	/**
	 * Constructor.
	 *
	 * @param string $protocol           `sftp` or `ftps`.
	 * @param string $host               The server's host name.
	 * @param int    $port               The port.
	 * @param string $username           The login name.
	 * @param string $rootPath           The folder every path stays inside.
	 * @param string $hostKeyFingerprint The pinned SHA-256 host key fingerprint (SFTP).
	 * @param string $authMode           `password` or `ssh-key`.
	 * @param string $credentialId       The credential broker object holding the password or key.
	 * @param string $organisation       The organisation the source belongs to, asserted to the broker on a sessionless run.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $protocol,
		public readonly string $host,
		public readonly int $port,
		public readonly string $username,
		public readonly string $rootPath,
		public readonly string $hostKeyFingerprint,
		public readonly string $authMode,
		public readonly string $credentialId,
		public readonly string $organisation='',
	) {

	}//end __construct()

	/**
	 * The server a source object describes.
	 *
	 * @param array<string, mixed> $source The source object.
	 *
	 * @return self
	 *
	 * @throws RemoteFileException When the protocol is not SFTP or FTPS, or the host is missing.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function fromSource(array $source): self {
		$configuration = (array)($source['configuration'] ?? []);
		$location      = trim((string)($source['location'] ?? ''));
		$parts         = ['host' => $location];
		if (str_contains($location, '://') === true) {
			$parts = (array)parse_url($location);
		}

		$protocol      = strtolower((string)($source['type'] ?? ($parts['scheme'] ?? '')));
		if (array_key_exists($protocol, self::PROTOCOLS) === false) {
			throw new RemoteFileException('The source type must be sftp or ftps; plain FTP is not supported because it sends the password in the clear.');
		}

		$host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
		if ($host === '') {
			throw new RemoteFileException('The source has no host: set its location to the server name, for example sftp.example.nl.');
		}

		$port = (int)($configuration['port'] ?? ($parts['port'] ?? self::PROTOCOLS[$protocol]));
		if ($port < 1 || $port > 65535) {
			throw new RemoteFileException('The port of the source must be between 1 and 65535.');
		}

		$authentication = (array)($configuration['authentication'] ?? []);
		$credentialRef  = (array)($authentication['credentialRef'] ?? []);

		return new self(
			protocol: $protocol,
			host: $host,
			port: $port,
			username: (string)($configuration['username'] ?? ''),
			rootPath: RemotePath::normaliseRoot(rootPath: (string)($configuration['rootPath'] ?? '/')),
			hostKeyFingerprint: (string)($configuration['hostKeyFingerprint'] ?? ''),
			authMode: (string)($authentication['mode'] ?? 'password'),
			credentialId: (string)($credentialRef['credentialId'] ?? ''),
			organisation: (string)($source['@self']['organisation'] ?? ($source['organisation'] ?? '')),
		);
	}//end fromSource()
}//end class
