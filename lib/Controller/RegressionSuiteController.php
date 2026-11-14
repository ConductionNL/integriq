<?php

/**
 * Integriq RegressionSuiteController.
 *
 * Runs the test cases of a mapping or a synchronization. Running a suite is
 * the action `regression-suite.run` in the action matrix (admin by
 * default); a user outside it gets 403 and no run record is written.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-running-and-recording-suites-are-named-actions-req-irt-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\RegressionSuiteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Suite runs, behind the `regression-suite.run` action.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-running-and-recording-suites-are-named-actions-req-irt-004
 */
class RegressionSuiteController extends Controller {

	/**
	 * The action that allows running a suite.
	 *
	 * @var string
	 */
	public const RUN_ACTION = 'regression-suite.run';

	/**
	 * Constructor.
	 *
	 * @param string                     $appName     The app id.
	 * @param IRequest                   $request     The request.
	 * @param RegressionSuiteService     $suites      Runs the suites.
	 * @param ActionAuthServiceInterface $actionAuth  The action matrix.
	 * @param IUserSession               $userSession The signed-in user.
	 * @param IL10N                      $l10n        Translates the refusals.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RegressionSuiteService $suites,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Run every test case of a mapping or synchronization and return the run record.
	 *
	 * @param string $subjectType `mapping` or `synchronization`.
	 * @param string $subjectId   The subject's uuid or slug.
	 *
	 * @return JSONResponse The run record; 400 for an unknown subject type; 401 or 403 when not allowed.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-running-and-recording-suites-are-named-actions-req-irt-004
	 */
	#[NoAdminRequired]
	public function run(string $subjectType, string $subjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->actionAuth->can(user: $user, action: self::RUN_ACTION) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not allowed to run test cases.')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$run = $this->suites->run(subjectType: $subjectType, subjectId: $subjectId, userId: $user->getUID());
		} catch (InvalidArgumentException) {
			return new JSONResponse(
				['error' => $this->l10n->t('Test cases run for a mapping or a synchronization only.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($run);
	}//end run()
}//end class
