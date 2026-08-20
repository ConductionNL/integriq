<?php

/**
 * OpenConnector Flows Controller.
 *
 * Bespoke, non-CRUD flow actions only — standard `flow` object CRUD goes
 * through OpenRegister's generic `/api/objects/openconnector/flow/*`
 * routes (ADR-022; see `appinfo/routes.php`'s "Resource block intentionally
 * omitted" note and the `hydra-gate-redundant-controller` gate this
 * controller deliberately avoids tripping). This controller only adds the
 * manual "Run" trigger (flow-orchestration REQ-007d), following the exact
 * `JobsController::run()` / `SynchronizationsController::run()` precedent.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Exception\FlowRunException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Manual "Run" trigger for a `flow` (flow-orchestration REQ-007d).
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
 */
class FlowsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param FlowRunnerService $flowRunnerService Executes a flow's steps.
	 * @param ActionAuthService $actionAuth ADR-023 action-matrix authorization gate.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowRunnerService $flowRunnerService,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Manually run a flow synchronously (flow-orchestration REQ-007d /
	 * TC-17). Returns the resulting `flow_run`'s status and (embedded)
	 * `flow_run_log` trail, mirroring `JobsController::run()`'s response
	 * shape.
	 *
	 * @param string $id The flow's OpenRegister id.
	 *
	 * @return JSONResponse The resulting flow_run, serialized.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function run(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'flow.run');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$flow = $this->flowRunnerService->findFlow(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('Flow not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$flowRun = $this->flowRunnerService->run(flow: $flow, triggerSource: 'manual');
		} catch (Exception $e) {
			// FlowRunException (a fatal flow-definition error, e.g. duplicate
			// step order) maps to 422; any other unexpected failure (e.g. an
			// OpenRegister persistence error) maps to 500 — a single catch
			// block distinguished via instanceof, since FlowRunException is
			// itself an Exception subtype (avoids a provably-unreachable
			// second catch arm).
			if ($e instanceof FlowRunException) {
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			return new JSONResponse(['error' => $this->l->t('Failed to run flow: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$resultData = $flowRun->jsonSerialize();

		$status = (string)($resultData['status'] ?? '');
		if ($status === 'failed' || $status === 'stopped' || $status === 'dead_letter') {
			return new JSONResponse($resultData, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($resultData);
	}//end run()
}//end class
