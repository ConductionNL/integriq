<?php

/**
 * Pins an SFTP server's host key by its SHA-256 fingerprint.
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
 * The fingerprint is the OpenSSH form, `SHA256:` and the unpadded base64 of the
 * SHA-256 of the key blob, so an administrator can compare it with what
 * `ssh-keygen -lf` prints on the partner's side. A pin is required: a source
 * without one is refused, and the refusal shows the server's fingerprint so it
 * can be confirmed and saved. A changed key is refused naming both.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class HostKeyPin {

	/**
	 * The fingerprint of an OpenSSH public key line (`ssh-ed25519 AAAA... comment`) or a raw key blob.
	 *
	 * @param string $publicKey The key as the server presented it.
	 *
	 * @return string The fingerprint, `SHA256:...`.
	 *
	 * @throws RemoteFileException When the key cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function fingerprintOf(string $publicKey): string {
		$blob  = $publicKey;
		$parts = preg_split('/\s+/', trim($publicKey));
		if (is_array($parts) === true && count($parts) >= 2 && preg_match('/^(ssh|ecdsa)-/', $parts[0]) === 1) {
			$blob = base64_decode($parts[1], true);
			if ($blob === false || $blob === '') {
				throw new RemoteFileException('The server presented a host key that cannot be read.');
			}
		}

		if ($blob === '') {
			throw new RemoteFileException('The server presented no host key.');
		}

		return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
	}//end fingerprintOf()

	/**
	 * Refuse a host key that does not match the pin.
	 *
	 * @param string $pinned    The fingerprint saved on the source; empty when none was saved yet.
	 * @param string $presented The fingerprint of the key the server presented.
	 * @param string $host      The host, for the message.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When there is no pin, or the key differs.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function assertMatches(string $pinned, string $presented, string $host): void {
		$pinned = self::normalise(fingerprint: $pinned);
		if ($pinned === '') {
			throw new RemoteFileException(
				sprintf(
					'The SFTP source for %s has no pinned host key. The server presents %s: '
					. 'check it with the partner, save it as the host key fingerprint, then connect again.',
					$host,
					$presented
				)
			);
		}

		if (hash_equals($pinned, self::normalise(fingerprint: $presented)) === false) {
			throw new RemoteFileException(
				sprintf(
					'The host key of %s has changed: the source is pinned to %s, the server presents %s. The connection was refused and no credential was sent.',
					$host,
					$pinned,
					$presented
				)
			);
		}
	}//end assertMatches()

	/**
	 * A fingerprint in its canonical form: `SHA256:` prefix, no padding.
	 *
	 * @param string $fingerprint The fingerprint as entered.
	 *
	 * @return string The canonical form, or empty for an empty input.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function normalise(string $fingerprint): string {
		$fingerprint = trim($fingerprint);
		if ($fingerprint === '') {
			return '';
		}

		if (stripos($fingerprint, 'SHA256:') === 0) {
			$fingerprint = substr($fingerprint, 7);
		}

		return 'SHA256:' . rtrim($fingerprint, '=');
	}//end normalise()
}//end class
