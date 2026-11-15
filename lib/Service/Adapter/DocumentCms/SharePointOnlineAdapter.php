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
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DocumentCms;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCP\Files\Folder;
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
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
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
	 * The Graph permission each lookup needs, named back when Graph refuses it.
	 *
	 * Searching sites and listing a site's libraries need `Sites.Read.All` (or a
	 * site-scoped grant); reading a library's folders needs `Files.Read.All`.
	 *
	 * @var array<string, string>
	 */
	public const LOOKUP_PERMISSIONS = [
		'sites' => 'Sites.Read.All',
		'drives' => 'Sites.Read.All',
		'children' => 'Files.Read.All',
	];

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
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getId(): string {
		return 'sharepoint-online';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getLabel(): string {
		return $this->l10n->t('SharePoint Online');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getIcon(): string {
		return 'FileDocumentMultiple';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
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
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
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
	 * Look a SharePoint site, library or folder up in Graph, with a credential the administrator picked.
	 *
	 * Three kinds, one per step of the setup dialog: `sites` (a search over
	 * site names, `search` required), `drives` (the document libraries of
	 * `siteId`) and `children` (the items in `driveId`, at its root or in the
	 * folder `itemId`). Ids are URL-encoded into the Graph path, so a value
	 * can never reach another Graph resource.
	 *
	 * When Graph refuses (401 or 403) the answer is a 403 that names the Graph
	 * permission the step needs and repeats Graph's own code and message, so
	 * the dialog can tell the administrator what to grant.
	 *
	 * @param string               $credentialId The brokered credential's uuid.
	 * @param string               $kind         `sites`, `drives` or `children`.
	 * @param array<string, mixed> $params       `search`, `siteId`, `driveId` and `itemId`, as the kind needs.
	 *
	 * @return array{status: int, items: list<array<string, mixed>>, error?: string, permission?: string, graphCode?: string, graphMessage?: string}
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	public function lookup(string $credentialId, string $kind, array $params): array {
		$path = $this->lookupPath(kind: $kind, params: $params);
		if ($path === null) {
			return ['status' => 400, 'items' => [], 'error' => 'invalid_lookup'];
		}

		if ($credentialId === '') {
			return ['status' => 400, 'items' => [], 'error' => 'credential_required'];
		}

		$response = $this->brokeredRequest(method: 'GET', path: $path, credentialId: $credentialId);
		if ($response === null) {
			return ['status' => 502, 'items' => [], 'error' => 'credential_unusable'];
		}

		$decoded = json_decode($response['body'], true);
		if ($response['status'] === 401 || $response['status'] === 403) {
			return [
				'status' => 403,
				'items' => [],
				'error' => 'graph_refused',
				'permission' => self::LOOKUP_PERMISSIONS[$kind],
				'graphCode' => (string)($decoded['error']['code'] ?? ''),
				'graphMessage' => (string)($decoded['error']['message'] ?? ''),
			];
		}

		if ($response['status'] < 200 || $response['status'] >= 300 || is_array($decoded['value'] ?? null) === false) {
			return ['status' => 502, 'items' => [], 'error' => 'graph_error'];
		}

		return ['status' => 200, 'items' => array_values(array_map(self::lookupItem(...), array_filter($decoded['value'], 'is_array')))];
	}//end lookup()

	/**
	 * The Graph path of a lookup, or null when the kind is unknown or a value it needs is missing.
	 *
	 * @param string               $kind   The lookup kind.
	 * @param array<string, mixed> $params The lookup parameters.
	 *
	 * @return string|null
	 */
	private function lookupPath(string $kind, array $params): ?string {
		$search = trim((string)($params['search'] ?? ''));
		$siteId = trim((string)($params['siteId'] ?? ''));
		$driveId = trim((string)($params['driveId'] ?? ''));
		$itemId = trim((string)($params['itemId'] ?? ''));

		if ($kind === 'sites') {
			if ($search === '') {
				return null;
			}

			return '/v1.0/sites?search=' . rawurlencode($search);
		}

		if ($kind === 'drives') {
			if ($siteId === '') {
				return null;
			}

			return sprintf('/v1.0/sites/%s/drives', rawurlencode($siteId));
		}

		if ($kind !== 'children' || $driveId === '') {
			return null;
		}

		if ($itemId === '') {
			return sprintf('/v1.0/drives/%s/root/children', rawurlencode($driveId));
		}

		return sprintf('/v1.0/drives/%s/items/%s/children', rawurlencode($driveId), rawurlencode($itemId));
	}//end lookupPath()

	/**
	 * One lookup result: its id, its name and web address, and whether it is a folder.
	 *
	 * @param array<string, mixed> $item A Graph site, drive or drive item.
	 *
	 * @return array{id: mixed, name: mixed, webUrl: mixed, isFolder: bool}
	 */
	private static function lookupItem(array $item): array {
		return [
			'id' => ($item['id'] ?? null),
			'name' => ($item['displayName'] ?? $item['name'] ?? null),
			'webUrl' => ($item['webUrl'] ?? null),
			'isFolder' => isset($item['folder']),
		];
	}//end lookupItem()

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
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
	 */
	public function fetchDocument(string $siteId, string $itemId, string $name): ?array {
		$body = $this->downloadedBody(siteId: $siteId, itemId: $itemId);
		if ($body === null) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$file       = $userFolder->newFile($this->freshTarget(userFolder: $userFolder, name: $name), $body);

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
	 * The content of a drive item, or null when Graph does not hand it over.
	 *
	 * @param string $siteId The site id.
	 * @param string $itemId The drive item id.
	 *
	 * @return string|null The bytes.
	 */
	private function downloadedBody(string $siteId, string $itemId): ?string {
		$path = sprintf('/v1.0/sites/%s/drive/items/%s/content', rawurlencode($siteId), rawurlencode($itemId));
		$response = $this->brokeredRequest(method: 'GET', path: $path);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return null;
		}

		return $response['body'];

	}//end downloadedBody()

	/**
	 * The path the document lands at in the target folder, with the folder made and an older copy removed.
	 *
	 * @param Folder $userFolder The user's Files folder.
	 * @param string $name       The document name; only its base name is used.
	 *
	 * @return string The path, relative to the user folder.
	 *
	 * @throws NotPermittedException When the folder or the older copy cannot be changed.
	 */
	private function freshTarget(Folder $userFolder, string $name): string {
		if ($userFolder->nodeExists(self::TARGET_FOLDER) === false) {
			$userFolder->newFolder(self::TARGET_FOLDER);
		}

		$target = self::TARGET_FOLDER . '/' . basename($name);
		if ($userFolder->nodeExists($target) === true) {
			$userFolder->get($target)->delete();
		}

		return $target;

	}//end freshTarget()

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
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
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
