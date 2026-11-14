<?php

/**
 * Shared base of the SFTP and FTPS adapters.
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
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSessionFactory;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * A partner's file server in the data infrastructure category (REQ-DIC-001).
 * Unlike the S3 adapter it is not tied to one app-wide credential: every SFTP
 * or FTPS source carries its own host, root path, host key pin and
 * `credentialRef`, and the operations take that source. The provider entry
 * says whether the protocol can be spoken on this instance at all.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
abstract class RemoteFilesAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService  $credentialBroker OpenRegister's credential broker.
	 * @param IAppConfig               $appConfig        App config.
	 * @param LoggerInterface          $logger           Logger.
	 * @param IL10N                    $l10n             Translator for labels.
	 * @param RemoteFileClient         $client           The guarded file operations.
	 * @param RemoteFileSessionFactory $sessionFactory   Says whether the protocol is available.
	 *
	 * @return void
	 */
	public function __construct(
		CredentialBrokerService $credentialBroker,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		protected readonly IL10N $l10n,
		protected readonly RemoteFileClient $client,
		protected readonly RemoteFileSessionFactory $sessionFactory,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
	}//end __construct()

	/**
	 * The protocol this adapter speaks.
	 *
	 * @return string `sftp` or `ftps`.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	abstract public function getProtocol(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getIcon(): string {
		return 'FolderNetworkOutline';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function getCapabilities(): array {
		return ['read', 'write', 'bulk-import'];
	}//end getCapabilities()

	/**
	 * {@inheritDoc}
	 *
	 * Enabled when the protocol can be spoken here; each source brings its own credential.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function isEnabled(): bool {
		return $this->sessionFactory->isAvailable(protocol: $this->getProtocol());
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function health(): array {
		if ($this->isEnabled() === false) {
			return [
				'status'     => 'unavailable',
				'authStatus' => 'missing',
				'message'    => $this->unavailableMessage(),
			];
		}

		return [
			'status'     => 'ok',
			'authStatus' => 'per-source',
			'message'    => $this->l10n->t('Each source carries its own credential, root path and server check.'),
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
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by IntegrationProvider.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function list(string $register, string $schema, string $objectId, array $filters=[]): array {
		if (is_array($filters['source'] ?? null) === false) {
			return [];
		}

		$source = $filters['source'];
		if (strtolower((string)($source['type'] ?? '')) !== $this->getProtocol()) {
			return [];
		}

		$files = $this->client->listFiles(
			source: $source,
			path: (string)($filters['path'] ?? ''),
			pattern: (string)($filters['pattern'] ?? '*')
		);

		return array_map(fn ($file): array => $file->toArray(), $files);
	}//end list()

	/**
	 * Why the protocol is not available here.
	 *
	 * @return string
	 */
	abstract protected function unavailableMessage(): string;
}//end class
