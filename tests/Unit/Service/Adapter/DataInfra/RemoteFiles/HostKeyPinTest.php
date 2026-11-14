<?php

/**
 * An SFTP server's host key is pinned by its SHA-256 fingerprint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\HostKeyPin;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers HostKeyPin.
 */
#[CoversClass(HostKeyPin::class)]
#[CoversClass(RemoteFileException::class)]
final class HostKeyPinTest extends TestCase {

	/**
	 * The fingerprint is OpenSSH's form: SHA256 of the key blob, unpadded base64.
	 *
	 * @return void
	 */
	public function testTheFingerprintIsTheOpenSshForm(): void {
		$blob     = random_bytes(51);
		$expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');

		$this->assertSame($expected, HostKeyPin::fingerprintOf(publicKey: 'ssh-ed25519 ' . base64_encode($blob) . ' root@partner'));
		$this->assertSame($expected, HostKeyPin::fingerprintOf(publicKey: 'ecdsa-sha2-nistp256 ' . base64_encode($blob)));
		$this->assertSame($expected, HostKeyPin::fingerprintOf(publicKey: $blob), 'a raw blob hashes the same');
		$this->assertStringNotContainsString('=', $expected);
	}//end testTheFingerprintIsTheOpenSshForm()

	/**
	 * A matching key passes, however the pin was typed.
	 *
	 * @return void
	 */
	public function testAMatchingKeyPasses(): void {
		HostKeyPin::assertMatches(pinned: 'SHA256:abcDEF', presented: 'SHA256:abcDEF', host: 'sftp.example.nl');
		HostKeyPin::assertMatches(pinned: ' abcDEF= ', presented: 'SHA256:abcDEF', host: 'sftp.example.nl');
		$this->assertSame('SHA256:abcDEF', HostKeyPin::normalise(fingerprint: 'sha256:abcDEF=='));
	}//end testAMatchingKeyPasses()

	/**
	 * A changed key is refused naming both fingerprints.
	 *
	 * @return void
	 */
	public function testAChangedKeyIsRefusedNamingBothFingerprints(): void {
		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('The host key of sftp.example.nl has changed: the source is pinned to SHA256:pinned, the server presents SHA256:other. The connection was refused and no credential was sent.');

		HostKeyPin::assertMatches(pinned: 'SHA256:pinned', presented: 'SHA256:other', host: 'sftp.example.nl');
	}//end testAChangedKeyIsRefusedNamingBothFingerprints()

	/**
	 * Without a pin the connection is refused and the server's fingerprint is shown for confirmation.
	 *
	 * @return void
	 */
	public function testWithoutAPinTheServerFingerprintIsShown(): void {
		try {
			HostKeyPin::assertMatches(pinned: '', presented: 'SHA256:server', host: 'sftp.example.nl');
			$this->fail('A source without a pin connected.');
		} catch (RemoteFileException $refusal) {
			$this->assertStringContainsString('has no pinned host key', $refusal->getMessage());
			$this->assertStringContainsString('SHA256:server', $refusal->getMessage());
		}
	}//end testWithoutAPinTheServerFingerprintIsShown()

	/**
	 * An unreadable key line is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableKeyIsRefused(): void {
		$this->expectExceptionMessage('The server presented a host key that cannot be read.');
		HostKeyPin::fingerprintOf(publicKey: 'ssh-rsa !!!notbase64');
	}//end testAnUnreadableKeyIsRefused()
}//end class
