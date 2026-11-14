<?php

/**
 * The host key is checked before the credential is sent.
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
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSessionFactory;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteServer;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\SftpSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers RemoteFileSessionFactory and SftpSession over a stand-in for phpseclib's SFTP.
 */
#[CoversClass(RemoteFileSessionFactory::class)]
#[CoversClass(SftpSession::class)]
#[UsesClass(HostKeyPin::class)]
#[UsesClass(RemoteServer::class)]
#[UsesClass(RemoteFileException::class)]
final class RemoteFileSessionFactoryTest extends TestCase {

	/**
	 * The server's key line.
	 */
	private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

	/**
	 * A stand-in for phpseclib3\Net\SFTP that records what it was asked.
	 *
	 * @param bool $loginSucceeds Whether the login is accepted.
	 *
	 * @return object
	 */
	private function sftp(bool $loginSucceeds=true): object {
		return new class(self::KEY, $loginSucceeds) {
			/** @var list<string> */
			public array $calls = [];

			/**
			 * @param string $key   The host key line.
			 * @param bool   $login Whether login succeeds.
			 */
			public function __construct(private string $key, private bool $login) {
			}

			/** @return string */
			public function getServerPublicHostKey() {
				$this->calls[] = 'hostkey';
				return $this->key;
			}

			/**
			 * @param string $user       The user.
			 * @param mixed  $credential The password or key.
			 *
			 * @return bool
			 */
			public function login($user, $credential) {
				$this->calls[] = 'login:' . $user . ':' . (string)$credential;
				return $this->login;
			}

			/** @return array<string, array<string, int>> */
			public function rawlist($dir) {
				$this->calls[] = 'rawlist:' . $dir;
				return ['.' => ['type' => 2], '..' => ['type' => 2], 'a.csv' => ['size' => 10, 'mtime' => 1700000000, 'type' => 1], 'archive' => ['type' => 2]];
			}

			/** @return bool */
			public function rename($from, $to) {
				$this->calls[] = 'rename:' . $from . '>' . $to;
				return false;
			}

			/** @return void */
			public function disconnect() {
				$this->calls[] = 'disconnect';
			}
		};
	}//end sftp()

	/**
	 * A server pinned to the given fingerprint.
	 *
	 * @param string $pin The pin.
	 *
	 * @return RemoteServer
	 */
	private function server(string $pin): RemoteServer {
		return new RemoteServer(protocol: 'sftp', host: 'sftp.example.nl', port: 22, username: 'gemeente', rootPath: '/outgoing', hostKeyFingerprint: $pin, authMode: 'password', credentialId: 'cred-1');
	}//end server()

	/**
	 * A key that differs from the pin is refused and the password is never sent.
	 *
	 * @return void
	 */
	public function testAChangedKeyIsRefusedBeforeTheLogin(): void {
		$sftp = $this->sftp();
		try {
			(new RemoteFileSessionFactory())->loggedIn(sftp: $sftp, server: $this->server(pin: 'SHA256:somethingElse'), secret: 'geheim');
			$this->fail('A changed key was accepted.');
		} catch (RemoteFileException $refusal) {
			$this->assertStringContainsString('SHA256:somethingElse', $refusal->getMessage());
			$this->assertStringContainsString(HostKeyPin::fingerprintOf(publicKey: self::KEY), $refusal->getMessage());
		}

		$this->assertSame(['hostkey'], $sftp->calls, 'only the host key was asked; no login');
	}//end testAChangedKeyIsRefusedBeforeTheLogin()

	/**
	 * The pinned key logs in, and the session lists and maps the folder.
	 *
	 * @return void
	 */
	public function testThePinnedKeyLogsInAndTheSessionLists(): void {
		$sftp    = $this->sftp();
		$session = (new RemoteFileSessionFactory())->loggedIn(sftp: $sftp, server: $this->server(pin: HostKeyPin::fingerprintOf(publicKey: self::KEY)), secret: 'geheim');

		$this->assertSame(
			[
				['name' => 'a.csv', 'size' => 10, 'modifiedAt' => 1700000000, 'isDirectory' => false],
				['name' => 'archive', 'size' => null, 'modifiedAt' => null, 'isDirectory' => true],
			],
			$session->listDirectory(directory: '/outgoing')
		);
		$this->assertSame(['hostkey', 'login:gemeente:geheim', 'rawlist:/outgoing'], $sftp->calls);
	}//end testThePinnedKeyLogsInAndTheSessionLists()

	/**
	 * A refused login disconnects and says whose login.
	 *
	 * @return void
	 */
	public function testARefusedLoginDisconnects(): void {
		$sftp = $this->sftp(loginSucceeds: false);
		try {
			(new RemoteFileSessionFactory())->loggedIn(sftp: $sftp, server: $this->server(pin: HostKeyPin::fingerprintOf(publicKey: self::KEY)), secret: 'geheim');
			$this->fail('A refused login opened a session.');
		} catch (RemoteFileException $refusal) {
			$this->assertSame('The SFTP server sftp.example.nl refused the login of "gemeente".', $refusal->getMessage());
		}

		$this->assertSame('disconnect', end($sftp->calls));
	}//end testARefusedLoginDisconnects()

	/**
	 * A failed server call becomes a message naming the path.
	 *
	 * @return void
	 */
	public function testAFailedMoveNamesBothPaths(): void {
		$session = new SftpSession(sftp: $this->sftp());
		$this->expectExceptionMessage('The file "/outgoing/a.csv" cannot be moved to "/outgoing/processed/a.csv" on the server.');
		$session->move(from: '/outgoing/a.csv', to: '/outgoing/processed/a.csv');
	}//end testAFailedMoveNamesBothPaths()

	/**
	 * FTPS is spoken through curl here; SFTP only when phpseclib 3 is installed.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsTheInstalledLibraries(): void {
		$factory = new RemoteFileSessionFactory();
		$this->assertSame(class_exists(RemoteFileSessionFactory::SFTP_CLASS), $factory->isAvailable(protocol: 'sftp'));
		$this->assertTrue($factory->isAvailable(protocol: 'ftps'));
		$this->assertFalse($factory->isAvailable(protocol: 'ftp'));
	}//end testAvailabilityFollowsTheInstalledLibraries()
}//end class
