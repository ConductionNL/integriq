<?php

/**
 * A folder in Nextcloud Files as a source.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
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
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * A source of type `nextcloud-folder` names a folder by its owner
 * (`configuration.ownerUid`) and its path in that owner's Files
 * (`configuration.rootPath`). Every operation resolves its path inside that
 * root, so a `..` segment is refused before any file is touched, exactly as
 * for an SFTP or FTPS source. No credential is involved: the folder is read
 * as its owner through `IRootFolder::getUserFolder()`.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class NextcloudFolderAdapter extends AbstractIntegrationProvider {

	/**
	 * The source type this adapter serves.
	 */
	public const SOURCE_TYPE = 'nextcloud-folder';

	/**
	 * Constructor.
	 *
	 * @param IL10N       $l10n       Translator for labels and messages.
	 * @param IRootFolder $rootFolder Nextcloud's file tree.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getId(): string {
		return 'data-infra-nextcloud-folder';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getLabel(): string {
		return $this->l10n->t('Folder in Nextcloud Files');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getIcon(): string {
		return 'FolderEyeOutline';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * The capability slugs of the data-infra category this adapter implements.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getCapabilities(): array {
		return ['read'];
	}//end getCapabilities()

	/**
	 * {@inheritDoc}
	 *
	 * The files are read live from Nextcloud Files; nothing is stored here.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

	/**
	 * {@inheritDoc}
	 *
	 * Nextcloud Files is always there; each source names its own folder.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function authRequirements(): array {
		return [
			'type'         => 'none',
			'authModes'    => [],
			'configSchema' => [
				'ownerUid' => 'string (the Nextcloud user whose Files hold the folder, required)',
				'rootPath' => 'string (the folder in that user\'s Files; every path stays inside it)',
			],
		];
	}//end authRequirements()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function health(): array {
		return [
			'status'     => 'ok',
			'authStatus' => 'per-source',
			'message'    => $this->l10n->t('Each source names its own owner and folder in Nextcloud Files.'),
		];
	}//end health()

	/**
	 * {@inheritDoc}
	 *
	 * `register`, `schema` and `objectId` are ignored: the adapter works on a
	 * source. `$filters['source']` (the source object, required), `path` and
	 * `pattern` select what to list.
	 *
	 * @param string              $register Ignored.
	 * @param string              $schema   Ignored.
	 * @param string              $objectId Ignored.
	 * @param array<string,mixed> $filters  `source` (required), `path`, `pattern`.
	 *
	 * @return list<array{fileId: int, name: string, path: string, mimetype: string, size: int, mtime: int, owner: string}>
	 *
	 * @throws RemoteFileException When the path leaves the root or the folder is missing.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by IntegrationProvider.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function list(string $register, string $schema, string $objectId, array $filters=[]): array {
		if (is_array($filters['source'] ?? null) === false || $this->handles(source: $filters['source']) === false) {
			return [];
		}

		return $this->listFiles(
			source: $filters['source'],
			path: (string)($filters['path'] ?? ''),
			pattern: (string)($filters['pattern'] ?? '*')
		);
	}//end list()

	/**
	 * Whether a source is a folder in Nextcloud Files.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return bool True for type `nextcloud-folder`.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function handles(array $source): bool {
		return strtolower((string)($source['type'] ?? '')) === self::SOURCE_TYPE;
	}//end handles()

	/**
	 * The files in a folder of the source whose names match a pattern.
	 *
	 * @param array<string,mixed> $source  The source object.
	 * @param string              $path    The folder, relative to the source's root path.
	 * @param string              $pattern A shell pattern for the names, such as `*.pdf`.
	 *
	 * @return list<array{fileId: int, name: string, path: string, mimetype: string, size: int, mtime: int, owner: string}> Files only, sorted by name.
	 *
	 * @throws RemoteFileException When the path leaves the root or the folder is missing.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function listFiles(array $source, string $path='', string $pattern='*'): array {
		$owner    = $this->ownerOf(source: $source);
		$folder   = $this->folderAt(source: $source, path: $path);
		$relative = trim($path, '/');
		if ($pattern === '') {
			$pattern = '*';
		}

		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (($node instanceof File) === false || fnmatch($pattern, $node->getName()) === false) {
				continue;
			}

			$files[] = [
				'fileId'   => (int)$node->getId(),
				'name'     => $node->getName(),
				'path'     => ltrim($relative . '/' . $node->getName(), '/'),
				'mimetype' => $node->getMimetype(),
				'size'     => (int)$node->getSize(),
				'mtime'    => (int)$node->getMTime(),
				'owner'    => $owner,
			];
		}

		usort($files, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		return $files;
	}//end listFiles()

	/**
	 * One file of the source.
	 *
	 * @param array<string,mixed> $source The source object.
	 * @param string              $path   The file, relative to the source's root path.
	 *
	 * @return File The file node, read as the source's owner.
	 *
	 * @throws RemoteFileException When the path leaves the root, or names no file.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function fetch(array $source, string $path): File {
		$absolute = $this->resolve(source: $source, path: $path);
		try {
			$node = $this->userFolder(source: $source)->get(ltrim($absolute, '/'));
		} catch (NotFoundException $e) {
			throw new RemoteFileException(message: 'The file "' . $absolute . '" does not exist in the Files of the source\'s owner.', code: 0, previous: $e);
		}

		if (($node instanceof File) === false) {
			throw new RemoteFileException(message: '"' . $absolute . '" is a folder, not a file.');
		}

		return $node;
	}//end fetch()

	/**
	 * Test the source: its owner exists and its root path is a folder.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return array{ok: bool, message: string} A failed test names the path it could not open.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function test(array $source): array {
		try {
			$folder = $this->folderAt(source: $source, path: '');
		} catch (RemoteFileException $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}

		$files = array_filter($folder->getDirectoryListing(), fn ($node): bool => $node instanceof File);

		return [
			'ok'      => true,
			'message' => $this->l10n->t('The folder %1$s holds %2$s files.', [$this->resolve(source: $source, path: ''), (string)count($files)]),
		];
	}//end test()

	/**
	 * The folder at a path of the source.
	 *
	 * @param array<string,mixed> $source The source object.
	 * @param string              $path   The folder, relative to the source's root path.
	 *
	 * @return Folder
	 *
	 * @throws RemoteFileException When the path leaves the root, or names no folder.
	 */
	private function folderAt(array $source, string $path): Folder {
		$absolute = $this->resolve(source: $source, path: $path);
		$userRoot = $this->userFolder(source: $source);
		if ($absolute === '/') {
			return $userRoot;
		}

		try {
			$node = $userRoot->get(ltrim($absolute, '/'));
		} catch (NotFoundException $e) {
			throw new RemoteFileException(
				message: 'The folder "' . $absolute . '" does not exist in the Files of the source\'s owner.',
				code: 0,
				previous: $e
			);
		}

		if (($node instanceof Folder) === false) {
			throw new RemoteFileException(message: '"' . $absolute . '" is a file, not a folder.');
		}

		return $node;
	}//end folderAt()

	/**
	 * The owner's Files.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return Folder
	 *
	 * @throws RemoteFileException When the source names no owner, or the owner has no Files.
	 */
	private function userFolder(array $source): Folder {
		$owner = $this->ownerOf(source: $source);
		try {
			return $this->rootFolder->getUserFolder($owner);
		} catch (\Throwable $e) {
			throw new RemoteFileException(message: 'The Files of user "' . $owner . '" cannot be opened: ' . $e->getMessage(), code: 0, previous: $e);
		}
	}//end userFolder()

	/**
	 * The path inside the owner's Files, checked against the root.
	 *
	 * @param array<string,mixed> $source The source object.
	 * @param string              $path   The path relative to the root.
	 *
	 * @return string An absolute path inside the owner's Files.
	 *
	 * @throws RemoteFileException When the root or the path contains `..`.
	 */
	private function resolve(array $source, string $path): string {
		return (new RemotePath())->resolve(rootPath: $this->rootOf(source: $source), path: $path);
	}//end resolve()

	/**
	 * The configured owner.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return string
	 *
	 * @throws RemoteFileException When the source names no owner.
	 */
	private function ownerOf(array $source): string {
		$owner = trim((string)($this->configurationOf(source: $source)['ownerUid'] ?? ''));
		if ($owner === '') {
			throw new RemoteFileException(message: 'A folder source needs configuration.ownerUid: the Nextcloud user whose Files hold the folder.');
		}

		return $owner;
	}//end ownerOf()

	/**
	 * The configured root path.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return string
	 */
	private function rootOf(array $source): string {
		return (string)($this->configurationOf(source: $source)['rootPath'] ?? '/');
	}//end rootOf()

	/**
	 * The source's configuration object.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return array<string,mixed>
	 */
	private function configurationOf(array $source): array {
		$configuration = ($source['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;
	}//end configurationOf()
}//end class
