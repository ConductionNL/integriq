<?php

/**
 * Integriq RegistrationTargetController.
 *
 * Lists the registration targets an app may use, by slug, title and kind.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCA\Integriq\Service\Registration\RegistrationStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/registration-targets?app={app}: the picker list for portal administrators.
 *
 * Open to every signed-in user, so it answers slug, title and kind only:
 * never the sources, the mapping or the settings that say how a target is
 * reached. The targets themselves stay admin-only in the register.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 */
class RegistrationTargetController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param RegistrationStore $store   Reads the targets.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function __construct(
		IRequest $request,
		private readonly RegistrationStore $store,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The enabled targets the app may use.
	 *
	 * @param string $app The app that will hand submissions over.
	 *
	 * @return JSONResponse {results: [{slug, title, kind}]}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegistrationService::allowedApps is the one pure rule for who may use a target.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	#[NoAdminRequired]
	public function index(string $app = ''): JSONResponse {
		$results = [];
		if ($app !== '') {
			foreach ($this->store->findTargets() as $target) {
				$allowed = in_array($app, RegistrationService::allowedApps(target: $target), true);
				if (($target['enabled'] ?? true) === false || $allowed === false) {
					continue;
				}

				$results[] = [
					'slug' => (string)($target['slug'] ?? ''),
					'title' => (string)($target['title'] ?? ''),
					'kind' => (string)($target['kind'] ?? ''),
				];
			}
		}

		return new JSONResponse(['results' => $results]);
	}//end index()
}//end class
