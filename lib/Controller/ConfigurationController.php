<?php

/**
 * Integriq configuration import/export controller.
 *
 * Thin, routed wrapper over the existing, already-tested
 * {@see ConfigurationServiceInterface} (REQ-001–REQ-005,
 * reused unchanged) plus the non-mutating
 * {@see ConfigurationImportPreviewServiceInterface}
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationServiceInterface;
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
	 * @param ConfigurationServiceInterface $configService The existing export/import service (unchanged).
	 * @param ConfigurationImportPreviewServiceInterface $previewService The non-mutating preview service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ConfigurationServiceInterface $configService,
		private readonly ConfigurationImportPreviewServiceInterface $previewService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
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
		$refusal = $this->refusal(action: 'configuration.export');
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$document = $this->configService->exportConfiguration(configurationId: $id);
		} catch (\Throwable $e) {
			return $this->exportFailed(exception: $e);
		}

		return $this->attachment(document: $document, filename: 'configuration-' . rawurlencode($id) . '.json');
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
		$refusal = $this->refusal(action: 'configuration.export');
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$document = $this->configService->exportRegister(registerId: $id);
		} catch (\Throwable $e) {
			return $this->exportFailed(exception: $e);
		}

		return $this->attachment(document: $document, filename: 'register-' . rawurlencode($id) . '.json');
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
		$refusal = $this->refusal(action: 'configuration.import');
		if ($refusal !== null) {
			return $refusal;
		}

		$preview = $this->previewOf(document: $this->extractDocument());
		if ($preview instanceof JSONResponse) {
			return $preview;
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
		$refusal = $this->importRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$document = $this->extractDocument();

		// Compute the preview BEFORE writing so the summary classifies
		// against the pre-import state (what was created vs updated).
		$preview = $this->previewOf(document: $document);
		if ($preview instanceof JSONResponse) {
			return $preview;
		}

		return $this->importPreviewed(document: $document, preview: $preview);
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
			return $this->decodedDocument(json: $document);
		}

		return $this->uploadedDocument();
	}//end extractDocument()

	/**
	 * The 401 or 403 answer when the session user may not run the action, or null.
	 *
	 * @param string $action The ADR-023 action, `configuration.export` or `configuration.import`.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function refusal(string $action): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()

	/**
	 * The refusal of an import: the action gate, then the explicit confirmation.
	 *
	 * @return JSONResponse|null The 401, 403 or 400 answer, or null.
	 */
	private function importRefusal(): ?JSONResponse {
		$refusal = $this->refusal(action: 'configuration.import');
		if ($refusal !== null) {
			return $refusal;
		}

		$confirmed = $this->request->getParam('confirmed');
		if ($confirmed !== true && $confirmed !== 'true') {
			return new JSONResponse(
				['error' => $this->l->t('Import requires explicit confirmation — preview first, then send confirmed: true')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end importRefusal()

	/**
	 * An export served as a JSON download.
	 *
	 * @param array<string,mixed> $document The exported document.
	 * @param string              $filename The download's file name.
	 *
	 * @return JSONResponse
	 */
	private function attachment(array $document, string $filename): JSONResponse {
		$response = new JSONResponse($document);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
		return $response;
	}//end attachment()

	/**
	 * The 500 answer for a failed export.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function exportFailed(\Throwable $exception): JSONResponse {
		return new JSONResponse(['error' => $this->l->t('Export failed: %s', [$exception->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end exportFailed()

	/**
	 * The preview of a document, or the 400/500 answer when there is none or it fails.
	 *
	 * @param array<string,mixed>|null $document The decoded document, or null.
	 *
	 * @return array<string,mixed>|JSONResponse The preview classification, or the error answer.
	 */
	private function previewOf(?array $document): array|JSONResponse {
		if ($document === null) {
			return new JSONResponse(
				['error' => $this->l->t('Request must carry a JSON configuration document under "document"')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return $this->previewService->preview(oas: $document);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $this->l->t('Preview failed: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end previewOf()

	/**
	 * Import a previewed document and answer the preview with what was written per type.
	 *
	 * @param array<string,mixed> $document The decoded document.
	 * @param array<string,mixed> $preview  Its preview, computed before the write.
	 *
	 * @return JSONResponse The post-import summary, or the 400/500 answer.
	 */
	private function importPreviewed(array $document, array $preview): JSONResponse {
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
	}//end importPreviewed()

	/**
	 * A JSON document decoded from a string, or null when it is not a JSON object or array.
	 *
	 * @param string $json The JSON text.
	 *
	 * @return array<string,mixed>|null
	 */
	private function decodedDocument(string $json): ?array {
		$decoded = json_decode($json, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decodedDocument()

	/**
	 * The document in the uploaded file named `file`, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function uploadedDocument(): ?array {
		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) === false || isset($upload['tmp_name']) === false || is_string($upload['tmp_name']) === false) {
			return null;
		}

		$raw = file_get_contents($upload['tmp_name']);
		if ($raw === false) {
			return null;
		}

		return $this->decodedDocument(json: $raw);
	}//end uploadedDocument()
}//end class
