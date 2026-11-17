<?php

/**
 * Integriq ErrorSinkSettingsController: the administrator's error sink
 * settings (REQ-OTEL-008).
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
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads and stores the error sink settings.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */
class ErrorSinkSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SettingsServiceInterface $settings Stores the `errorsink_*` keys; its refusals are already translated.
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsServiceInterface $settings,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stored settings.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse($this->settings->getErrorSinkSettings());
	}//end getConfig()

	/**
	 * Store the settings; 400 with the reason when a value is refused.
	 *
	 * @return JSONResponse The stored settings, or `{error}`.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		try {
			$stored = $this->settings->saveErrorSinkSettings(
				values: [
					'enabled' => $this->request->getParam('enabled', false),
					'kind' => $this->request->getParam('kind', ErrorSinkSettings::KIND_SENTRY),
					'endpoint' => $this->request->getParam('endpoint', ''),
					'allowLocal' => $this->request->getParam('allowLocal', false),
					'minLevel' => $this->request->getParam('minLevel', ErrorSinkSettings::DEFAULT_MIN_LEVEL),
					'apps' => $this->request->getParam('apps', ErrorSinkSettings::DEFAULT_APPS),
					'headerName' => $this->request->getParam('headerName', ''),
					'credentialName' => $this->request->getParam('credentialName', ''),
				]
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($stored);
	}//end setConfig()
}//end class
