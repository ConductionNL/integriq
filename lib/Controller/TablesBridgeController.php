<?php

/**
 * Integriq Tables Bridge discovery controller.
 *
 * Read-only endpoints backing the synchronization editor's `nextcloud-table`
 * kind: a feature-detection status flag plus table/column discovery, so
 * `sync-editor-ui`'s table picker and column-mapping helper never talk to
 * the Tables API directly (contract.md).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\TablesConfigException;
use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\Integriq\Exception\TablesNotFoundException;
use OCA\Integriq\Exception\TablesPermissionDeniedException;
use OCA\Integriq\Exception\TablesUpstreamException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * `nextcloud-table` feature-status + table/column discovery for the sync editor.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */
class TablesBridgeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The HTTP request.
	 * @param TablesSyncAdapter $tablesSyncAdapter The Tables sync adapter (feature detection + discovery).
	 * @param OrObjectService $orObjectService The OR object service (Source lookups).
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger The logger.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly TablesSyncAdapter $tablesSyncAdapter,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/synchronizations/tables-bridge/status — whether `nextcloud-table`
	 * is available for the acting user (Tables app enabled).
	 *
	 * Backs the sync editor's kind selector (`sync-editor-ui` REQ-SYNCUI-006):
	 * `nextcloud-table` is only offered when this reports `enabled: true`.
	 *
	 * @return JSONResponse `{"enabled": bool}`.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['enabled' => $this->tablesSyncAdapter->isEnabled(user: $user)]);
	}//end status()

	/**
	 * GET /api/synchronizations/tables-bridge/tables — list the tables
	 * accessible to a Source's configured identity.
	 *
	 * @param string|null $sourceId The `Source` id whose credentials list the tables.
	 *
	 * @return JSONResponse `{"results": [...]}` per contract.md, or a mapped error.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tables(?string $sourceId = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'synchronization.tablesBridge.discover');

		if ($sourceId === null || $sourceId === '') {
			return new JSONResponse(['error' => $this->l->t('sourceId is required')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->tablesSyncAdapter->assertEnabled(user: $user);
			$source = $this->findSourceObject(sourceId: $sourceId);
			$tables = $this->tablesSyncAdapter->listTablesForEditor(source: $source);

			return new JSONResponse(['results' => $tables]);
		} catch (\Throwable $exception) {
			return $this->mapException(exception: $exception);
		}

	}//end tables()

	/**
	 * GET /api/synchronizations/tables-bridge/tables/{tableId}/columns — list a
	 * table's columns with type metadata for the mapping helper.
	 *
	 * @param int $tableId The Tables table id.
	 * @param string|null $sourceId The `Source` id whose credentials read the columns.
	 *
	 * @return JSONResponse `{"results": [...]}` per contract.md, or a mapped error.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function columns(int $tableId, ?string $sourceId = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'synchronization.tablesBridge.discover');

		if ($sourceId === null || $sourceId === '') {
			return new JSONResponse(['error' => $this->l->t('sourceId is required')], Http::STATUS_BAD_REQUEST);
		}

		if ($tableId <= 0) {
			return new JSONResponse(['error' => $this->l->t('tableId must be numeric')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->tablesSyncAdapter->assertEnabled(user: $user);
			$source = $this->findSourceObject(sourceId: $sourceId);
			$columns = $this->tablesSyncAdapter->listColumnsForEditor(source: $source, tableId: $tableId);

			return new JSONResponse(['results' => $columns]);
		} catch (\Throwable $exception) {
			return $this->mapException(exception: $exception);
		}

	}//end columns()

	/**
	 * Resolve a Source by id, admin-context (the acting user need not have
	 * direct OR access to the admin-only `source` schema — the engine reads
	 * it on their behalf, mirroring `SynchronizationService::findSourceObject()`).
	 *
	 * @param string $sourceId The OpenRegister id/uuid of the Source.
	 *
	 * @return ObjectEntity
	 *
	 * @throws TablesNotFoundException When no Source matches the id.
	 */
	private function findSourceObject(string $sourceId): ObjectEntity {
		try {
			return $this->orObjectService->find(
				id: $sourceId,
				register: 'openconnector',
				schema: 'source',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			throw new TablesNotFoundException(message: 'Source not found: ' . $sourceId);
		}

	}//end findSourceObject()

	/**
	 * Map a thrown exception to the contract.md error-code table.
	 *
	 * @param \Throwable $exception The exception to map.
	 *
	 * @return JSONResponse
	 */
	private function mapException(\Throwable $exception): JSONResponse {
		if ($exception instanceof TablesFeatureDisabledException) {
			$this->logger->info('TablesBridgeController: Tables app not enabled', ['message' => $exception->getMessage()]);
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}

		if ($exception instanceof TablesNotFoundException) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($exception instanceof TablesPermissionDeniedException) {
			return new JSONResponse(['error' => $exception->getMessage()], $exception->getCode());
		}

		if ($exception instanceof TablesConfigException) {
			return new JSONResponse(['error' => $exception->getMessage()], $exception->getCode());
		}

		if ($exception instanceof TablesUpstreamException) {
			$this->logger->warning('TablesBridgeController: upstream Tables failure', ['message' => $exception->getMessage()]);
			return new JSONResponse(['error' => $this->l->t('Upstream Tables call failed')], Http::STATUS_BAD_GATEWAY);
		}

		$this->logger->error('TablesBridgeController: unexpected error', ['message' => $exception->getMessage()]);
		return new JSONResponse(['error' => $this->l->t('Unexpected error')], Http::STATUS_BAD_GATEWAY);
	}//end mapException()
}//end class
