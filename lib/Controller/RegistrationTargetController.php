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
use OCA\Integriq\Service\Registration\RegistrationStoreInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
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
	 * @param IRequest                   $request       The request.
	 * @param RegistrationStoreInterface $store         Reads the targets.
	 * @param RegistrationService        $registrations Runs the dry run.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function __construct(
		IRequest $request,
		private readonly RegistrationStoreInterface $store,
		private readonly RegistrationService $registrations,
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

	/**
	 * POST /api/registration-targets/{slug}/test: the administrator's dry run (design D6).
	 *
	 * Maps the given hand-over, or the sample one, for the target and answers
	 * the mapped body. It stops before the first write: no record, no file
	 * read, no outside call. A refusal is answered with HTTP 200 and its code,
	 * like the command's own answer, so the page can show it.
	 *
	 * @param string              $slug     The target slug.
	 * @param array<string,mixed> $handover The hand-over to map, or nothing for the sample.
	 *
	 * @return JSONResponse The dry-run answer.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function test(string $slug, array $handover = []): JSONResponse {
		return new JSONResponse($this->registrations->dryRun(target: $slug, handover: $handover));
	}//end test()
}//end class
