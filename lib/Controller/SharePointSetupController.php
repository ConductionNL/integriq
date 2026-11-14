<?php

/**
 * Integriq SharePoint setup controller.
 *
 * The Graph lookups behind the SharePoint to publication setup dialog: the
 * sites matching a search, a site's libraries and a folder's children, each
 * through the credential broker with the credential the administrator picked.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use InvalidArgumentException;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two routes for integriq administrators only: `GET /api/sharepoint/lookup` reads Graph,
 * `POST /api/sharepoint/publication-intake` saves a setup through the configuration import.
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
class SharePointSetupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request       The request.
	 * @param SharePointOnlineAdapter       $adapter       Makes the brokered Graph calls.
	 * @param SharePointPublicationSet      $set           Fills the packaged set with the setup's choices.
	 * @param ConfigurationServiceInterface $configuration Imports the filled set.
	 * @param LoggerInterface               $logger        Records an import that failed.
	 */
	public function __construct(
		IRequest $request,
		private readonly SharePointOnlineAdapter $adapter,
		private readonly SharePointPublicationSet $set,
		private readonly ConfigurationServiceInterface $configuration,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Look up sites, libraries or folder children in Graph.
	 *
	 * Query parameters: `credential` (the brokered credential's uuid), `kind`
	 * (`sites`, `drives` or `children`) and `search`, `siteId`, `driveId` or
	 * `itemId` as the kind needs. Answers `{items}`; a Graph refusal is a 403
	 * naming the permission to grant; nothing is ever stored.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function lookup(): JSONResponse {
		$params = [];
		foreach (['search', 'siteId', 'driveId', 'itemId'] as $key) {
			$params[$key] = (string)$this->request->getParam($key, '');
		}

		$answer = $this->adapter->lookup(
			credentialId: (string)$this->request->getParam('credential', ''),
			kind: (string)$this->request->getParam('kind', ''),
			params: $params
		);

		$status = $answer['status'];
		unset($answer['status']);

		return new JSONResponse($answer, $status);
	}//end lookup()

	/**
	 * Save a SharePoint to publication setup: one source, one mapping, one rule and one synchronization.
	 *
	 * Body: `credentialId`, `siteId`, `driveId`, `folderId` (empty for the
	 * library root), `folderName`, `catalog`, `register`, `schema` and
	 * `requiresApproval`. The source holds a broker reference and no secret.
	 * Answers 201 with the created ids by component; 400 naming the missing
	 * choice; 500 when the import fails, with nothing reported as created.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function intake(): JSONResponse {
		try {
			$document = $this->set->build(choices: $this->submittedChoices());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => 'invalid_setup', 'message' => $exception->getMessage()], 400);
		}

		try {
			$imported = $this->configuration->importConfiguration(oas: ['components' => $document['components']]);
		} catch (Throwable $exception) {
			$this->logger->error('SharePoint publication intake: the import failed: ' . $exception->getMessage(), ['exception' => $exception]);
			return new JSONResponse(['error' => 'import_failed', 'message' => $exception->getMessage()], 500);
		}

		return new JSONResponse(['created' => $this->createdIds(imported: $imported)], 201);
	}//end intake()

	/**
	 * The setup choices of the request, every key present.
	 *
	 * @return array<string, mixed>
	 */
	private function submittedChoices(): array {
		$choices = [];
		foreach (['credentialId', 'siteId', 'driveId', 'folderId', 'folderName', 'catalog', 'register', 'schema', 'requiresApproval'] as $key) {
			$choices[$key] = $this->request->getParam($key, '');
		}

		return $choices;
	}//end submittedChoices()

	/**
	 * The slug and id of every imported entity, by component.
	 *
	 * @param array<string, mixed> $imported The import's answer, entities by bucket.
	 *
	 * @return array<string, array<int, array{slug: string, id: string|null}>>
	 */
	private function createdIds(array $imported): array {
		$created = [];
		foreach (['sources', 'mappings', 'rules', 'synchronizations'] as $bucket) {
			foreach ((array)($imported[$bucket] ?? []) as $slug => $entity) {
				$created[$bucket][] = ['slug' => (string)$slug, 'id' => $this->idOf(entity: $entity)];
			}
		}

		return $created;
	}//end createdIds()

	/**
	 * The uuid, else the id, of an imported entity.
	 *
	 * @param mixed $entity The imported entity.
	 *
	 * @return string|null
	 */
	private function idOf(mixed $entity): ?string {
		if (is_object($entity) === true && method_exists($entity, 'getUuid') === true && $entity->getUuid() !== null) {
			return (string)$entity->getUuid();
		}

		if (is_object($entity) === true && method_exists($entity, 'getId') === true && $entity->getId() !== null) {
			return (string)$entity->getId();
		}

		return null;
	}//end idOf()
}//end class
