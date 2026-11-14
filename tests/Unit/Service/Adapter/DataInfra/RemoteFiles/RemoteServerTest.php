<?php

/**
 * A source object is read into the connection settings of an SFTP or FTPS server.
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

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers RemoteServer.
 */
#[CoversClass(RemoteServer::class)]
#[UsesClass(RemotePath::class)]
#[UsesClass(RemoteFileException::class)]
final class RemoteServerTest extends TestCase {

	/**
	 * Every setting is read from the source.
	 *
	 * @return void
	 */
	public function testEverySettingIsReadFromTheSource(): void {
		$server = RemoteServer::fromSource(
			source: [
				'@self'         => ['organisation' => 'org-1'],
				'type'          => 'sftp',
				'location'      => 'sftp.example.nl',
				'configuration' => [
					'port'               => 2222,
					'username'           => 'gemeente',
					'rootPath'           => 'outgoing/',
					'hostKeyFingerprint' => 'SHA256:abc',
					'authentication'     => ['mode' => 'ssh-key', 'credentialRef' => ['credentialId' => 'cred-1']],
				],
			]
		);

		$this->assertSame(['sftp', 'sftp.example.nl', 2222, 'gemeente', '/outgoing', 'SHA256:abc', 'ssh-key', 'cred-1', 'org-1'], [$server->protocol, $server->host, $server->port, $server->username, $server->rootPath, $server->hostKeyFingerprint, $server->authMode, $server->credentialId, $server->organisation]);
	}//end testEverySettingIsReadFromTheSource()

	/**
	 * A URL location gives host and port, and FTPS defaults to port 21.
	 *
	 * @return void
	 */
	public function testAUrlLocationAndDefaults(): void {
		$sftp = RemoteServer::fromSource(source: ['type' => 'sftp', 'location' => 'sftp://Files.Example.nl:2022']);
		$this->assertSame(['files.example.nl', 2022, '/', 'password'], [$sftp->host, $sftp->port, $sftp->rootPath, $sftp->authMode]);

		$ftps = RemoteServer::fromSource(source: ['type' => 'ftps', 'location' => 'ftp.example.nl']);
		$this->assertSame(21, $ftps->port);
	}//end testAUrlLocationAndDefaults()

	/**
	 * Plain FTP, a missing host and a bad port are refused.
	 *
	 * @return void
	 */
	public function testPlainFtpAMissingHostAndABadPortAreRefused(): void {
		foreach ([
			[['type' => 'ftp', 'location' => 'ftp.example.nl'], 'plain FTP is not supported'],
			[['type' => 'sftp', 'location' => ''], 'has no host'],
			[['type' => 'sftp', 'location' => 'x.nl', 'configuration' => ['port' => 70000]], 'between 1 and 65535'],
		] as [$source, $message]) {
			try {
				RemoteServer::fromSource(source: $source);
				$this->fail('Accepted: ' . json_encode($source));
			} catch (RemoteFileException $refusal) {
				$this->assertStringContainsString($message, $refusal->getMessage());
			}
		}
	}//end testPlainFtpAMissingHostAndABadPortAreRefused()
}//end class
