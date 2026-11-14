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
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * One read-only route: `GET /api/sharepoint/lookup`, for integriq administrators only.
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
class SharePointSetupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request The request.
	 * @param SharePointOnlineAdapter $adapter Makes the brokered Graph calls.
	 */
	public function __construct(
		IRequest $request,
		private readonly SharePointOnlineAdapter $adapter,
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
}//end class
