<?php

/**
 * Integriq OtelSettingsController: the administrator's OpenTelemetry export
 * settings (REQ-OTEL-005).
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads and stores the OpenTelemetry export settings.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
 */
class OtelSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param OtelSettings $settings The export settings; its refusals are already translated.
	 */
	public function __construct(
		IRequest $request,
		private readonly OtelSettings $settings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stored settings.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse($this->settings->all());
	}//end getConfig()

	/**
	 * Store the settings; 400 with the reason when a value is refused.
	 *
	 * @return JSONResponse The stored settings, or `{error}`.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		try {
			$stored = $this->settings->save(
				values: [
					'enabled' => $this->request->getParam('enabled', false),
					'endpoint' => $this->request->getParam('endpoint', ''),
					'allowLocal' => $this->request->getParam('allowLocal', false),
					'samplingRatio' => $this->request->getParam('samplingRatio', OtelSettings::DEFAULT_SAMPLING_RATIO),
					'serviceName' => $this->request->getParam('serviceName', ''),
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
