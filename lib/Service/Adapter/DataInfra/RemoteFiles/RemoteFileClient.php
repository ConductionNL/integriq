<?php

/**
 * List, fetch, deliver, move and delete files on a partner's SFTP or FTPS server.
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

use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;

/**
 * Every operation runs the same checks before it touches the server, in this
 * order: the source is SFTP or FTPS with a host; the host passes the egress
 * guard, so a source cannot point into the instance's own network; every path
 * stays inside the source's root path; and the password or key comes from the
 * credential broker (`resolveInjectable()`, organisation scope, because an
 * SSH or FTP connection cannot go through the broker's HTTP proxy). For SFTP
 * the session factory then checks the host key pin before sending it.
 *
 * Each call opens and closes its own connection.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class RemoteFileClient {

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService  $credentialBroker OpenRegister's credential broker.
	 * @param EgressGuardInterface     $egressGuard      Refuses hosts inside the instance's network.
	 * @param RemoteFileSessionFactory $sessionFactory   Opens the connection.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialBrokerService $credentialBroker,
		private readonly EgressGuardInterface $egressGuard,
		private readonly RemoteFileSessionFactory $sessionFactory,
	) {

	}//end __construct()

	/**
	 * The files in a folder whose names match a pattern.
	 *
	 * @param array<string, mixed> $source  The source object.
	 * @param string               $path    The folder, relative to the root path.
	 * @param string               $pattern A shell pattern for the names, such as `*.csv`.
	 *
	 * @return list<RemoteFileEntry> Files only, sorted by name.
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function listFiles(array $source, string $path='', string $pattern='*'): array {
		$server    = RemoteServer::fromSource(source: $source);
		$directory = RemotePath::resolve(rootPath: $server->rootPath, path: $path);
		$relative  = trim($path, '/');

		$entries = $this->withSession(
			server: $server,
			operation: fn (RemoteFileSession $session): array => $session->listDirectory(directory: $directory)
		);

		$files = [];
		foreach ($entries as $entry) {
			if ($entry['isDirectory'] === true || fnmatch($pattern, $entry['name']) === false) {
				continue;
			}

			$files[] = new RemoteFileEntry(
				name: $entry['name'],
				path: ltrim($relative . '/' . $entry['name'], '/'),
				size: $entry['size'],
				modifiedAt: $entry['modifiedAt'],
			);
		}

		usort($files, fn (RemoteFileEntry $a, RemoteFileEntry $b): int => strcmp($a->name, $b->name));

		return $files;
	}//end listFiles()

	/**
	 * Copy a remote file into a local stream.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param string               $path   The file, relative to the root path.
	 * @param resource             $target The stream to write to.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function fetch(array $source, string $path, $target): void {
		$server = RemoteServer::fromSource(source: $source);
		$remote = RemotePath::resolve(rootPath: $server->rootPath, path: $path);
		$this->withSession(server: $server, operation: fn (RemoteFileSession $session) => $session->fetch(path: $remote, target: $target));
	}//end fetch()

	/**
	 * Write a local stream to a remote file.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param string               $path   The file, relative to the root path.
	 * @param resource             $stream The stream to read from.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function deliver(array $source, string $path, $stream): void {
		$server = RemoteServer::fromSource(source: $source);
		$remote = RemotePath::resolve(rootPath: $server->rootPath, path: $path);
		$this->withSession(server: $server, operation: fn (RemoteFileSession $session) => $session->deliver(path: $remote, source: $stream));
	}//end deliver()

	/**
	 * Move a remote file; both paths stay inside the root path.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param string               $from   The file, relative to the root path.
	 * @param string               $to     The new place, relative to the root path.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function move(array $source, string $from, string $to): void {
		$server     = RemoteServer::fromSource(source: $source);
		$remoteFrom = RemotePath::resolve(rootPath: $server->rootPath, path: $from);
		$remoteTo   = RemotePath::resolve(rootPath: $server->rootPath, path: $to);
		$this->withSession(server: $server, operation: fn (RemoteFileSession $session) => $session->move(from: $remoteFrom, to: $remoteTo));
	}//end move()

	/**
	 * Delete a remote file.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param string               $path   The file, relative to the root path.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function delete(array $source, string $path): void {
		$server = RemoteServer::fromSource(source: $source);
		$remote = RemotePath::resolve(rootPath: $server->rootPath, path: $path);
		if ($remote === $server->rootPath) {
			throw new RemoteFileException('The root path itself cannot be deleted.');
		}

		$this->withSession(server: $server, operation: fn (RemoteFileSession $session) => $session->delete(path: $remote));
	}//end delete()

	/**
	 * Run one operation on a fresh session, closing it afterwards.
	 *
	 * @param RemoteServer $server    The server.
	 * @param callable     $operation The operation, given the session.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @throws RemoteFileException When a check refuses or the server fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	private function withSession(RemoteServer $server, callable $operation): mixed {
		try {
			$this->egressGuard->assertAllowed(url: 'https://' . $server->host . ':' . $server->port . '/');
		} catch (EgressRefusedException $refusal) {
			throw new RemoteFileException($refusal->getMessage(), 0, $refusal);
		}

		$session = $this->sessionFactory->open(server: $server, secret: $this->secret(server: $server));
		try {
			return $operation($session);
		} finally {
			$session->close();
		}
	}//end withSession()

	/**
	 * The password or private key, from the credential broker.
	 *
	 * @param RemoteServer $server The server.
	 *
	 * @return string
	 *
	 * @throws RemoteFileException When no credential is set or the broker refuses it.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	private function secret(RemoteServer $server): string {
		if ($server->credentialId === '') {
			throw new RemoteFileException(
				'The source for ' . $server->host . ' has no credential: add the password or private key to the credential broker '
				. 'and set its id under configuration.authentication.credentialRef.credentialId.'
			);
		}

		$organisation = null;
		if ($server->organisation !== '') {
			$organisation = $server->organisation;
		}

		try {
			$secret = $this->credentialBroker->resolveInjectable(
				credentialId: $server->credentialId,
				appId: BrokeredCallServiceInterface::APP_ID,
				actingOrganisationId: $organisation
			);
		} catch (\Throwable $exception) {
			// The class only: a broker refusal can name the credential.
			throw new RemoteFileException('The credential broker refused the credential of the source for ' . $server->host . ' (' . $exception::class . ').');
		}

		if ($secret === null || $secret === '') {
			throw new RemoteFileException(
				'The credential of the source for ' . $server->host . ' holds no secret this app may read; '
				. 'it must be an inject-only credential with organisation scope.'
			);
		}

		return $secret;
	}//end secret()
}//end class
