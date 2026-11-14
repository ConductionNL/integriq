<?php

/**
 * Integriq EndpointsController.
 *
 * Controller for handling endpoint related operations: page render, generic
 * path matching (handlePath), CORS preflight, logs and the simple-endpoint
 * optimisation path.
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Controller;

use Exception;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Service\EndpointCacheServiceInterface;
use OCA\Integriq\Service\EndpointCorsPolicyInterface;
use OCA\Integriq\Service\EndpointServiceInterface;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SearchServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling endpoint related operations.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008
 */
class EndpointsController extends Controller {

	/**
	 * CORS allowed methods.
	 *
	 * @var string
	 */
	private string $corsMethods;

	/**
	 * CORS allowed headers.
	 *
	 * @var string
	 */
	private string $corsAllowedHeaders;

	/**
	 * CORS max age.
	 *
	 * @var integer
	 */
	private int $corsMaxAge;

	/**
	 * Endpoint keys that each name a processing step the simple fast path
	 * cannot run; any one of them non-empty routes the request through the
	 * full EndpointService.
	 *
	 * @var string[]
	 */
	private const FAST_PATH_EXCLUDED_KEYS = [
		'rules',
		'fixedFilters',
		'anonymousRateLimit',
		'conditions',
		'inputMapping',
		'outputMapping',
		'configurations',
	];

	/**
	 * Constructor for the EndpointsController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param EndpointServiceInterface $endpointService Service for handling endpoint operations.
	 * @param OpenRegisterCredentialBridgeInterface $authorizationService Service for handling authorization.
	 * @param ObjectService $objectService Service for direct ObjectService operations.
	 * @param EndpointCacheServiceInterface $endpointCacheService Service for cached endpoint lookups.
	 * @param LoggerInterface $logger Service for logging.
	 * @param IL10N $l The localization service.
	 * @param EndpointCorsPolicyInterface $corsPolicy An endpoint's own CORS policy (REQ-EP-014).
	 * @param string $corsMethods Allowed CORS methods.
	 * @param string $corsAllowedHeaders Allowed CORS headers.
	 * @param integer $corsMaxAge CORS max age in seconds.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private EndpointServiceInterface $endpointService,
		private OpenRegisterCredentialBridgeInterface $authorizationService,
		private ObjectService $objectService,
		private EndpointCacheServiceInterface $endpointCacheService,
		private LoggerInterface $logger,
		private IL10N $l,
		private EndpointCorsPolicyInterface $corsPolicy,
		$corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		$corsAllowedHeaders = 'Authorization, Content-Type, Accept',
		$corsMaxAge = 1728000,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->corsMethods = $corsMethods;
		$this->corsAllowedHeaders = $corsAllowedHeaders;
		$this->corsMaxAge = $corsMaxAge;

	}//end __construct()

	/**
	 * Handles generic path requests by matching against registered endpoints.
	 *
	 * This method checks if the current path matches any registered endpoint patterns
	 * and forwards the request to the appropriate endpoint service if found.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): the dynamic endpoint router — every
	 * configured integration endpoint is served through here, so this is the
	 * busiest public path in the app and the ceiling has to accommodate all of
	 * them at once.
	 *
	 * @param string $_path The path component appended after /api/endpoint/.
	 *
	 * @return JSONResponse|XMLResponse|Response The response from the endpoint service or 404 if no match.
	 *
	 * @throws Exception On underlying service failure.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function handlePath(string $_path): Response {
		$endpoint = $this->matchEndpoint(path: $_path);
		if ($endpoint instanceof JSONResponse) {
			return $endpoint;
		}

		$response = $this->serve(endpoint: $endpoint, path: $_path);
		$response = $this->inRequestedFormat(response: $response, path: $_path);
		$response = $this->authorizationService->corsAfterController($this->request, $response);

		// An endpoint's own CORS policy replaces the echoed origin (REQ-EP-014).
		foreach (($this->corsPolicy->headersFor($endpoint) ?? []) as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end handlePath()

	/**
	 * The endpoint registered for the path and method (using cache), or the
	 * 404 when none matches and the 409 when the match is ambiguous.
	 *
	 * @param string $path The path component appended after /api/endpoint/.
	 *
	 * @return ObjectEntity|JSONResponse The endpoint, or the refusal.
	 */
	private function matchEndpoint(string $path): ObjectEntity|JSONResponse {
		try {
			$endpoint = $this->endpointCacheService->findByPathRegex(
				path: $path,
				method: $this->request->getMethod()
			);
			if ($endpoint === null) {
				return $this->noEndpointFor(path: $path);
			}
		} catch (\Exception $e) {
			// Multiple endpoints found (handled by cache service).
			return $this->ambiguousEndpoint(exception: $e);
		}

		return $endpoint;
	}//end matchEndpoint()

	/**
	 * The 404 for a path and method no endpoint is registered for.
	 *
	 * @param string $path The requested path.
	 *
	 * @return JSONResponse
	 */
	private function noEndpointFor(string $path): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $this->l->t(
					'No matching endpoint found for path and method: %1$s %2$s',
					[$path, $this->request->getMethod()]
				),
			],
			statusCode: 404
		);
	}//end noEndpointFor()

	/**
	 * The 409 for a path more than one endpoint matches.
	 *
	 * @param \Exception $exception The cache service's refusal.
	 *
	 * @return JSONResponse
	 */
	private function ambiguousEndpoint(\Exception $exception): JSONResponse {
		return new JSONResponse(
			data: ['error' => $exception->getMessage()],
			statusCode: 409
		);
	}//end ambiguousEndpoint()

	/**
	 * Serve the request: simple endpoints (no rules, conditions or mappings)
	 * directly, every other one through EndpointService.
	 *
	 * @param ObjectEntity $endpoint The matched endpoint.
	 * @param string       $path     The requested path.
	 *
	 * @return Response
	 */
	private function serve(ObjectEntity $endpoint, string $path): Response {
		// OPTIMIZATION: For simple endpoints with no rules/conditions/mappings, bypass EndpointService.
		if ($this->isSimpleEndpoint(endpoint: $endpoint) === true) {
			return $this->handleSimpleSchemaRequest(endpoint: $endpoint, path: $path);
		}

		return $this->endpointService->handleRequest($endpoint, $this->request, $path);
	}//end serve()

	/**
	 * The answer as XML when the Accept header asks for it and it is JSON;
	 * otherwise as served.
	 *
	 * @param Response $response The served answer.
	 * @param string   $path     The requested path.
	 *
	 * @return Response
	 */
	private function inRequestedFormat(Response $response, string $path): Response {
		$acceptHeader = $this->request->getHeader('Accept');
		if (stripos($acceptHeader, 'application/xml') === false || $response instanceof JSONResponse === false) {
			return $response;
		}

		// Convert JSON response to XML response.
		return new XMLResponse(
			data: $response->getData(),
			status: $response->getStatus(),
			headers: $response->getHeaders(),
			path: $path
		);
	}//end inRequestedFormat()

	/**
	 * Implements a preflighted CORS response for OPTIONS requests.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): CORS preflight — the browser sends one
	 * before each cross-origin call, so it is looser than the call it precedes.
	 *
	 * An endpoint that declares its own CORS policy answers that policy
	 * (REQ-EP-014); every other path keeps the echo of the caller's origin.
	 *
	 * @param string $_path The path component appended after /api/endpoint/.
	 *
	 * @return Response The CORS response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @since 7.0.0
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 480, period: 60)]
	public function preflightedCors(string $_path=''): Response {
		// Determine the origin.
		$origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');

		// Create and configure the response.
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		foreach (($this->corsPolicy->headersFor($this->preflightEndpoint(path: $_path)) ?? []) as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end preflightedCors()

	/**
	 * The endpoint a preflight asks about, or null when none (or more than one) matches.
	 *
	 * @param string $path The path component appended after /api/endpoint/.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-declare-its-own-cors-policy-req-ep-014
	 */
	private function preflightEndpoint(string $path): ?ObjectEntity {
		if ($path === '') {
			return null;
		}

		$method = strtoupper(trim($this->request->getHeader('Access-Control-Request-Method')));
		if ($method === '') {
			$method = 'GET';
		}

		try {
			return $this->endpointCacheService->findByPathRegex(path: $path, method: $method);
		} catch (Exception $e) {
			// Several endpoints match: answer the default preflight; the call itself answers 409.
			return null;
		}
	}//end preflightEndpoint()

	/**
	 * Retrieves endpoint logs with filtering and pagination support.
	 *
	 * @param SearchServiceInterface $searchService Search service injected by route resolution.
	 *
	 * @return JSONResponse A JSON response containing the filtered endpoint logs and pagination.
	 *
	 * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function logs(SearchServiceInterface $searchService): JSONResponse {
		// Endpoint logging is not yet wired to the OR call_log schema.
		// Returning an empty paginated result (instead of 500) lets clients
		// poll without breaking; once the migration lands this returns a
		// real result set.
		return new JSONResponse(
			[
				'results' => [],
				'total' => 0,
				'page' => 1,
				'pages' => 1,
				'limit' => (int)$this->request->getParam('_limit', 20),
				'offset' => 0,
			]
		);

	}//end logs()

	/**
	 * Check if an endpoint is simple (no rules, conditions, mappings, configurations).
	 *
	 * An endpoint must also carry `isPublic: true` to be eligible for the simple
	 * path — without an explicit opt-in the request is routed through the full
	 * EndpointService which runs the authentication-rule pipeline.  Only GET
	 * requests are allowed on the simple path; write methods (POST/PUT/PATCH/
	 * DELETE) are always routed through EndpointService so that OR's write RBAC
	 * layer (OR #1949) is respected.
	 *
	 * @param ObjectEntity $endpoint The endpoint to check.
	 *
	 * @return boolean True if the endpoint qualifies for the optimised simple path.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoints-fixed-filters-narrow-its-collection-and-no-path-skips-them-req-ep-012
	 */
	private function isSimpleEndpoint(ObjectEntity $endpoint): bool {
		$data = $endpoint->getObject();

		// Only GET is allowed on the simple path — writes must go through the
		// full EndpointService so OR's write RBAC (OR #1949/#1951) is honoured.
		if ($this->request->getMethod() !== 'GET') {
			return false;
		}

		// The endpoint must explicitly declare isPublic:true to opt in to
		// anonymous access via the simple path.
		if (($data['isPublic'] ?? false) !== true) {
			return false;
		}

		// Check if endpoint has no complex processing requirements. Fixed
		// filters count as one: the fast path answers a single object without
		// the id-fetch guard (REQ-EP-012).
		return $this->hasNoProcessing(data: $data) === true
			&& ($data['targetType'] ?? '') === 'register/schema';

	}//end isSimpleEndpoint()

	/**
	 * Whether the endpoint declares none of the processing steps the fast
	 * path cannot run (rules, fixed filters, rate limits, conditions,
	 * mappings, configurations).
	 *
	 * @param array $data The endpoint object data.
	 *
	 * @return boolean True when every processing key is empty.
	 */
	private function hasNoProcessing(array $data): bool {
		foreach (self::FAST_PATH_EXCLUDED_KEYS as $key) {
			if (empty($data[$key]) === false) {
				return false;
			}
		}

		return true;

	}//end hasNoProcessing()

	/**
	 * Handle simple GET-only schema requests directly without EndpointService
	 * overhead, while still attaching RFC 8594 deprecation headers and
	 * product-scoped inbound observability logging when the endpoint belongs
	 * to an `api_product` — this fast path bypasses `EndpointService::
	 * handleRequest()` entirely, so those two concerns (normally applied at
	 * that choke point) are wired here directly via the small set of public
	 * `EndpointService` helpers built for exactly this purpose
	 * (`resolveProductForEndpoint()`/`buildDeprecationHeaders()`/
	 * `recordInboundCallLog()`).
	 *
	 * Only reachable when isSimpleEndpoint() returns true, which requires:
	 *   - HTTP method = GET
	 *   - endpoint.isPublic = true
	 *   - no rules / conditions / mappings / configurations
	 * Write methods (POST/PUT/PATCH/DELETE) are always routed through the full
	 * EndpointService so OR's ObjectService write RBAC (OR #1949/#1951) is
	 * honoured end-to-end. Because the simple path never authenticates a
	 * consumer (no rule pipeline runs), per-tier rate-limit enforcement does
	 * not apply here — there is no consumer identity to resolve a
	 * subscription against.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration.
	 * @param string $path The request path.
	 *
	 * @return JSONResponse The direct response from ObjectService, with deprecation headers merged in when applicable.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008
	 */
	private function handleSimpleSchemaRequest(ObjectEntity $endpoint, string $path): JSONResponse {
		$startTime = microtime(true);
		$product = $this->endpointService->resolveProductForEndpoint(endpoint: $endpoint);

		$response = $this->dispatchSimpleSchemaRequest(endpoint: $endpoint, path: $path);

		if ($product !== null) {
			foreach ($this->endpointService->buildDeprecationHeaders(product: $product) as $headerName => $headerValue) {
				$response->addHeader($headerName, $headerValue);
			}

			$durationMs = ((microtime(true) - $startTime) * 1000);
			$this->endpointService->recordInboundCallLog(
				endpoint: $endpoint,
				product: $product,
				statusCode: $response->getStatus(),
				durationMs: $durationMs
			);
		}

		return $response;
	}//end handleSimpleSchemaRequest()

	/**
	 * The original simple-endpoint dispatch logic (renamed, unchanged), now
	 * wrapped by {@see handleSimpleSchemaRequest()} so deprecation headers
	 * and product-scoped inbound logging apply uniformly to every return
	 * path below without duplicating them at each `return` statement.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration.
	 * @param string $path The request path.
	 *
	 * @return JSONResponse The direct response from ObjectService.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function dispatchSimpleSchemaRequest(ObjectEntity $endpoint, string $path): JSONResponse {
		try {
			$query = $this->buildSimpleQuery(endpoint: $endpoint, path: $path);
			if ($query instanceof JSONResponse) {
				return $query;
			}

			return $this->answerSimpleQuery(query: $query);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $this->l->t('Simple endpoint error: %s', [$e->getMessage()])], 500);
		}//end try

	}//end dispatchSimpleSchemaRequest()

	/**
	 * Read the target, path parameters and query parameters of a fast-path
	 * request, or the 500 a misconfigured target answers.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration.
	 * @param string       $path     The request path.
	 *
	 * @return SimpleSchemaQuery|JSONResponse The query, or the error response.
	 */
	private function buildSimpleQuery(ObjectEntity $endpoint, string $path): SimpleSchemaQuery|JSONResponse {
		$endpointData = $endpoint->getObject();
		$target = $this->parseSimpleTarget(endpointData: $endpointData);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		$endpointArray = ($endpointData['endpointArray'] ?? explode('/', $endpointData['endpoint'] ?? ''));
		$query = new SimpleSchemaQuery();
		$query->target = $target;
		$query->path = $path;
		$query->pathParams = $this->getPathParameters(endpointArray: $endpointArray, path: $path);
		// Remove _path as parameters (not needed and breaks things).
		$query->parameters = array_diff_key($this->request->getParams(), ['_path' => true]);

		return $query;

	}//end buildSimpleQuery()

	/**
	 * Parse the register and schema from the endpoint's targetId (e.g. "20/111").
	 *
	 * @param array $endpointData The endpoint object data.
	 *
	 * @return SimpleSchemaTarget|JSONResponse The target, or the 500 a misconfigured one answers.
	 */
	private function parseSimpleTarget(array $endpointData): SimpleSchemaTarget|JSONResponse {
		$target = SimpleSchemaTarget::fromEndpoint(endpointData: $endpointData);
		if (empty($target->targetId) === true) {
			$this->logger->error('Simple endpoint has empty targetId', ['endpoint' => $target->endpoint]);
			return new JSONResponse(['error' => $this->l->t('Endpoint misconfigured: empty targetId')], 500);
		}

		if ($target->isWellFormed() === false) {
			return $this->invalidTargetResponse(target: $target);
		}

		return $target;

	}//end parseSimpleTarget()

	/**
	 * Log a targetId that is not a "register/schema" pair and answer 500.
	 *
	 * @param SimpleSchemaTarget $target The malformed target.
	 *
	 * @return JSONResponse The error response.
	 */
	private function invalidTargetResponse(SimpleSchemaTarget $target): JSONResponse {
		$this->logger->error(
			'Simple endpoint has invalid targetId format',
			[
				'endpoint' => $target->endpoint,
				'targetId' => $target->targetId,
				'parsed'   => $target->parts(),
			]
		);

		return new JSONResponse(['error' => $this->l->t('Endpoint misconfigured: invalid targetId format. Expected "register/schema"')], 500);

	}//end invalidTargetResponse()

	/**
	 * Answer a fast-path query: one object when the path names an id,
	 * otherwise the paginated collection.
	 *
	 * @param SimpleSchemaQuery $query The query.
	 *
	 * @return JSONResponse The response.
	 */
	private function answerSimpleQuery(SimpleSchemaQuery $query): JSONResponse {
		$mapper = $this->resolveSimpleMapper(target: $query->target);
		if ($mapper instanceof JSONResponse) {
			return $mapper;
		}

		// Only GET is handled here.  isSimpleEndpoint() guarantees this but
		// we guard defensively so a future refactor cannot silently open write
		// access without going through OR's RBAC layer.
		if ($this->request->getMethod() !== 'GET') {
			return new JSONResponse(['error' => $this->l->t('Method not supported on simple endpoint')], 405);
		}

		if ($query->addressesSingleObject() === true) {
			return new JSONResponse($mapper->find($query->pathParams['id'])->jsonSerialize());
		}

		return $this->answerSimpleCollection(query: $query, mapper: $mapper);

	}//end answerSimpleQuery()

	/**
	 * Get the ObjectService mapper for the target, or the 404 an unknown
	 * register or schema answers.
	 *
	 * @param SimpleSchemaTarget $target The target.
	 *
	 * @return mixed The mapper, or the error JSONResponse.
	 */
	private function resolveSimpleMapper(SimpleSchemaTarget $target): mixed {
		try {
			return $this->objectService->getMapper(schema: $target->schema(), register: $target->register());
		} catch (\Exception $e) {
			$this->logger->error('Failed to get ObjectService mapper', array_merge($target->logContext(), ['error' => $e->getMessage()]));
			return new JSONResponse(['error' => $this->l->t('Schema or register not found: %s', [$e->getMessage()])], 404);
		}

	}//end resolveSimpleMapper()

	/**
	 * List the target's objects, log the query and answer the page with its
	 * count and page links.
	 *
	 * @param SimpleSchemaQuery $query  The query.
	 * @param mixed             $mapper The ObjectService mapper.
	 *
	 * @return JSONResponse The collection response.
	 */
	private function answerSimpleCollection(SimpleSchemaQuery $query, mixed $mapper): JSONResponse {
		$result = $mapper->findAllPaginated(requestParams: $query->parameters);
		$context = [
			'targetId'     => $query->target->targetId,
			'parameters'   => $query->parameters,
			'result_total' => ($result['total'] ?? 0),
		];
		$this->logger->info('Simple endpoint query', array_merge($query->target->logContext(), $context));

		return new JSONResponse($this->addPageLinks(page: $this->serializePage(result: $result), query: $query));

	}//end answerSimpleCollection()

	/**
	 * Serialize a page's objects and rename 'total' to 'count'.
	 *
	 * @param array $result The paginated mapper result.
	 *
	 * @return array The page as the fast path answers it.
	 */
	private function serializePage(array $result): array {
		$page = $result;
		$page['count'] = $result['total'];
		$page['results'] = array_map(fn ($obj) => $obj->jsonSerialize(), $result['results']);
		unset($page['total']);

		return $page;

	}//end serializePage()

	/**
	 * Add the next and previous page links when there are such pages.
	 *
	 * @param array             $page  The serialized page.
	 * @param SimpleSchemaQuery $query The query the page answers.
	 *
	 * @return array The page with its links.
	 */
	private function addPageLinks(array $page, SimpleSchemaQuery $query): array {
		$parameters = $query->parameters;
		if ($page['page'] < $page['pages']) {
			$parameters['page'] = ($page['page'] + 1);
			$page['next'] = $this->buildPaginationUrl(parameters: $parameters, path: $query->path);
		}

		if ($page['page'] > 1) {
			$parameters['page'] = ($page['page'] - 1);
			$page['previous'] = $this->buildPaginationUrl(parameters: $parameters, path: $query->path);
		}

		return $page;

	}//end addPageLinks()

	/**
	 * Parse path parameters from endpoint pattern and actual path.
	 *
	 * @param array $endpointArray The endpoint pattern array.
	 * @param string $path The actual request path.
	 *
	 * @return array The parsed parameters.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getPathParameters(array $endpointArray, string $path): array {
		$pathParts = explode('/', $path);
		$params = [];

		foreach ($endpointArray as $index => $pattern) {
			if (str_starts_with($pattern, '{{') === true && str_ends_with($pattern, '}}') === true) {
				$key = trim($pattern, '{}');
				if (isset($pathParts[$index]) === true) {
					$params[$key] = $pathParts[$index];
				}
			}
		}

		return $params;
	}//end getPathParameters()

	/**
	 * Build pagination URL for simple endpoints.
	 *
	 * @param array $parameters Query parameters.
	 * @param string $path The request path.
	 *
	 * @return string The pagination URL.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function buildPaginationUrl(array $parameters, string $path): string {
		$baseUrl = ($this->request->getServerProtocol() . '://' . $this->request->getServerHost() . '/apps/integriq/api/endpoint/' . $path);

		return ($baseUrl . '?' . http_build_query($parameters));
	}//end buildPaginationUrl()
}//end class
