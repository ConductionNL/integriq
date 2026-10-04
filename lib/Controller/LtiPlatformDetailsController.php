<?php

/**
 * Integriq LtiPlatformDetailsController: the platform values for one tool registration.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Lti\LtiPlatformDetailsService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/lti/tools/{id}/platform-details, for administrators only.
 *
 * Its own controller rather than a method on LtiController, whose
 * constructor is already at its parameter ceiling.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */
class LtiPlatformDetailsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request The request.
	 * @param LtiPlatformDetailsService $details Builds the six values.
	 */
	public function __construct(
		IRequest $request,
		private readonly LtiPlatformDetailsService $details,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The issuer, client id, deployment ids, authorization URL, token URL and
	 * key set URL a tool's administrator enters on the vendor's side.
	 *
	 * @param string $id The `lti_tool` registration uuid.
	 *
	 * @return JSONResponse The six values, or 404 when the tool does not exist.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function show(string $id): JSONResponse {
		$details = $this->details->forTool(toolUuid: $id);
		if ($details === null) {
			return new JSONResponse(data: ['error' => 'Tool not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $details);

	}//end show()
}//end class
