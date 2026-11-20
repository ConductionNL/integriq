<?php

/**
 * Opens an SFTP or FTPS session for a server.
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

use SensitiveParameter;

/**
 * For SFTP the host key is checked against the pin BEFORE the credential is
 * sent: phpseclib exchanges keys on the first call that needs the connection,
 * so asking for the server's host key connects without logging in.
 *
 * SFTP needs phpseclib 3 (`phpseclib3\Net\SFTP`). When no app on the
 * instance provides it, an SFTP source is reported unavailable rather than
 * failing at the first run.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class RemoteFileSessionFactory {

	/**
	 * The phpseclib 3 SFTP class.
	 */
	public const SFTP_CLASS = 'phpseclib3\\Net\\SFTP';

	/**
	 * The phpseclib 3 key loader.
	 */
	public const KEY_LOADER_CLASS = 'phpseclib3\\Crypt\\PublicKeyLoader';

	/**
	 * Constructor.
	 *
	 * The SFTP class is a parameter rather than written into the code, because
	 * phpseclib is optional: it is only instantiated once class_exists() said it
	 * is there.
	 *
	 * @param string $sftpClass The SFTP client class.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sftpClass=self::SFTP_CLASS,
	) {

	}//end __construct()

	/**
	 * What is missing when a protocol cannot be spoken.
	 */
	private const MISSING = ['sftp' => 'phpseclib 3 is not installed', 'ftps' => 'curl with TLS is missing'];

	/**
	 * Whether a protocol can be spoken on this instance.
	 *
	 * @param string $protocol `sftp` or `ftps`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function isAvailable(string $protocol): bool {
		if ($protocol === 'sftp') {
			return class_exists($this->sftpClass) === true;
		}

		return $protocol === 'ftps' && function_exists('curl_init') === true && defined('CURLUSESSL_ALL') === true;
	}//end isAvailable()

	/**
	 * Open a session.
	 *
	 * @param RemoteServer $server The server.
	 * @param string       $secret The password, or the private key for `ssh-key`.
	 *
	 * @return RemoteFileSession
	 *
	 * @throws RemoteFileException When the protocol is unavailable, the host key differs or the login fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function open(RemoteServer $server, #[SensitiveParameter] string $secret): RemoteFileSession {
		if ($this->isAvailable(protocol: $server->protocol) === false) {
			throw new RemoteFileException(
				sprintf('%s is not available on this instance: %s.', strtoupper($server->protocol), self::MISSING[$server->protocol])
			);
		}

		if ($server->protocol === 'ftps') {
			return new FtpsSession(server: $server, password: $secret);
		}

		$class = $this->sftpClass;
		$sftp  = new $class($server->host, $server->port, 15);

		return $this->loggedIn(sftp: $sftp, server: $server, secret: $secret);
	}//end open()

	/**
	 * The host key fingerprint an SFTP server presents, read without logging in.
	 *
	 * No credential is sent: the connection ends after the key exchange. This
	 * is what an administrator checks with the partner before pinning it.
	 *
	 * @param RemoteServer $server The server.
	 *
	 * @return string The SHA-256 fingerprint, `SHA256:...`.
	 *
	 * @throws RemoteFileException When the server is not SFTP, SFTP is unavailable, or no key is presented.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function presentedFingerprint(RemoteServer $server): string {
		if ($server->protocol !== 'sftp') {
			throw new RemoteFileException(
				'Only an SFTP server has a host key to pin; ' . strtoupper($server->protocol) . ' checks the server certificate instead.'
			);
		}

		if ($this->isAvailable(protocol: 'sftp') === false) {
			throw new RemoteFileException(sprintf('SFTP is not available on this instance: %s.', self::MISSING['sftp']));
		}

		$class = $this->sftpClass;
		return $this->fingerprintOfConnection(sftp: new $class($server->host, $server->port, 15), server: $server);
	}//end presentedFingerprint()

	/**
	 * The fingerprint a connected SFTP object presents; the connection is closed after.
	 *
	 * @param object       $sftp   A phpseclib3\Net\SFTP (or an object with its methods).
	 * @param RemoteServer $server The server, for messages.
	 *
	 * @return string The SHA-256 fingerprint.
	 *
	 * @throws RemoteFileException When no key is presented.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function fingerprintOfConnection(object $sftp, RemoteServer $server): string {
		try {
			return $this->fingerprintPresentedBy(sftp: $sftp, server: $server);
		} finally {
			$sftp->disconnect();
		}
	}//end fingerprintOfConnection()

	/**
	 * The fingerprint of the host key a connected SFTP object presents.
	 *
	 * @param object       $sftp   A phpseclib3\Net\SFTP (or an object with its methods).
	 * @param RemoteServer $server The server, for messages.
	 *
	 * @return string The SHA-256 fingerprint.
	 *
	 * @throws RemoteFileException When no key is presented.
	 */
	private function fingerprintPresentedBy(object $sftp, RemoteServer $server): string {
		$hostKey = $sftp->getServerPublicHostKey();
		if (is_string($hostKey) === false || $hostKey === '') {
			throw new RemoteFileException('The SFTP server ' . $server->host . ' presented no host key.');
		}

		return (new HostKeyPin())->fingerprintOf(publicKey: $hostKey);
	}//end fingerprintPresentedBy()

	/**
	 * Check the host key of a connected SFTP object, then log in.
	 *
	 * @param object       $sftp   A phpseclib3\Net\SFTP (or an object with its methods).
	 * @param RemoteServer $server The server.
	 * @param string       $secret The password or private key.
	 *
	 * @return SftpSession
	 *
	 * @throws RemoteFileException When the host key differs from the pin, or the login fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function loggedIn(object $sftp, RemoteServer $server, #[SensitiveParameter] string $secret): SftpSession {
		(new HostKeyPin())->assertMatches(
			pinned: $server->hostKeyFingerprint,
			presented: $this->fingerprintPresentedBy(sftp: $sftp, server: $server),
			host: $server->host
		);

		$credential = $secret;
		if ($server->authMode === 'ssh-key') {
			$loader     = self::KEY_LOADER_CLASS;
			$credential = $loader::load($secret);
		}

		if ($sftp->login($server->username, $credential) !== true) {
			$sftp->disconnect();
			throw new RemoteFileException('The SFTP server ' . $server->host . ' refused the login of "' . $server->username . '".');
		}

		return new SftpSession(sftp: $sftp);
	}//end loggedIn()
}//end class
