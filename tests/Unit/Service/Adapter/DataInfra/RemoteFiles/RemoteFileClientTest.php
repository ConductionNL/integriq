<?php

/**
 * Every remote file operation passes the source, egress, path and credential checks first.
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

use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSession;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSessionFactory;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteServer;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers RemoteFileClient.
 */
#[CoversClass(RemoteFileClient::class)]
#[UsesClass(RemoteFileEntry::class)]
#[UsesClass(RemoteFileException::class)]
#[UsesClass(RemotePath::class)]
#[UsesClass(RemoteServer::class)]
final class RemoteFileClientTest extends TestCase {

	/**
	 * The credential broker.
	 *
	 * @var CredentialBrokerService&MockObject
	 */
	private CredentialBrokerService $broker;

	/**
	 * The egress guard.
	 *
	 * @var EgressGuardInterface&MockObject
	 */
	private EgressGuardInterface $egress;

	/**
	 * The session factory.
	 *
	 * @var RemoteFileSessionFactory&MockObject
	 */
	private RemoteFileSessionFactory $factory;

	/**
	 * What the session was asked, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->broker  = $this->createMock(CredentialBrokerService::class);
		$this->egress  = $this->createMock(EgressGuardInterface::class);
		$this->factory = $this->createMock(RemoteFileSessionFactory::class);
		$calls         = &$this->calls;
		$session       = new class($calls) implements RemoteFileSession {
			/**
			 * @param list<string> $calls The shared call log.
			 */
			public function __construct(private array &$calls) {
			}

			/** {@inheritDoc} */
			public function listDirectory(string $directory): array {
				$this->calls[] = 'list ' . $directory;
				return [
					['name' => 'b.csv', 'size' => 2, 'modifiedAt' => 20, 'isDirectory' => false],
					['name' => 'a.csv', 'size' => 1, 'modifiedAt' => 10, 'isDirectory' => false],
					['name' => 'a.txt', 'size' => 1, 'modifiedAt' => 10, 'isDirectory' => false],
					['name' => 'old.csv', 'size' => null, 'modifiedAt' => null, 'isDirectory' => true],
				];
			}

			/** {@inheritDoc} */
			public function fetch(string $path, $target): void {
				$this->calls[] = 'fetch ' . $path;
				fwrite($target, 'content');
			}

			/** {@inheritDoc} */
			public function deliver(string $path, $source): void {
				$this->calls[] = 'deliver ' . $path . ' ' . stream_get_contents($source);
			}

			/** {@inheritDoc} */
			public function move(string $from, string $to): void {
				$this->calls[] = 'move ' . $from . ' ' . $to;
			}

			/** {@inheritDoc} */
			public function delete(string $path): void {
				$this->calls[] = 'delete ' . $path;
			}

			/** {@inheritDoc} */
			public function close(): void {
				$this->calls[] = 'close';
			}
		};
		$this->factory->method('open')->willReturn($session);
	}//end setUp()

	/**
	 * The client under test.
	 *
	 * @return RemoteFileClient
	 */
	private function client(): RemoteFileClient {
		return new RemoteFileClient(credentialBroker: $this->broker, egressGuard: $this->egress, sessionFactory: $this->factory);
	}//end client()

	/**
	 * An SFTP source as an administrator saves it.
	 *
	 * @param string $credentialId The credential id.
	 *
	 * @return array<string, mixed>
	 */
	private function source(string $credentialId='cred-1'): array {
		return [
			'@self'         => ['organisation' => 'org-1'],
			'type'          => 'sftp',
			'location'      => 'sftp.example.nl',
			'configuration' => [
				'username'           => 'gemeente',
				'rootPath'           => '/outgoing',
				'hostKeyFingerprint' => 'SHA256:abc',
				'authentication'     => ['credentialRef' => ['credentialId' => $credentialId]],
			],
		];
	}//end source()

	/**
	 * A listing keeps matching files only, sorted, with paths relative to the root.
	 *
	 * @return void
	 */
	public function testAListingKeepsMatchingFilesSortedByName(): void {
		$this->broker->expects($this->once())->method('resolveInjectable')
			->with('cred-1', 'openconnector', null, 'org-1')
			->willReturn('geheim');
		$this->egress->expects($this->once())->method('assertAllowed')->with('https://sftp.example.nl:22/');
		$this->factory->expects($this->once())->method('open')->with($this->anything(), 'geheim');

		$files = $this->client()->listFiles(source: $this->source(), path: 'besluiten', pattern: '*.csv');

		$this->assertSame(
			[
				['name' => 'a.csv', 'path' => 'besluiten/a.csv', 'size' => 1, 'modifiedAt' => 10, 'isDirectory' => false],
				['name' => 'b.csv', 'path' => 'besluiten/b.csv', 'size' => 2, 'modifiedAt' => 20, 'isDirectory' => false],
			],
			array_map(fn (RemoteFileEntry $file): array => $file->toArray(), $files)
		);
		$this->assertSame(['list /outgoing/besluiten', 'close'], $this->calls);
	}//end testAListingKeepsMatchingFilesSortedByName()

	/**
	 * A path with .. is refused before anything is resolved or connected, for every operation.
	 *
	 * @return void
	 */
	public function testAPathWithParentSegmentsIsRefusedBeforeAnyConnection(): void {
		$this->broker->expects($this->never())->method('resolveInjectable');
		$this->egress->expects($this->never())->method('assertAllowed');
		$this->factory->expects($this->never())->method('open');
		$client = $this->client();
		$stream = fopen('php://memory', 'w+b');

		foreach ([
			fn () => $client->listFiles(source: $this->source(), path: '../etc'),
			fn () => $client->fetch(source: $this->source(), path: '../a.csv', target: $stream),
			fn () => $client->deliver(source: $this->source(), path: 'x/../../a.csv', stream: $stream),
			fn () => $client->move(source: $this->source(), from: 'a.csv', to: '../a.csv'),
			fn () => $client->delete(source: $this->source(), path: '..'),
		] as $index => $operation) {
			try {
				$operation();
				$this->fail('Operation ' . $index . ' was not refused.');
			} catch (RemoteFileException $refusal) {
				$this->assertStringContainsString('".." is not allowed', $refusal->getMessage());
			}
		}

		$this->assertSame([], $this->calls);
	}//end testAPathWithParentSegmentsIsRefusedBeforeAnyConnection()

	/**
	 * A host inside the instance's network is refused before the credential is read.
	 *
	 * @return void
	 */
	public function testAnInternalHostIsRefusedBeforeTheCredentialIsRead(): void {
		$this->egress->method('assertAllowed')->willThrowException(new EgressRefusedException(message: 'The host "sftp.example.nl" resolves to a loopback, private or reserved address.'));
		$this->broker->expects($this->never())->method('resolveInjectable');

		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('resolves to a loopback, private or reserved address');
		$this->client()->listFiles(source: $this->source());
	}//end testAnInternalHostIsRefusedBeforeTheCredentialIsRead()

	/**
	 * A source without a credential, or one the broker refuses, does not connect.
	 *
	 * @return void
	 */
	public function testAMissingOrRefusedCredentialDoesNotConnect(): void {
		$this->factory->expects($this->never())->method('open');

		try {
			$this->client()->listFiles(source: $this->source(credentialId: ''));
			$this->fail('Connected without a credential.');
		} catch (RemoteFileException $refusal) {
			$this->assertStringContainsString('has no credential', $refusal->getMessage());
		}

		$this->broker->method('resolveInjectable')->willThrowException(new \RuntimeException('credential cred-2 secret leak'));
		try {
			$this->client()->listFiles(source: $this->source(credentialId: 'cred-2'));
			$this->fail('Connected with a refused credential.');
		} catch (RemoteFileException $refusal) {
			$this->assertSame('The credential broker refused the credential of the source for sftp.example.nl (RuntimeException).', $refusal->getMessage());
		}
	}//end testAMissingOrRefusedCredentialDoesNotConnect()

	/**
	 * A proxy-only credential has no secret to hand over, and is refused.
	 *
	 * @return void
	 */
	public function testAProxyCredentialIsRefused(): void {
		$this->broker->method('resolveInjectable')->willReturn(null);
		$this->factory->expects($this->never())->method('open');

		$this->expectExceptionMessage('it must be an inject-only credential with organisation scope');
		$this->client()->listFiles(source: $this->source());
	}//end testAProxyCredentialIsRefused()

	/**
	 * Fetch, deliver, move and delete reach the server at their confined paths, each on its own connection.
	 *
	 * @return void
	 */
	public function testTheOperationsReachTheServerAtTheirConfinedPaths(): void {
		$this->broker->method('resolveInjectable')->willReturn('geheim');
		$client = $this->client();
		$target = fopen('php://memory', 'w+b');
		$client->fetch(source: $this->source(), path: 'a.csv', target: $target);
		rewind($target);
		$this->assertSame('content', stream_get_contents($target));

		$source = fopen('php://memory', 'w+b');
		fwrite($source, 'answer');
		rewind($source);
		$client->deliver(source: $this->source(), path: '/incoming/b.csv', stream: $source);
		$client->move(source: $this->source(), from: 'a.csv', to: 'processed/a.csv');
		$client->delete(source: $this->source(), path: 'processed/a.csv');

		$this->assertSame(
			['fetch /outgoing/a.csv', 'close', 'deliver /outgoing/incoming/b.csv answer', 'close', 'move /outgoing/a.csv /outgoing/processed/a.csv', 'close', 'delete /outgoing/processed/a.csv', 'close'],
			$this->calls
		);
	}//end testTheOperationsReachTheServerAtTheirConfinedPaths()

	/**
	 * The root path itself is never deleted.
	 *
	 * @return void
	 */
	public function testTheRootItselfIsNeverDeleted(): void {
		$this->factory->expects($this->never())->method('open');
		$this->expectExceptionMessage('The root path itself cannot be deleted.');
		$this->client()->delete(source: $this->source(), path: '/');
	}//end testTheRootItselfIsNeverDeleted()
}//end class
