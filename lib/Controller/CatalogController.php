<?php

/**
 * OpenConnector catalog controller.
 *
 * Thin controller over CatalogRegistryService exposing the two bespoke,
 * non-CRUD catalog endpoints — everything else about the Catalog page
 * (listing, search, category filter) goes through OpenRegister's generic
 * `/api/objects/openconnector/catalog_item` endpoint per ADR-022, so no
 * `index`/`show` methods are added here.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\CatalogRegistryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the Catalog page's status re-check and Enable/Instantiate
 * action endpoints (REQ-002).
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CatalogController extends Controller {
	/**
	 * Constructor for the CatalogController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param CatalogRegistryService $registryService The catalog registry service.
	 * @param OrObjectService $orObjectService The OR object service.
	 * @param IAppConfig $appConfig App config, used to flip flag-gated items.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly CatalogRegistryService $registryService,
		private readonly OrObjectService $orObjectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Live re-check a single catalog item's gating mechanism.
	 *
	 * Any authenticated user may call this — read is not gated by the
	 * `catalog.instantiate` action, only the write below is (contract.md).
	 *
	 * @param string $id The catalog_item object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$item = $this->findCatalogItem(id: $id);
		if ($item === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$data = $item->getObject();
		$entry = $this->entryFromStoredData(data: $data);
		$status = $this->registryService->resolveStatus(entry: $entry);

		return new JSONResponse(
			[
				'id' => $id,
				'status' => $status,
				'mechanism' => (string)($data['mechanism'] ?? ''),
				'flagKey' => (string)($data['flagKey'] ?? ''),
			]
		);

	}//end status()

	/**
	 * Enable a flag-gated item, or instantiate/enable a mock-seeded /
	 * always-available item's underlying Source.
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `catalog.instantiate` action gate
	 * below (hydra no-admin-idor gate: an authorization guard MUST live in
	 * the method body when the route relaxes admin-only routing). The
	 * underlying Source write still passes through OpenRegister's
	 * admin-only `source` schema authorization (99-source-lockdown.json) —
	 * a non-admin operator granted `catalog.instantiate` via the action
	 * matrix is still rejected at the data layer (REQ-002 scenario 4).
	 *
	 * @param string $id The catalog_item object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-enable-action-flips-a-feature-flag-for-a-flag-gated-item
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function instantiate(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'catalog.instantiate');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$item = $this->findCatalogItem(id: $id);
		if ($item === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$data = $item->getObject();
		$mechanism = (string)($data['mechanism'] ?? 'always-available');

		try {
			if ($mechanism === 'flag-gated') {
				return $this->instantiateFlagGated(data: $data);
			}

			return $this->instantiateSourceBacked(data: $data);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => $this->l->t('Failed to instantiate catalog item: %s', [$e->getMessage()])],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}//end instantiate()

	/**
	 * Flip a flag-gated item's app-config flag on.
	 *
	 * @param array<string,mixed> $data The catalog_item's stored data.
	 *
	 * @return JSONResponse
	 */
	private function instantiateFlagGated(array $data): JSONResponse {
		$flagKey = (string)($data['flagKey'] ?? '');
		if ($flagKey === '') {
			return new JSONResponse(['error' => $this->l->t('This catalog item has no flag to enable')], Http::STATUS_CONFLICT);
		}

		$raw = $this->appConfig->getValueString('openconnector', $flagKey, '0');
		if ($raw === '1' || strtolower($raw) === 'true') {
			return new JSONResponse(['error' => $this->l->t('Already enabled')], Http::STATUS_CONFLICT);
		}

		$this->appConfig->setValueString('openconnector', $flagKey, '1');

		return new JSONResponse(
			[
				'created' => true,
				'type' => 'flag',
				'id' => $flagKey,
				'action' => 'enabled',
			],
			Http::STATUS_CREATED
		);

	}//end instantiateFlagGated()

	/**
	 * Instantiate (create) or enable the Source backing a mock-seeded /
	 * always-available catalog item.
	 *
	 * @param array<string,mixed> $data The catalog_item's stored data.
	 *
	 * @return JSONResponse
	 *
	 * @throws DoesNotExistException Propagated from OpenRegister when the
	 *                               source this enables is deleted between the
	 *                               lookup and the write. Deliberately NOT
	 *                               translated here: the caller, `instantiate()`,
	 *                               owns the response envelope for every branch
	 *                               of this method and already catches it.
	 */
	private function instantiateSourceBacked(array $data): JSONResponse {
		$slug = (string)($data['sourceTemplateSlug'] ?? '');
		if ($slug === '') {
			return new JSONResponse(['error' => $this->l->t('This catalog item has nothing to instantiate')], Http::STATUS_CONFLICT);
		}

		$existing = $this->findSourceBySlug(slug: $slug);
		if ($existing !== null) {
			$existingData = $existing->getObject();
			if (($existingData['isEnabled'] ?? false) === true) {
				return new JSONResponse(['error' => $this->l->t('Already instantiated and enabled')], Http::STATUS_CONFLICT);
			}

			$existingData['isEnabled'] = true;
			$saved = $this->orObjectService->saveObject(
				object: $existingData,
				register: 'openconnector',
				schema: 'source',
				uuid: $existing->getUuid()
			);

			return new JSONResponse(
				[
					'created' => false,
					'type' => 'source',
					'id' => $saved->getUuid(),
					'action' => 'enabled',
				],
				Http::STATUS_CREATED
			);
		}//end if

		$seedPayload = $this->registryService->findSeedSourcePayload(slug: $slug);
		if ($seedPayload === null) {
			return new JSONResponse(['error' => $this->l->t('No seed template found for this catalog item')], Http::STATUS_NOT_FOUND);
		}

		$seedPayload['isEnabled'] = true;
		$created = $this->orObjectService->saveObject(
			object: $seedPayload,
			register: 'openconnector',
			schema: 'source'
		);

		return new JSONResponse(
			[
				'created' => true,
				'type' => 'source',
				'id' => $created->getUuid(),
				'action' => 'enabled',
			],
			Http::STATUS_CREATED
		);

	}//end instantiateSourceBacked()

	/**
	 * Find a catalog_item OR object by id, returning null instead of
	 * throwing so callers can return a clean 404.
	 *
	 * @param string $id The catalog_item object id.
	 *
	 * @return ObjectEntity|null
	 */
	private function findCatalogItem(string $id): ?ObjectEntity {
		try {
			return $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'catalog_item');
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findCatalogItem()

	/**
	 * Find a `source` OR object by slug.
	 *
	 * @param string $slug The source's slug.
	 *
	 * @return ObjectEntity|null
	 */
	private function findSourceBySlug(string $slug): ?ObjectEntity {
		$result = $this->orObjectService->findAll(
			config: ['filters' => ['register' => 'openconnector', 'schema' => 'source', 'slug' => $slug]]
		);
		$items = ($result['results'] ?? $result);
		foreach ($items as $item) {
			if ($item instanceof ObjectEntity === false) {
				continue;
			}

			$itemData = $item->getObject();
			if (($itemData['slug'] ?? '') === $slug) {
				return $item;
			}
		}

		return null;
	}//end findSourceBySlug()

	/**
	 * Build a resolveStatus()-compatible entry array from a stored
	 * catalog_item object's data.
	 *
	 * @param array<string,mixed> $data The catalog_item's stored data.
	 *
	 * @return array<string,mixed>
	 */
	private function entryFromStoredData(array $data): array {
		return [
			'mechanism' => ($data['mechanism'] ?? 'always-available'),
			'flagKey' => ($data['flagKey'] ?? ''),
			'sourceTemplateSlug' => ($data['sourceTemplateSlug'] ?? ''),
		];

	}//end entryFromStoredData()
}//end class
