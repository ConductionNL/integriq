<?php

/**
 * Integriq DsoActivityMappingController: the unmapped DSO activities on the
 * admin settings section.
 *
 * The rows themselves are read and written through OpenRegister's object
 * API, where the schema's authorization keeps them admin-only (ADR-022).
 * This controller only serves what that API cannot: the unmapped activities
 * grouped by identifier.
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
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-in-the-app-req-dso-012
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the unmapped DSO activities, admin only.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-in-the-app-req-dso-012
 */
class DsoActivityMappingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request   The request.
	 * @param DsoUnmappedActivities $unmapped  Groups the unmapped activities.
	 */
	public function __construct(
		IRequest $request,
		private readonly DsoUnmappedActivities $unmapped,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The activities seen on verzoeken that no active row maps.
	 *
	 * @return JSONResponse `{activities, withoutIdentifier, scanned, limit}`.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.2
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function unmapped(): JSONResponse {
		return new JSONResponse($this->unmapped->list());
	}//end unmapped()
}//end class
