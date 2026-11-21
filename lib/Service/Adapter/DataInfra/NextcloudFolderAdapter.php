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

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The data-infra provider face of a folder in Nextcloud Files (REQ-DIC-001).
 * The file work is NextcloudFolderFiles'; this registers it with OpenRegister's
 * integration registry beside the SFTP and FTPS adapters. No credential is
 * involved, so the broker the category base carries is never called.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class NextcloudFolderAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $credentialBroker OpenRegister's credential broker (unused: no credential).
	 * @param IAppConfig              $appConfig        App config.
	 * @param LoggerInterface         $logger           Logger.
	 * @param NextcloudFolderFiles    $files            The folder's files.
	 *
	 * @return void
	 */
	public function __construct(
		CredentialBrokerService $credentialBroker,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		private readonly NextcloudFolderFiles $files,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
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
		return $this->files->label();
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
	 * {@inheritDoc}
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
			'message'    => $this->files->healthMessage(),
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
		if (is_array($filters['source'] ?? null) === false || $this->files->handles(source: $filters['source']) === false) {
			return [];
		}

		return $this->files->listFiles(
			source: $filters['source'],
			path: (string)($filters['path'] ?? ''),
			pattern: (string)($filters['pattern'] ?? '*')
		);
	}//end list()

	/**
	 * Test a folder source.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return array{ok: bool, message: string} A failed test names the path it could not open.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function test(array $source): array {
		return $this->files->test(source: $source);
	}//end test()
}//end class
