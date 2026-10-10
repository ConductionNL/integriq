<?php

/**
 * Shared base of the SFTP and FTPS data-infra adapters.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
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

namespace OCA\Integriq\Service\Adapter\DataInfra;

use InvalidArgumentException;
use OCA\Integriq\Exception\HostKeyMismatchException;
use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemoteFileClientFactory;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemotePath;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A partner's file server as a source: list, fetch, deliver, move, delete.
 *
 * The source carries `location` (host, optionally `scheme://host:port`),
 * `rootPath`, `hostKeyFingerprint` (SFTP) and
 * `configuration.authentication` = `{type: basic|ssh-key, username,
 * credentialRef}`. The secret comes from the credential broker as an injected
 * secret (design D2: an SSH or FTPS connection cannot go through the broker's
 * HTTP proxy). Every operation opens its own connection, checks the server's
 * identity before it sends a credential, and closes it again (design D3).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The adapter joins the broker, egress guard, path guard and protocol seam on purpose.
 * @SuppressWarnings(PHPMD.StaticAccess) RemotePath is a pure path calculation; there is nothing to inject.
 */
abstract class AbstractFileTransferAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $credentialBroker The broker (base class contract).
	 * @param IAppConfig              $appConfig        App config.
	 * @param LoggerInterface         $logger           Logger.
	 * @param RemoteFileClientFactory $clientFactory    Opens connections.
	 * @param BrokeredCallService     $brokeredCalls    Resolves credential references.
	 * @param EgressGuard             $egressGuard      Refuses internal hosts.
	 * @param ITempManager            $tempManager      Temporary files for fetched content.
	 * @param ORObjectService         $objectService    Loads a source for the generic list().
	 * @param IL10N                   $l10n             Translations.
	 */
	public function __construct(
		CredentialBrokerService $credentialBroker,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		private readonly RemoteFileClientFactory $clientFactory,
		private readonly BrokeredCallService $brokeredCalls,
		private readonly EgressGuard $egressGuard,
		private readonly ITempManager $tempManager,
		private readonly ORObjectService $objectService,
		protected readonly IL10N $l10n,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);

	}//end __construct()

	/**
	 * The protocol: `sftp` or `ftps`.
	 *
	 * @return string The protocol.
	 */
	abstract public function protocol(): string;

	/**
	 * The port used when the location names none.
	 *
	 * @return int The port.
	 */
	abstract protected function defaultPort(): int;

	/**
	 * Whether the protocol pins the server's key (SFTP does, FTPS checks the certificate chain).
	 *
	 * @return bool True when a pin is mandatory.
	 */
	abstract protected function requiresPin(): bool;

	/**
	 * Capabilities per REQ-DIC-002's vocabulary.
	 *
	 * @return array<int,string> The capabilities.
	 */
	public function getCapabilities(): array {
		return ['read', 'write', 'bulk-import'];

	}//end getCapabilities()

	/**
	 * The adapter needs no app beyond integriq.
	 *
	 * @return string|null Always null.
	 */
	public function getRequiredApp(): ?string {
		return null;

	}//end getRequiredApp()

	/**
	 * Icon.
	 *
	 * @return string The MDI glyph.
	 */
	public function getIcon(): string {
		return 'FolderNetwork';

	}//end getIcon()

	/**
	 * Credentials live on each source, so the adapter itself is always usable.
	 *
	 * @return bool Always true.
	 */
	public function isEnabled(): bool {
		return true;

	}//end isEnabled()

	/**
	 * Health: configured per source, never on the app.
	 *
	 * @return array<string,mixed> The health descriptor.
	 */
	public function health(): array {
		return [
			'status' => 'ok',
			'authStatus' => 'per-source',
			'message' => null,
		];

	}//end health()

	/**
	 * First contact with a server: what it presents, and whether that matches the pin.
	 *
	 * Without a pin (a new source) nothing is sent beyond the key exchange, so
	 * the administrator can confirm the fingerprint before any credential
	 * leaves. With a pin the login and a listing of the root are tried too.
	 *
	 * @param array<string,mixed> $source The source.
	 *
	 * @return array{fingerprint:string,pinned:string,matches:bool,connected:bool,message:string|null} The outcome.
	 */
	public function testConnection(array $source): array {
		$pinned = (string)($source['hostKeyFingerprint'] ?? '');
		$result = ['fingerprint' => '', 'pinned' => $pinned, 'matches' => false, 'connected' => false, 'message' => null];

		$client = $this->clientFactory->create(protocol: $this->protocol());
		try {
			$this->openChecked(client: $client, source: $source, verifyPin: false);
			$result['fingerprint'] = $client->serverFingerprint();
			$result['matches'] = ($this->requiresPin() === false
				|| ($pinned !== '' && hash_equals($pinned, $result['fingerprint']) === true));
			if ($result['matches'] === false && $pinned === '') {
				$result['message'] = 'Confirm the server\'s fingerprint to pin it.';
				return $result;
			}

			if ($result['matches'] === false) {
				$result['message'] = (new HostKeyMismatchException(pinned: $pinned, presented: $result['fingerprint']))->getMessage();
				return $result;
			}

			$this->login(client: $client, source: $source);
			$client->listFiles(path: $this->rootPath(source: $source));
			$result['connected'] = true;
		} catch (Throwable $exception) {
			$result['message'] = $exception->getMessage();
		} finally {
			$client->close();
		}//end try

		return $result;

	}//end testConnection()

	/**
	 * The files in a folder whose name matches a pattern.
	 *
	 * @param array<string,mixed> $source  The source.
	 * @param string              $path    The folder, relative to the root path or absolute under it.
	 * @param string              $pattern A shell pattern such as `*.csv`.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int,path:string}> The files.
	 */
	public function listFiles(array $source, string $path, string $pattern = '*'): array {
		$folder = RemotePath::resolve(rootPath: $this->rootPath(source: $source), path: $path);

		return $this->withConnection(
			source: $source,
			operation: function (RemoteFileClient $client) use ($folder, $pattern): array {
				$files = [];
				foreach ($client->listFiles(path: $folder) as $file) {
					if (fnmatch($pattern, $file['name']) === false) {
						continue;
					}

					$file['path'] = rtrim($folder, '/') . '/' . $file['name'];
					$files[] = $file;
				}

				return $files;
			}
		);

	}//end listFiles()

	/**
	 * Fetch a file to a local temporary file.
	 *
	 * @param array<string,mixed> $source The source.
	 * @param string              $path   The file.
	 *
	 * @return string The local temporary file; the caller removes it.
	 */
	public function fetch(array $source, string $path): string {
		$remote = RemotePath::resolve(rootPath: $this->rootPath(source: $source), path: $path);
		$local = $this->tempManager->getTemporaryFile();
		if ($local === false) {
			throw new RuntimeException(message: 'No temporary file could be created.');
		}

		try {
			$this->withConnection(
				source: $source,
				operation: static fn (RemoteFileClient $client) => $client->download(remotePath: $remote, localPath: $local)
			);
		} catch (Throwable $exception) {
			if (file_exists($local) === true) {
				unlink($local);
			}

			throw $exception;
		}

		return $local;

	}//end fetch()

	/**
	 * Deliver a local file to the server.
	 *
	 * @param array<string,mixed> $source    The source.
	 * @param string              $path      The remote file to write.
	 * @param string              $localPath The local file.
	 *
	 * @return void
	 */
	public function deliver(array $source, string $path, string $localPath): void {
		$remote = RemotePath::resolve(rootPath: $this->rootPath(source: $source), path: $path);
		$this->withConnection(
			source: $source,
			operation: static fn (RemoteFileClient $client) => $client->upload(remotePath: $remote, localPath: $localPath)
		);

	}//end deliver()

	/**
	 * Move a remote file; both ends stay under the root path.
	 *
	 * @param array<string,mixed> $source The source.
	 * @param string              $from   The current path.
	 * @param string              $to     The new path.
	 *
	 * @return void
	 */
	public function move(array $source, string $from, string $to): void {
		$root = $this->rootPath(source: $source);
		$fromPath = RemotePath::resolve(rootPath: $root, path: $from);
		$toPath = RemotePath::resolve(rootPath: $root, path: $to);
		$this->withConnection(
			source: $source,
			operation: static fn (RemoteFileClient $client) => $client->rename(from: $fromPath, to: $toPath)
		);

	}//end move()

	/**
	 * Delete a remote file.
	 *
	 * @param array<string,mixed> $source The source.
	 * @param string              $path   The file.
	 *
	 * @return void
	 */
	public function deleteFile(array $source, string $path): void {
		$remote = RemotePath::resolve(rootPath: $this->rootPath(source: $source), path: $path);
		$this->withConnection(
			source: $source,
			operation: static fn (RemoteFileClient $client) => $client->delete(path: $remote)
		);

	}//end deleteFile()

	/**
	 * Generic integration list: `filters` carries `sourceId`, `path` and `pattern`.
	 *
	 * @param string              $register Unused: a file server is not a register.
	 * @param string              $schema   Unused.
	 * @param string              $objectId Unused.
	 * @param array<string,mixed> $filters  `sourceId`, `path`, `pattern`.
	 *
	 * @return array<int,array<string,mixed>> The files.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$sourceId = (string)($filters['sourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		$entity = $this->objectService->find(id: $sourceId, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		if (($entity instanceof ObjectEntity) === false) {
			return [];
		}

		return $this->listFiles(
			source: $entity->getObject(),
			path: (string)($filters['path'] ?? '.'),
			pattern: (string)($filters['pattern'] ?? '*')
		);

	}//end list()

	/**
	 * The source's root path.
	 *
	 * @param array<string,mixed> $source The source.
	 *
	 * @return string The root path, `/` when unset.
	 */
	protected function rootPath(array $source): string {
		$root = (string)($source['rootPath'] ?? '');
		if ($root === '') {
			return '/';
		}

		return $root;

	}//end rootPath()

	/**
	 * Run one operation on a fresh, checked, logged-in connection.
	 *
	 * @param array<string,mixed> $source    The source.
	 * @param callable            $operation Receives the client.
	 *
	 * @return mixed What the operation returned.
	 */
	private function withConnection(array $source, callable $operation): mixed {
		$client = $this->clientFactory->create(protocol: $this->protocol());
		try {
			$this->openChecked(client: $client, source: $source, verifyPin: true);
			$this->login(client: $client, source: $source);

			return $operation($client);
		} finally {
			$client->close();
		}

	}//end withConnection()

	/**
	 * Open the connection after the egress check, and check the pin before any credential is sent.
	 *
	 * @param RemoteFileClient    $client    The client.
	 * @param array<string,mixed> $source    The source.
	 * @param bool                $verifyPin Whether a missing or different pin stops here.
	 *
	 * @return void
	 *
	 * @throws HostKeyMismatchException When the server's key differs from the pin.
	 * @throws InvalidArgumentException When the source names no host or no pin.
	 */
	private function openChecked(RemoteFileClient $client, array $source, bool $verifyPin): void {
		[$host, $port] = $this->hostAndPort(source: $source);

		// The egress guard speaks URLs; only its host rules apply to a file server.
		$this->egressGuard->assertAllowed(url: 'https://' . $host);
		$client->open(host: $host, port: $port);

		if ($verifyPin === false || $this->requiresPin() === false) {
			return;
		}

		$pinned = (string)($source['hostKeyFingerprint'] ?? '');
		if ($pinned === '') {
			throw new InvalidArgumentException(message: 'The source has no pinned host key. Test the connection and confirm the fingerprint first.');
		}

		$presented = $client->serverFingerprint();
		if (hash_equals($pinned, $presented) === false) {
			throw new HostKeyMismatchException(pinned: $pinned, presented: $presented);
		}

	}//end openChecked()

	/**
	 * Log in with the brokered secret.
	 *
	 * @param RemoteFileClient    $client The client.
	 * @param array<string,mixed> $source The source.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source has no credential reference.
	 */
	private function login(RemoteFileClient $client, array $source): void {
		$authentication = ($source['configuration']['authentication'] ?? []);
		if (is_array($authentication) === false || is_array(($authentication['credentialRef'] ?? null)) === false) {
			throw new InvalidArgumentException(message: 'The source has no credential reference; credentials come from the credential broker.');
		}

		$username = (string)($authentication['username'] ?? ($source['username'] ?? ''));
		$secret = $this->brokeredCalls->resolveCredentialRef(ref: $authentication['credentialRef']);
		$client->login(username: $username, secret: $secret, authMode: (string)($authentication['type'] ?? 'basic'));

	}//end login()

	/**
	 * Host and port from the source's location.
	 *
	 * @param array<string,mixed> $source The source.
	 *
	 * @return array{0:string,1:int} Host and port.
	 *
	 * @throws InvalidArgumentException When the location names no host.
	 */
	private function hostAndPort(array $source): array {
		$location = trim((string)($source['location'] ?? ''));
		if (str_contains($location, '://') === false) {
			$location = $this->protocol() . '://' . $location;
		}

		$parts = parse_url($location);
		$host = (string)($parts['host'] ?? '');
		if ($host === '') {
			throw new InvalidArgumentException(message: 'The source names no host.');
		}

		return [$host, (int)($parts['port'] ?? $this->defaultPort())];

	}//end hostAndPort()
}//end class
