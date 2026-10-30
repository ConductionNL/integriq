<?php

/**
 * Integriq sources controller.
 *
 * Controller for source listing pages and source-test/call-log endpoints.
 * Surfaces filtered call logs and runs ad-hoc test calls against a source.
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
 */

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\SearchServiceInterface;
use OCA\Integriq\Service\SourceTestServiceInterface;
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
 * Controller for source-test and call-log endpoints.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
 */
class SourcesController extends Controller {
	/**
	 * Constructor for the SourcesController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param OrObjectService $orObjectService The OR object service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Retrieves call logs with filtering and pagination support.
	 *
	 * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
	 *
	 * @param SearchServiceInterface $searchService Service used to strip special query parameters.
	 *
	 * @return JSONResponse A JSON response containing the filtered call logs and pagination.
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function logs(SearchServiceInterface $searchService): JSONResponse {
		try {
			$filters = $this->request->getParams();
			$limit   = (int)($filters['_limit'] ?? 20);
			$page    = (int)($filters['_page'] ?? 1);
			$offset  = (($page - 1) * $limit);

			// Underscore keys (_limit, _page, _sort) are dropped with the other special query params.
			$filters = $searchService->unsetSpecialQueryParams(filters: $this->withoutCallLogSearchFilters(filters: $filters));

			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => array_merge(['register' => 'integriq', 'schema' => 'call_log'], $filters),
					'limit' => $limit,
					'offset' => $offset,
				]
			);
			$callLogs = ($matches['results'] ?? $matches);
			$total    = ($matches['total'] ?? count($callLogs));

			$pages       = 1;
			$currentPage = 1;
			if ($limit > 0) {
				$pages       = ceil($total / $limit);
				$currentPage = (floor($offset / $limit) + 1);
			}

			return new JSONResponse(
				[
					'results' => $callLogs,
					'page' => $currentPage,
					'pages' => $pages,
					'results_count' => count($callLogs),
					'total' => $total,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
		}//end try
	}//end logs()

	/**
	 * The request filters without the call log search filters that are set.
	 *
	 * The date range, endpoint, status code range and slow request filters are
	 * not passed on to OpenRegister's call log query.
	 *
	 * @param array $filters The request parameters.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	private function withoutCallLogSearchFilters(array $filters): array {
		foreach (['date_from', 'date_to', 'endpoint', 'status_code', 'slow_requests'] as $key) {
			if (empty($filters[$key]) === false) {
				unset($filters[$key]);
			}
		}

		return $filters;
	}//end withoutCallLogSearchFilters()

	/**
	 * Test a source.
	 *
	 * This method fires a test call to the source and returns the response.
	 *
	 * @param SourceTestServiceInterface $sourceTestService Fires the outbound test call (shared with the connection health job).
	 * @param string $id The UUID of the source to test.
	 *
	 * @return JSONResponse A JSON response containing the test results.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(SourceTestServiceInterface $sourceTestService, string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'source.test');

		// ObjectService::find() throws DoesNotExistException on a missing
		// UUID — catch it so the response is a clean 404 instead of 500.
		try {
			$source = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
		}

		// Get the request data.
		$requestData = $this->request->getParams();

		// Build Guzzle call configuration array.
		$config = [];

		// Add headers if present.
		if (isset($requestData['headers']) === true && is_array($requestData['headers']) === true) {
			$config['headers'] = $requestData['headers'];
		}

		// Add query parameters if present.
		if (isset($requestData['query']) === true && is_array($requestData['query']) === true) {
			$config['query'] = $requestData['query'];
		}

		// Set method, default to POST if not provided.
		$method = ($requestData['method'] ?? 'GET');

		// Set endpoint.
		$endpoint = ($requestData['endpoint'] ?? '');

		// Set body if present.
		if (isset($requestData['body']) === true) {
			$config['body'] = $requestData['body'];
		}

		// Set content type based on the type parameter.
		if (isset($requestData['type']) === true) {
			switch ($requestData['type']) {
				case 'json':
					$config['headers']['Content-Type'] = 'application/json';
					break;
				case 'xml':
					$config['headers']['Content-Type'] = 'application/xml';
					break;
				case 'yaml':
					$config['headers']['Content-Type'] = 'application/x-yaml';
					break;
			}
		}

		// The call itself lives in SourceTestService, shared with the
		// connection health job, so a probe and this button test a source the
		// same way. It fires with persistLog:false and never throws: the
		// Test-connection modal always gets a readable result.
		$test = $sourceTestService->run(source: $source, endpoint: $endpoint, method: $method, config: $config);

		if ($test['outcome'] === SourceTestServiceInterface::OUTCOME_FAILED) {
			return new JSONResponse(
				data: ['error' => $this->l->t('The source test could not be completed: %s', [$test['error']])],
				statusCode: \OCP\AppFramework\Http::STATUS_BAD_GATEWAY
			);
		}

		if ($test['outcome'] === SourceTestServiceInterface::OUTCOME_NO_RESPONSE) {
			// The engine returned without a usable response (e.g. an early-exit CallLog).
			// Give the UI an explicit error rather than an empty/opaque body.
			return new JSONResponse(
				data: ['error' => $this->l->t('The source test returned no response data.')],
				statusCode: \OCP\AppFramework\Http::STATUS_BAD_GATEWAY
			);
		}

		$result = $test['result'];

		return new JSONResponse($result);
	}//end test()

	/**
	 * Manually trips the circuit breaker for a Source (REQ-009).
	 *
	 * Admin-only, CSRF-protected — deliberately NOT `@NoAdminRequired`
	 * (Decision 6, retry-and-circuit-breaker-policies design.md): tripping a
	 * breaker is an operationally sensitive action that can black-hole
	 * traffic to an upstream, so it follows the `dead-letter-replay`
	 * admin-only posture rather than this app's older IDOR-prone convention.
	 *
	 * @param CallServiceInterface $callService The CallService used to trip the breaker.
	 * @param string $id The UUID of the source to trip.
	 *
	 * @return JSONResponse The updated breaker state, or 404 when the source is unknown.
	 *
	 * @no-admin-idor-exempt Admin-only via #[AuthorizedAdminSetting]; not a
	 * #[NoAdminRequired] endpoint. The IDOR gate misattributes the preceding
	 * test() method's @NoAdminRequired across the method boundary.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function tripCircuitBreaker(CallServiceInterface $callService, string $id): JSONResponse {
		try {
			$source = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
		}

		$saved = $callService->tripCircuitBreaker(source: $source);
		$data = $saved->getObject();

		return new JSONResponse(
			[
				'uuid' => $saved->getUuid(),
				'circuitBreakerState' => $data['circuitBreakerState'],
				'circuitBreakerOpenedAt' => $data['circuitBreakerOpenedAt'],
			]
		);
	}//end tripCircuitBreaker()

	/**
	 * Manually resets the circuit breaker for a Source (REQ-009).
	 *
	 * Admin-only, CSRF-protected — see {@see tripCircuitBreaker()} for the
	 * rationale.
	 *
	 * @param CallServiceInterface $callService The CallService used to reset the breaker.
	 * @param string $id The UUID of the source to reset.
	 *
	 * @return JSONResponse The updated breaker state, or 404 when the source is unknown.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function resetCircuitBreaker(CallServiceInterface $callService, string $id): JSONResponse {
		try {
			$source = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
		}

		$saved = $callService->resetCircuitBreaker(source: $source);
		$data = $saved->getObject();

		return new JSONResponse(
			[
				'uuid' => $saved->getUuid(),
				'circuitBreakerState' => $data['circuitBreakerState'],
				'circuitBreakerFailureCount' => $data['circuitBreakerFailureCount'],
			]
		);
	}//end resetCircuitBreaker()
}//end class
