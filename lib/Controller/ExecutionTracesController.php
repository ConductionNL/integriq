<?php

/**
 * OpenConnector ExecutionTracesController.
 *
 * REST surface for the `execution_trace` observability capability: list,
 * detail, and replay (dry-run by default, explicit force for a real write).
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ExecutionTraceService;
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
 * Controller for the execution_trace observability capability.
 *
 * Auth posture: `#[NoAdminRequired]` + `#[NoCSRFRequired]` at the route layer,
 * with the authorization decision delegated to `ActionAuthService` (ADR-023)
 * per method.
 *
 * ⚠️ The comment this replaced said the posture "mirrors the existing
 * `LogsController`/`SourcesController` convention … documented, not
 * re-litigated". What it actually mirrored was `LogsController`'s *missing*
 * guard: an authentication check and nothing else. A trace carries the full
 * ordered `steps` array of somebody's integration run — every request and
 * response payload that crossed that connector — and `replay(force: true)`
 * re-executes it for real. Both were reachable by any authenticated account
 * with a substituted id.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */
class ExecutionTracesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The request interface.
	 * @param ExecutionTraceService $executionTraceService Trace assembly/persistence/replay service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The ADR-023 action authorization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExecutionTraceService $executionTraceService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List execution traces with optional filtering and pagination.
	 *
	 * @param integer|null $limit Maximum number of results to return (default: 20).
	 * @param integer|null $offset Starting offset for results (default: 0).
	 * @param string|null $entryPoint Filter by entryPoint (endpoint|job|event|sync).
	 * @param string|null $status Filter by status (running|success|failed|short_circuited).
	 * @param string|null $dateFrom Filter traces from this date.
	 * @param string|null $dateTo Filter traces until this date.
	 *
	 * @return JSONResponse The traces list response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(
		?int $limit = 20,
		?int $offset = 0,
		?string $entryPoint = null,
		?string $status = null,
		?string $dateFrom = null,
		?string $dateTo = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'execution-trace.index');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$filters = [];
		if ($entryPoint !== null) {
			$filters['entryPoint'] = $entryPoint;
		}

		if ($status !== null) {
			$filters['status'] = $status;
		}

		if ($dateFrom !== null) {
			$filters['date_from'] = $dateFrom;
		}

		if ($dateTo !== null) {
			$filters['date_to'] = $dateTo;
		}

		$matches = $this->executionTraceService->list(filters: $filters, limit: $limit, offset: $offset);
		$results = $matches['results'];
		$total = $matches['total'];

		if ($limit > 0) {
			$pages = ceil($total / $limit);
			$currentPage = (floor($offset / $limit) + 1);
		} else {
			$pages = 1;
			$currentPage = 1;
		}

		return new JSONResponse(
			[
				'results' => $results,
				'pagination' => [
					'page' => $currentPage,
					'pages' => $pages,
					'results' => count($results),
					'total' => $total,
				],
			]
		);

	}//end index()

	/**
	 * Get one execution trace, including its full ordered `steps` array.
	 *
	 * @param string $id The trace uuid.
	 *
	 * @return JSONResponse The trace, or 404 when not found.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'execution-trace.show');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		// `find()` returns null for a miss and propagates everything else, so
		// a broken OpenRegister does not arrive here dressed as a 404.
		try {
			$trace = $this->executionTraceService->find($id);
		} catch (DoesNotExistException $e) {
			$trace = null;
		}

		if ($trace === null) {
			return new JSONResponse(['error' => $this->l->t('Trace not found')], 404);
		}

		return new JSONResponse($trace->getObject());
	}//end show()

	/**
	 * Replay a traced execution: dry-run by default (no body or
	 * `{force: false}`), an explicit `{force: true}` performs a real write
	 * via the original entry point's own dispatch mechanism.
	 *
	 * @param string $id The trace uuid to replay.
	 *
	 * @return JSONResponse The new, linked execution_trace, or 404 when the original trace does not exist.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function replay(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'execution-trace.replay');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$force = filter_var($this->request->getParam('force', false), FILTER_VALIDATE_BOOLEAN);

		try {
			$replayed = $this->executionTraceService->replay(traceId: $id, actorUid: $user->getUID(), force: $force);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('Trace not found')], 404);
		}

		return new JSONResponse($replayed->getObject());
	}//end replay()
}//end class
