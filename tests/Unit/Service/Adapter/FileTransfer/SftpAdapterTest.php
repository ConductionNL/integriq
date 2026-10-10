<?php

/**
 * SFTP and FTPS adapters: pin, path confinement, brokered credentials (REQ-SFTP-001).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer
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

namespace OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer;

use InvalidArgumentException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Exception\HostKeyMismatchException;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\FtpsClient;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemotePath;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\SftpClient;
use OCA\Integriq\Service\Security\EgressGuard;

class SftpAdapterTest extends FileTransferAdapterTestCase {

	/**
	 * A changed host key stops the connection, names both keys, and no credential is sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAChangedHostKeyIsRefusedNamingBothFingerprints(): void {
		$this->server->fingerprint = 'SHA256:ZZZdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

		try {
			$this->adapter()->listFiles(source: $this->source(), path: '.');
			$this->fail('A changed host key must stop the connection.');
		} catch (HostKeyMismatchException $exception) {
			$this->assertStringContainsString(self::PIN, $exception->getMessage());
			$this->assertStringContainsString('SHA256:ZZZdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ', $exception->getMessage());
		}

		$this->assertSame([], $this->resolved, 'No credential may be resolved for a server whose key changed.');
		$this->assertSame([], preg_grep('/^login:/', $this->server->calls));
		$this->assertContains('close', $this->server->calls);
	}//end testAChangedHostKeyIsRefusedNamingBothFingerprints()

	/**
	 * An SFTP source without a pin is refused for every operation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAnUnpinnedSftpSourceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->adapter()->listFiles(source: $this->source(['hostKeyFingerprint' => '']), path: '.');
	}//end testAnUnpinnedSftpSourceIsRefused()

	/**
	 * A path with .. is refused before any connection opens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAPathWithDotDotIsRefusedForEveryOperation(): void {
		$adapter = $this->adapter();
		$source = $this->source();
		$operations = [
			fn () => $adapter->listFiles(source: $source, path: '../etc'),
			fn () => $adapter->fetch(source: $source, path: 'a/../../etc/passwd'),
			fn () => $adapter->deliver(source: $source, path: '../x', localPath: __FILE__),
			fn () => $adapter->move(source: $source, from: 'a.csv', to: '../a.csv'),
			fn () => $adapter->deleteFile(source: $source, path: '/outgoing/../etc/passwd'),
		];

		foreach ($operations as $index => $operation) {
			try {
				$operation();
				$this->fail('Operation ' . $index . ' accepted a path with ..');
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], $this->server->calls, 'No connection may open for a refused path.');
	}//end testAPathWithDotDotIsRefusedForEveryOperation()

	/**
	 * Paths stay under the root; an absolute path outside it is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testRemotePathConfinesToTheRoot(): void {
		$this->assertSame('/outgoing/a.csv', RemotePath::resolve('/outgoing', 'a.csv'));
		$this->assertSame('/outgoing/sub/a.csv', RemotePath::resolve('/outgoing/', '/outgoing//sub/./a.csv'));
		$this->assertSame('/outgoing', RemotePath::resolve('/outgoing', '.'));
		$this->assertSame('/x/y', RemotePath::resolve('', '/x/y'));

		$this->expectException(InvalidArgumentException::class);
		RemotePath::resolve('/outgoing', '/outgoingevil/a.csv');
	}//end testRemotePathConfinesToTheRoot()

	/**
	 * The credential comes from the broker, and login follows the pin check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testTheBrokeredCredentialLogsInAfterThePinCheck(): void {
		$this->server->files = ['/outgoing/a.csv' => ['content' => 'x', 'mtime' => 1], '/outgoing/b.txt' => ['content' => 'y', 'mtime' => 1]];

		$files = $this->adapter()->listFiles(source: $this->source(['location' => 'sftp://sftp.example.nl:2222']), path: '.', pattern: '*.csv');

		$this->assertSame([['name' => 'a.csv', 'size' => 1, 'mtime' => 1, 'path' => '/outgoing/a.csv']], $files);
		$this->assertSame([['credentialName' => 'partner-sftp']], $this->resolved);
		$this->assertSame(['open:sftp.example.nl:2222', 'fingerprint', 'login:partner:s3cret:basic', 'list:/outgoing', 'close'], $this->server->calls);
		$this->assertSame(['https://sftp.example.nl'], $this->egressChecked);
	}//end testTheBrokeredCredentialLogsInAfterThePinCheck()

	/**
	 * A source without a credential reference is refused: no embedded secrets.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testASourceWithoutACredentialReferenceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->adapter()->listFiles(
			source: $this->source(['password' => 'inline', 'configuration' => ['authentication' => ['type' => 'basic', 'username' => 'partner']]]),
			path: '.'
		);
	}//end testASourceWithoutACredentialReferenceIsRefused()

	/**
	 * An internal host is refused before a connection opens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAnInternalHostIsRefusedByTheEgressGuard(): void {
		$guard = $this->createMock(EgressGuard::class);
		$guard->method('assertAllowed')->willThrowException(new EgressRefusedException(message: 'internal'));

		try {
			$this->adapter(guard: $guard)->listFiles(source: $this->source(['location' => '10.0.0.5']), path: '.');
			$this->fail('An internal host must be refused.');
		} catch (EgressRefusedException) {
			$this->assertSame(['close'], $this->server->calls);
		}
	}//end testAnInternalHostIsRefusedByTheEgressGuard()

	/**
	 * The first test of a new source shows the fingerprint and sends no credential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testTheFirstTestShowsTheFingerprintForConfirmation(): void {
		$result = $this->adapter()->testConnection(source: $this->source(['hostKeyFingerprint' => '']));

		$this->assertSame(self::PIN, $result['fingerprint']);
		$this->assertFalse($result['matches']);
		$this->assertFalse($result['connected']);
		$this->assertSame([], $this->resolved);

		$pinned = $this->adapter()->testConnection(source: $this->source());
		$this->assertTrue($pinned['matches']);
		$this->assertTrue($pinned['connected']);
	}//end testTheFirstTestShowsTheFingerprintForConfirmation()

	/**
	 * Fetch, deliver, move and delete reach the server at the confined paths.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testFetchDeliverMoveAndDelete(): void {
		$this->server->files = ['/outgoing/a.csv' => ['content' => 'id,name', 'mtime' => 1]];
		$adapter = $this->adapter();
		$source = $this->source();

		$local = $adapter->fetch(source: $source, path: 'a.csv');
		$this->assertSame('id,name', file_get_contents($local));

		$adapter->deliver(source: $source, path: 'reply.csv', localPath: $local);
		$adapter->move(source: $source, from: 'a.csv', to: 'processed/a.csv');
		$adapter->deleteFile(source: $source, path: 'reply.csv');
		unlink($local);

		$this->assertSame(['/outgoing/processed/a.csv'], array_keys($this->server->files));
	}//end testFetchDeliverMoveAndDelete()

	/**
	 * FTPS checks the certificate chain instead of a pin, so it needs none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testFtpsNeedsNoPinAndUsesPort21(): void {
		$this->adapter('ftps')->listFiles(source: $this->source(['type' => 'ftps', 'hostKeyFingerprint' => '']), path: '.');

		$this->assertSame('open:sftp.example.nl:21', $this->server->calls[0]);
		$this->assertNotContains('fingerprint', $this->server->calls);
	}//end testFtpsNeedsNoPinAndUsesPort21()

	/**
	 * The fingerprint matches what `ssh-keygen -lf` prints for the same key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testTheFingerprintIsTheOpenSshSha256Form(): void {
		$blob = base64_encode('ssh-ed25519-test-blob');
		$expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', 'ssh-ed25519-test-blob', true)), '=');

		$this->assertSame($expected, SftpClient::fingerprintOf('ssh-ed25519 ' . $blob));
		$this->assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+\/]{43}$/', $expected);
	}//end testTheFingerprintIsTheOpenSshSha256Form()

	/**
	 * MLSD listings keep files and drop folders.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testMlsdListingsKeepFilesOnly(): void {
		$listing = "type=cdir;modify=20261009120000; .\r\ntype=dir;modify=20261009120000; processed\r\n"
			. "type=file;size=12;modify=20261009120000; besluiten 1.csv\r\n";

		$this->assertSame(
			[['name' => 'besluiten 1.csv', 'size' => 12, 'mtime' => 1791547200]],
			FtpsClient::parseMlsd($listing)
		);
	}//end testMlsdListingsKeepFilesOnly()
}//end class
