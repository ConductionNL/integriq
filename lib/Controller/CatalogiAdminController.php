<?php

/**
 * Administrator actions on the Catalogi facade: the Woo starter set.
 *
 * `POST /api/catalogi/woo-starter-set` creates one document type per Woo
 * information category that has none yet. Administrator only, refused by
 * `#[AuthorizedAdminSetting]` and again in the method body.
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
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Catalogi\WooStarterSet;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Runs the Woo starter set for an administrator.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */
class CatalogiAdminController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request      The request.
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager Whether the caller is an administrator.
	 * @param WooStarterSet $starterSet   The starter set.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly WooStarterSet $starterSet,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/catalogi/woo-starter-set`.
	 *
	 * @return JSONResponse `created` and `skipped`, or the refusal.
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function wooStarterSet(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return new JSONResponse(['error' => 'Only an administrator may run the Woo starter set.'], Http::STATUS_FORBIDDEN);
		}

		$answer = $this->starterSet->run();

		return new JSONResponse($answer['body'], $answer['status']);
	}//end wooStarterSet()

}//end class
