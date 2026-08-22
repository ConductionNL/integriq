<?php

/**
 * Integriq configuration import/export controller.
 *
 * Thin, routed wrapper over the existing, already-tested
 * {@see \OCA\Integriq\Service\ConfigurationService} (REQ-001–REQ-005,
 * reused unchanged) plus the non-mutating
 * {@see \OCA\Integriq\Service\ConfigurationImportPreviewService}
 * (REQ-007/REQ-009). Before connector-catalog-ui the service was fully
 * implemented but unrouted — reachable only from PHPUnit.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ConfigurationImportPreviewService;
use OCA\Integriq\Service\ConfigurationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing configuration export (REQ-006), import preview
 * (REQ-007) and confirmed import (REQ-008/REQ-009).
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class ConfigurationController extends Controller {
	/**
	 * Constructor for the ConfigurationController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param ConfigurationService $configService The existing export/import service (unchanged).
	 * @param ConfigurationImportPreviewService $previewService The non-mutating preview service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ConfigurationService $configService,
		private readonly ConfigurationImportPreviewService $previewService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export a configuration group as a redacted OAS JSON download (REQ-006).
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `configuration.export` action gate
	 * in the body (hydra no-admin-idor gate). Redaction and slug
	 * translation are the existing service's, unchanged — including the
	 * documented substring-match redaction gap (REQ-005 Notes).
	 *
	 * @param string $id The configuration group id.
	 *
	 * @return JSONResponse The redacted OAS document, served as an attachment.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-exporting-a-configuration-from-the-ui-produces-a-redacted-downloadable-file
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'configuration.export');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$document = $this->configService->exportConfiguration(configurationId: $id);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Export failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new JSONResponse($document);
		$response->addHeader('Content-Disposition', 'attachment; filename="configuration-' . rawurlencode($id) . '.json"');
		return $response;
	}//end export()

	/**
	 * Export every connector entity linked to a register as an OAS JSON download.
	 *
	 * Routed trigger for the previously-unwired
	 * {@see ConfigurationService::exportRegister()} — exports the endpoints,
	 * synchronisations, and their related sources, mappings, rules and jobs
	 * that target the given register. `#[NoAdminRequired]` + the ADR-023
	 * `configuration.export` action gate in the body (hydra no-admin-idor gate),
	 * reusing the same authorisation as the configuration export path.
	 *
	 * @param string $id The register id (or slug) to export connectors for.
	 *
	 * @return JSONResponse The register connector bundle, served as an attachment.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/revive-dead-capabilities/tasks.md#task-3
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportRegister(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'configuration.export');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$document = $this->configService->exportRegister(registerId: $id);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Export failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new JSONResponse($document);
		$response->addHeader('Content-Disposition', 'attachment; filename="register-' . rawurlencode($id) . '.json"');
		return $response;
	}//end exportRegister()

	/**
	 * Non-mutating import preview (REQ-007): classify creates/updates/
	 * collisions, surface unresolved slug references and credential
	 * re-entry flags — nothing is written.
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `configuration.import` action gate
	 * in the body (preview shares the import gate — it reveals what the
	 * target environment contains, contract.md).
	 *
	 * @return JSONResponse The preview classification.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function previewImport(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'configuration.import');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$document = $this->extractDocument();
		if ($document === null) {
			return new JSONResponse(
				['error' => $this->l->t('Request must carry a JSON configuration document under "document"')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$preview = $this->previewService->preview(oas: $document);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Preview failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($preview);
	}//end previewImport()

	/**
	 * Confirmed import (REQ-008): requires `confirmed: true` (rejected with
	 * 400 otherwise), then delegates unchanged to the existing
	 * ConfigurationService::importConfiguration() and returns the preview
	 * shape reflecting what was actually written (REQ-009 flags included).
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `configuration.import` action gate
	 * in the body; the per-entity writes inside importConfiguration() still
	 * pass through each schema's own OpenRegister data-layer authorization
	 * (e.g. Source writes admin-only per 99-source-lockdown.json).
	 *
	 * @return JSONResponse The post-import summary.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-import-without-confirmation-is-rejected
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-confirmed-import-proceeds-and-reuses-the-existing-import-pipeline-unchanged
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'configuration.import');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$confirmed = $this->request->getParam('confirmed');
		if ($confirmed !== true && $confirmed !== 'true') {
			return new JSONResponse(
				['error' => $this->l->t('Import requires explicit confirmation — preview first, then send confirmed: true')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$document = $this->extractDocument();
		if ($document === null) {
			return new JSONResponse(
				['error' => $this->l->t('Request must carry a JSON configuration document under "document"')],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Compute the preview BEFORE writing so the summary classifies
		// against the pre-import state (what was created vs updated).
		try {
			$preview = $this->previewService->preview(oas: $document);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Preview failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$imported = $this->configService->importConfiguration(oas: $document);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Import failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Summarise what importConfiguration() actually returned per type.
		$written = [];
		foreach ($imported as $type => $entities) {
			$written[$type] = array_keys((array)$entities);
		}

		return new JSONResponse(array_merge($preview, ['written' => $written]));
	}//end import()

	/**
	 * Extract the OAS document from the request: either a `document` param
	 * (JSON body) or an uploaded file named `file` (multipart).
	 *
	 * @return array<string,mixed>|null The decoded document, or null when absent/undecodable.
	 */
	private function extractDocument(): ?array {
		$document = $this->request->getParam('document');
		if (is_array($document) === true) {
			return $document;
		}

		if (is_string($document) === true && $document !== '') {
			$decoded = json_decode($document, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) === true && isset($upload['tmp_name']) === true && is_string($upload['tmp_name']) === true) {
			$raw = file_get_contents($upload['tmp_name']);
			if ($raw !== false) {
				$decoded = json_decode($raw, true);
				if (is_array($decoded) === true) {
					return $decoded;
				}
			}
		}

		return null;
	}//end extractDocument()
}//end class
