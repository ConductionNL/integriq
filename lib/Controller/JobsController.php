<?php

/**
 * Integriq jobs controller.
 *
 * Controller for job listing, log retrieval, and ad-hoc job run/test endpoints.
 * Delegates execution to JobService and applies search-style log filtering.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Controller;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\JobCalendarSchedule;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\Integriq\Service\SearchServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for job listing, log retrieval, and ad-hoc job run/test endpoints.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class JobsController extends Controller {
	use LogPageListing;

	/**
	 * Constructor for the JobController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param OrObjectService $orObjectService The OR object service.
	 * @param JobServiceInterface $jobService The job service (used by run/test action methods).
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param JobCalendarSchedule|null $calendar The calendar calculator the schedule preview uses.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private OrObjectService $orObjectService,
		private JobServiceInterface $jobService,
		private IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly ?JobCalendarSchedule $calendar = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Retrieves job logs with filtering and pagination support.
	 *
	 * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
	 *
	 * @param SearchServiceInterface $searchService Service used to strip special query parameters.
	 *
	 * @return JSONResponse A JSON response containing the filtered job logs and pagination.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function logs(SearchServiceInterface $searchService): JSONResponse {
		return $this->logPage(searchService: $searchService, schema: 'job_log');
	}//end logs()

	/**
	 * The next five run times of a calendar, for the job form's preview.
	 *
	 * Computed by the same calculator the run loop uses, so what the form shows
	 * is what the job does. Requires the action `job.schedule-preview`, default
	 * admin, so a preview does not open a read of the job list to everyone.
	 *
	 * @return JSONResponse `{"runs": ["<ISO 8601>", ...]}`, 400 naming the
	 *                      malformed field, 401 or 403.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
	 */
	#[NoAdminRequired]
	public function schedulePreview(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'job.schedule-preview');

		$calendar = $this->request->getParam('schedule', $this->request->getParam('calendar'));
		if (is_array($calendar) === false) {
			return new JSONResponse(
				['error' => $this->l->t('A calendar schedule is required.')],
				\OCP\AppFramework\Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$runs = ($this->calendar ?? new JobCalendarSchedule())->preview(calendar: $calendar, count: 5);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			['runs' => array_map(static fn (DateTimeImmutable $run): string => $run->format('c'), $runs)]
		);
	}//end schedulePreview()

	/**
	 * Executes a job.
	 *
	 * This method executes a job based on its ID and returns the execution results.
	 *
	 * @param string $id The UUID of the job to execute.
	 *
	 * @return JSONResponse A JSON response containing the execution results.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function run(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'job.run');

		try {
			$job = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'job', _rbac: false);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
		}

		try {
			// Get execution parameters from request.
			$parameters = $this->request->getParams();

			// Remove non-parameter fields.
			foreach ($parameters as $key => $value) {
				if (str_starts_with($key, '_') === true) {
					unset($parameters[$key]);
				}
			}

			// Determine if forceRun is set.
			if (isset($parameters['forceRun']) === true) {
				$forceRun = filter_var($parameters['forceRun'], FILTER_VALIDATE_BOOLEAN);
			} else {
				$forceRun = false;
			}

			// Execute the job.
			$result = $this->jobService->executeJob(job: $job, forceRun: $forceRun);

			// Return the execution results (serialize the ObjectEntity to a plain array).
			if ($result === null) {
				return new JSONResponse(null);
			}

			// L4: Surface the real error code when the job failed.
			// executeJob() now always returns a log entry — check its level.
			$resultData = $result->jsonSerialize();
			if (($resultData['level'] ?? '') === 'ERROR') {
				return new JSONResponse($resultData, 500);
			}

			return new JSONResponse($resultData);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
		}//end try
	}//end run()

	/**
	 * Test a job.
	 *
	 * This method executes a job based on its ID and returns the execution results.
	 *
	 * @param string $id The UUID of the job to execute.
	 *
	 * @return JSONResponse A JSON response containing the execution results.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'job.test');

		try {
			$job = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'job', _rbac: false);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
		}

		try {
			// Get execution parameters from request.
			$parameters = $this->request->getParams();

			// Remove non-parameter fields.
			foreach ($parameters as $key => $value) {
				if (str_starts_with($key, '_') === true) {
					unset($parameters[$key]);
				}
			}

			// Always force run for test.
			$forceRun = true;

			// Execute the job.
			$result = $this->jobService->executeJob(job: $job, forceRun: $forceRun);

			// Return the execution results (serialize the ObjectEntity to a plain array).
			if ($result === null) {
				return new JSONResponse(null);
			}

			// L4: Surface the real error code when the job failed.
			$resultData = $result->jsonSerialize();
			if (($resultData['level'] ?? '') === 'ERROR') {
				return new JSONResponse($resultData, 500);
			}

			return new JSONResponse($resultData);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
		}//end try
	}//end test()
}//end class
