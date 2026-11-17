<?php

/**
 * EndpointWorkspaceController: the sessions or devices a workspace-manager source reports.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Adapter\EndpointWorkspace\EndpointWorkspaceListingInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists what the workspace manager behind a source reports, each entry with
 * the Nextcloud user it maps to or none.
 *
 * The source names its adapter in `configuration.workspaceAdapter`
 * (`intune`, `jamf-pro`, `citrix-daas` or `azure-virtual-desktop`); the rest
 * of `configuration` holds the vendor settings and an optional `userMapping`.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class EndpointWorkspaceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                            $appName The app name.
	 * @param IRequest                          $request The request.
	 * @param EndpointWorkspaceListingInterface $listing What an administrator sees of a source's workspace manager.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly EndpointWorkspaceListingInterface $listing,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The entries the source's workspace manager reports.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(string $id): JSONResponse {
		[$status, $data] = $this->listing->forSource(id: $id);

		return new JSONResponse($data, $status);
	}//end index()
}//end class
