<?php

/**
 * Integriq FeedsController: sync the declared data feeds on request.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\FeedDeclarationService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin only: re-reads every enabled app's feeds.json, or one app's.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class FeedsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request The request.
	 * @param FeedDeclarationService $feeds   Syncs the declarations.
	 */
	public function __construct(
		IRequest $request,
		private readonly FeedDeclarationService $feeds,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Sync the feed declarations.
	 *
	 * @return JSONResponse The counts and, per refused file or dataset, why.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function sync(): JSONResponse {
		$app = $this->request->getParam('app');
		if (is_string($app) === false || trim($app) === '') {
			$app = null;
		}

		return new JSONResponse($this->feeds->sync(app: $app));
	}//end sync()
}//end class
