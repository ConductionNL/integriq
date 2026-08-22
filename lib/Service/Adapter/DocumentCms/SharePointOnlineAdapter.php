<?php

/**
 * SharePoint Online document/CMS adapter.
 *
 * Reference adapter proving REQ-DCC-001 for the document-cms-connectors
 * category.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DocumentCms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DocumentCms;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Reference `document-cms-connectors` adapter: SharePoint Online, via
 * Microsoft Graph's `drive` API.
 *
 * Capability: `document-fetch` — {@see listDocuments()} /
 * {@see fetchDocument()}.
 *
 * Graph endpoints called (via {@see brokeredRequest()}, credential-broker
 * only — no locally-held Graph token):
 *   `GET /v1.0/sites/{siteId}/drive/root/children`
 *   `GET /v1.0/sites/{siteId}/drive/items/{itemId}/content`
 *
 * Persistence NOTE (partial per ADR-022 "no local file store"): fetched
 * document bytes are written into Nextcloud's own Files storage via
 * `IRootFolder` (a real NC storage backend, not a raw local-disk write), not
 * a bespoke integriq table. A dedicated hand-off into docudesk's own
 * attachment ingestion surface was scoped by this change's proposal, but no
 * such public API/route currently exists in the docudesk app (confirmed:
 * no `attachment`-named controller/route in `docudesk/appinfo/routes.php`
 * or `docudesk/lib/Controller/`) — wiring that hand-off is deferred as a
 * follow-up gap rather than invented against a route that doesn't exist.
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
 */
class SharePointOnlineAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * NC Files folder name documents are persisted under.
	 *
	 * @var string
	 */
	// Frozen on the old name: this folder already exists in users' NC Files and
	// holds every document fetched so far. Renaming it creates a second folder and
	// strands the existing one. Moves only with a folder-migration step.
	private const TARGET_FOLDER = 'OpenConnector SharePoint Documents';

	/**
	 * Constructor.
	 *
	 * @param \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker OR's credential broker.
	 * @param \OCP\IAppConfig $appConfig App config.
	 * @param \Psr\Log\LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translator for labels.
	 * @param IRootFolder $rootFolder NC root folder (Files persistence).
	 * @param IUserSession $userSession Current user session.
	 */
	public function __construct(
		\OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker,
		\OCP\IAppConfig $appConfig,
		\Psr\Log\LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getId(): string {
		return 'sharepoint-online';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getLabel(): string {
		return $this->l10n->t('SharePoint Online');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getIcon(): string {
		return 'FileDocumentMultiple';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getCapabilities(): array {
		return ['document-fetch', 'document-list'];
	}//end getCapabilities()

	/**
	 * List the children of a SharePoint site's default document library root.
	 *
	 * @param string $siteId The Graph `site` id (`{hostname},{site-collection-id},{site-id}`).
	 *
	 * @return array<int,array<string,mixed>> Normalised document summaries.
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function listDocuments(string $siteId): array {
		$path = sprintf('/v1.0/sites/%s/drive/root/children', rawurlencode($siteId));

		$response = $this->brokeredRequest(method: 'GET', path: $path);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return [];
		}

		$decoded = json_decode($response['body'], true);
		if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
			return [];
		}

		return array_map(
			static function (array $item): array {
				return [
					'id' => ($item['id'] ?? null),
					'name' => ($item['name'] ?? null),
					'size' => ($item['size'] ?? null),
					'lastModified' => ($item['lastModifiedDateTime'] ?? null),
					'webUrl' => ($item['webUrl'] ?? null),
					'isFolder' => isset($item['folder']),
				];
			},
			$decoded['value']
		);

	}//end listDocuments()

	/**
	 * Fetch a document's content from SharePoint and persist it into the
	 * current user's Nextcloud Files storage.
	 *
	 * @param string $siteId The Graph `site` id.
	 * @param string $itemId The Graph drive-item id.
	 * @param string $name The filename to persist as (from {@see listDocuments()}).
	 *
	 * @return array{path: ?string, size: int}|null Persisted-file descriptor, or null on failure
	 *                                              (unconfigured credential, upstream error, or no active user session).
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function fetchDocument(string $siteId, string $itemId, string $name): ?array {
		$path = sprintf('/v1.0/sites/%s/drive/items/%s/content', rawurlencode($siteId), rawurlencode($itemId));
		$response = $this->brokeredRequest(method: 'GET', path: $path);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			if ($userFolder->nodeExists(self::TARGET_FOLDER) === false) {
				$userFolder->newFolder(self::TARGET_FOLDER);
			}

			$target = self::TARGET_FOLDER . '/' . basename($name);
			if ($userFolder->nodeExists($target) === true) {
				$userFolder->get($target)->delete();
			}

			$file = $userFolder->newFile($target, $response['body']);

			return [
				'path' => $file->getPath(),
				'size' => $file->getSize(),
			];
		} catch (NotPermittedException $e) {
			$this->logger->warning(
				sprintf('%s: could not persist fetched document — %s', $this->getId(), $e->getMessage())
			);
			return null;
		}//end try

	}//end fetchDocument()

	/**
	 * {@inheritDoc}
	 *
	 * `register`/`schema`/`objectId` are ignored — this adapter is
	 * instance-scoped; `$filters['siteId']` selects which SharePoint site
	 * to list.
	 *
	 * @param string $register Ignored (instance-scoped adapter).
	 * @param string $schema Ignored (instance-scoped adapter).
	 * @param string $objectId Ignored (instance-scoped adapter).
	 * @param array<string,mixed> $filters `siteId` (required).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
	 *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if (isset($filters['siteId']) === false) {
			return [];
		}

		return $this->listDocuments(siteId: (string)$filters['siteId']);
	}//end list()
}//end class
