<?php

/**
 * Integriq exchange controller.
 *
 * The read model an app queries for its exchange jobs, plus the two
 * correction actions on a rejection.
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
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * `/api/exchange/*` (contract.md, "Integriq endpoints").
 *
 * Every method checks the session, then its ADR-023 action, before reading
 * anything; every read is scoped to the `ownerApp` the caller names, so a job
 * of another app answers 404.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
 */
class ExchangeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app id.
	 * @param IRequest                 $request     The request.
	 * @param ExchangeReadModel        $readModel   The read model.
	 * @param ExchangeRejectionService $rejections  The correction actions.
	 * @param ActionAuthService        $actionAuth  ADR-023 action checks.
	 * @param IUserSession             $userSession The session.
	 * @param IL10N                    $l           Translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExchangeReadModel $readModel,
		private readonly ExchangeRejectionService $rejections,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List the caller-named app's exchange jobs.
	 *
	 * @return JSONResponse `{results, total}`, or 400/401/403.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function jobs(): JSONResponse {
		$user = $this->authorise(action: 'exchange.read');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$ownerApp = (string)$this->request->getParam('ownerApp', '');
		if ($ownerApp === '') {
			return $this->missingOwner();
		}

		return new JSONResponse(
			$this->readModel->listJobs(
				ownerApp: $ownerApp,
				filters: [
					'target' => (string)$this->request->getParam('target', ''),
					'status' => (string)$this->request->getParam('status', ''),
					'ownerRef' => (string)$this->request->getParam('ownerRef', ''),
				],
				limit: (int)$this->request->getParam('limit', 50),
				offset: (int)$this->request->getParam('offset', 0)
			)
		);

	}//end jobs()

	/**
	 * One exchange job of the caller-named app.
	 *
	 * @param string $id The job's uuid.
	 *
	 * @return JSONResponse The job, or 400/401/403/404.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function job(string $id): JSONResponse {
		$user = $this->authorise(action: 'exchange.read');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$ownerApp = (string)$this->request->getParam('ownerApp', '');
		if ($ownerApp === '') {
			return $this->missingOwner();
		}

		$row = $this->readModel->getJob(ownerApp: $ownerApp, jobId: $id);
		if ($row === null) {
			return new JSONResponse(['error' => $this->l->t('Exchange job not found')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row);

	}//end job()

	/**
	 * List the caller-named app's exchange rejections.
	 *
	 * @return JSONResponse `{results, total}`, or 400/401/403.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rejections(): JSONResponse {
		$user = $this->authorise(action: 'exchange.read');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$ownerApp = (string)$this->request->getParam('ownerApp', '');
		if ($ownerApp === '') {
			return $this->missingOwner();
		}

		return new JSONResponse(
			$this->readModel->listRejections(
				ownerApp: $ownerApp,
				filters: [
					'status' => (string)$this->request->getParam('status', ''),
					'target' => (string)$this->request->getParam('target', ''),
					'jobId' => (string)$this->request->getParam('jobId', ''),
				],
				limit: (int)$this->request->getParam('limit', 50),
				offset: (int)$this->request->getParam('offset', 0)
			)
		);

	}//end rejections()

	/**
	 * The target catalogue.
	 *
	 * @return JSONResponse `{results}`, or 401/403.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function targets(): JSONResponse {
		$user = $this->authorise(action: 'exchange.read');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		return new JSONResponse(['results' => $this->readModel->targets()]);

	}//end targets()

	/**
	 * Resubmit a rejection as a single-record job.
	 *
	 * @param string $id The rejection's uuid.
	 *
	 * @return JSONResponse `{rejectionId, jobId}`, or 401/403/404/409.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	#[NoAdminRequired]
	public function resubmit(string $id): JSONResponse {
		$user = $this->authorise(action: 'exchange.resubmit');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		try {
			$outcome = $this->rejections->resubmit(rejectionId: $id, actor: $user->getUID());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidMessageStateException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['rejectionId' => $outcome['rejectionId'], 'jobId' => $outcome['jobId']]);

	}//end resubmit()

	/**
	 * Waive a rejection with a reason.
	 *
	 * @param string $id The rejection's uuid.
	 *
	 * @return JSONResponse The updated rejection, or 400/401/403/404/409.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	#[NoAdminRequired]
	public function waive(string $id): JSONResponse {
		$user = $this->authorise(action: 'exchange.waive');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(['error' => $this->l->t('A reason is required to waive a rejection')], Http::STATUS_BAD_REQUEST);
		}

		$entry = $this->rejections->find(rejectionId: $id);
		if ($entry === null) {
			return new JSONResponse(['error' => $this->l->t('Exchange rejection not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$saved = $this->rejections->waive(entry: $entry, actor: $user->getUID(), reason: $reason);
		} catch (InvalidMessageStateException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['id' => $saved->getUuid()] + $saved->getObject());

	}//end waive()

	/**
	 * The session check plus the ADR-023 action check.
	 *
	 * @param string $action The action name.
	 *
	 * @return IUser|JSONResponse The allowed user, or a 401 or 403 response.
	 */
	private function authorise(string $action): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return $user;

	}//end authorise()

	/**
	 * The 400 for a read without `ownerApp`.
	 *
	 * @return JSONResponse The response.
	 */
	private function missingOwner(): JSONResponse {
		return new JSONResponse(['error' => $this->l->t('The ownerApp parameter is required')], Http::STATUS_BAD_REQUEST);

	}//end missingOwner()
}//end class
