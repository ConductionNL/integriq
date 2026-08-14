<?php

/**
 * OpenConnector endpoint service.
 *
 * Service class for handling endpoint requests. Routes to a schema within a
 * register, proxies to an external source, or dispatches to a generic source
 * connector, applying configured before/after rules and request mappings.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JWadhams\JsonLogic;
use OC\AppFramework\Http;
use OC\Files\Node\File;
use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Rule\AvgBsnPolicyRule;
use OCA\OpenConnector\Rule\CompositeFanoutRule;
use OCA\OpenConnector\Rule\ReferenceNumberRule;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Service\RateLimit\InboundRateLimitService;
use OCA\OpenConnector\Service\RateLimit\RateLimitDecision;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Util\SafeXmlParser;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;
use Symfony\Component\Uid\Uuid;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use UnexpectedValueException;
use function React\Async\await;
use function React\Promise\all;

/**
 * Service class for handling endpoint requests
 *
 * This class provides functionality to handle requests to endpoints, either by
 * connecting to a schema within a register or by proxying to a source.
 *
 * @SuppressWarnings(PHPMD)
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointService {

	/**
	 * Body parameter keys stripped before forwarding a request.
	 *
	 * @var array<int, string>
	 */
	private const UNSET_PARAMETERS = [
		'_parameters',
		'_utility',
		'_method',
		'_headers',
		'_route',
		'_path',
	];

	/**
	 * Rule types with an external or persisted side-effect — under a
	 * dry-run replay (`dryRun: true`), these MUST NOT perform their write;
	 * `processRules()` records a `skipped_dry_run` step instead
	 * (rule-pipeline REQ-RULE-011). `synchronization` is a deliberate
	 * partial exception NOT in this set — it forwards `$dryRun` into
	 * `processSyncRule()`/`SynchronizationService::synchronize()`'s own
	 * `isTest` no-write guarantee rather than being blanket-skipped.
	 *
	 * @var array<int, string>
	 */
	private const DRY_RUN_SUPPRESSED_RULE_TYPES = [
		'save_object',
		'override',
		'locking',
		'write_file',
		'fileparts_create',
		'filepart_upload',
		'composite_fanout',
		// `flow` triggers a real FlowRunnerService::run() — every step it
		// executes (call/save/synchronization) is write-shaped and has no
		// dry-run forward of its own, so a traced replay must suppress it
		// outright rather than fire a live flow run.
		'flow',
	];

	/**
	 * Constructor for EndpointService.
	 *
	 * @param ObjectService $objectService Service for handling object operations.
	 * @param CallService $callService Service for making external API calls.
	 * @param LoggerInterface $logger Logger interface for error logging.
	 * @param IURLGenerator $urlGenerator Nextcloud URL generator used for absolute links.
	 * @param MappingService $mappingService Service used to apply request/response mappings.
	 * @param ORObjectService $orObjectService OpenRegister object service for register/schema CRUD.
	 * @param IConfig $config Nextcloud system configuration.
	 * @param StorageService $storageService Service used for file part and attachment storage.
	 * @param AuthorizationService $authorizationService Service used to authorize incoming endpoint requests.
	 * @param ContainerInterface $containerInterface PSR container used to resolve optional services.
	 * @param SynchronizationService $synchronizationService Service used to dispatch endpoint synchronizations.
	 * @param RuleService $ruleService Service used to load and resolve endpoint rules.
	 * @param WebhookSignatureService $webhookSignatureService Service used to verify inbound webhook signatures.
	 * @param InboundRateLimitService $rateLimitService Service enforcing inbound per-consumer rate limits + quotas.
	 * @param CompositeFanoutRule $compositeFanoutRule Dialect-agnostic composite transactional fan-out rule.
	 * @param ReferenceNumberRule $referenceNumberRule Dialect-agnostic referentienummer generation rule.
	 * @param AvgBsnPolicyRule $avgBsnPolicyRule Dialect-agnostic AVG BSN hash/guard rule.
	 * @param ApprovalService $approvalService Suspends the pipeline on a HITL `approval` rule.
	 * @param IRequestId $requestId Nextcloud request-id service, used to synthesize
	 *                              an `IRequest` for `triggerFromFlow()`.
	 * @param FlowRunnerService $flowRunnerService Executes the `flow` rule action type (REQ-RULE-009).
	 * @param ConsumerScopeService $consumerScopeService Enforces the resolved consumer's source allowlist
	 *                                                   (`ips`/`domains`, REQ-CON-SCOPE-001).
	 * @param ExecutionTraceService|null $executionTraceService Assembles/persists the per-execution trace
	 *                                                          (execution-trace REQ-001/REQ-004).
	 *                                                          Nullable + defaulted so pre-existing
	 *                                                          positional test instantiations keep
	 *                                                          working unmodified; a real request always
	 *                                                          gets the DI container's instance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
		private readonly IURLGenerator $urlGenerator,
		private readonly MappingService $mappingService,
		private readonly ORObjectService $orObjectService,
		private readonly IConfig $config,
		private readonly StorageService $storageService,
		private readonly AuthorizationService $authorizationService,
		private readonly ContainerInterface $containerInterface,
		private readonly SynchronizationService $synchronizationService,
		private readonly RuleService $ruleService,
		private readonly WebhookSignatureService $webhookSignatureService,
		private readonly InboundRateLimitService $rateLimitService,
		private readonly CompositeFanoutRule $compositeFanoutRule,
		private readonly ReferenceNumberRule $referenceNumberRule,
		private readonly AvgBsnPolicyRule $avgBsnPolicyRule,
		private readonly ApprovalService $approvalService,
		private readonly IRequestId $requestId,
		private readonly FlowRunnerService $flowRunnerService,
		private readonly ConsumerScopeService $consumerScopeService,
		private readonly ?ExecutionTraceService $executionTraceService = null,
	) {
	}//end __construct()

	/**
	 * IETF RateLimit-* response headers to attach to the current request's
	 * response, populated during inbound rate-limit enforcement.
	 *
	 * @var array<string, string>
	 */
	private array $rateLimitHeaders = [];

	/**
	 * RFC 8594 `Deprecation`/`Sunset` response headers to attach to the
	 * current request's response, populated when the dispatched endpoint
	 * belongs to a `deprecated` `api_product` (REQ-APG-006/REQ-EP-008).
	 *
	 * @var array<string, string>
	 */
	private array $deprecationHeaders = [];

	/**
	 * Parse the error message from the validation service for ZGW format.
	 *
	 * @param array $response The response that is build.
	 * @param array $responseData The data from the responses found in the rules and openregister.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function parseMessage(array $response, array $responseData): array {
		if (isset($responseData['message']) === true
			&& $responseData['message'] === 'Validation failed'
			&& isset($responseData['errors']) === true
			&& str_contains(haystack: $responseData['errors'][0]['message'], needle: 'missing') === true
		) {
			$startChar = strpos($responseData['errors'][0]['message'], '(') + 1;
			$endChar = strpos($responseData['errors'][0]['message'], ')');

			$keys = explode(
				separator: ',',
				string: substr(
					string: $responseData['errors'][0]['message'],
					offset: $startChar,
					length: ($endChar - $startChar)
				)
			);

			$response['detail'] = $responseData['errors'][0]['message'];
			$response['invalidParams'] = array_map(
				function (string $key) {
					return ['property' => trim($key), 'code' => 'required', 'reason' => 'The required property is missing'];
				},
				$keys
			);
		} elseif (isset($responseData['message']) === true
			&& $responseData['message'] === 'Validation failed'
			&& isset($responseData['errors']) === true
			&& isset($responseData['errors'][0]['errors']) === true
		) {
			$response['detail'] = $responseData['errors'][0]['message'];
			$response['invalidParams'] = array_map(
				function (string $key, array $message) {
					if (str_contains(haystack: $message[0], needle: 'type') === true) {
						$code = 'invalid type';
					} else {
						$code = 'invalid value';
					}

					return ['property' => $key, 'code' => $code, 'reason' => $message[0]];
				},
				array_keys($responseData['errors'][0]['errors']),
				array_values($responseData['errors'][0]['errors'])
			);
		} elseif (isset($responseData['errors']) === true) {
			$response['invalidParams'] = $responseData['errors'];
		}//end if

		return $response;
	}//end parseMessage()

	/**
	 * Transform outgoing errors according to a specified format
	 *
	 * @param Response $result The result from either the rules or the target of the endpoint.
	 * @param IRequest $request The current request, used to determine the request identifier.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function transformError(Response $result, IRequest $request): Response {
		if ($result->getStatus() < 200 || $result->getStatus() >= 300) {
			$resultData = $result->getData();
			$message = $resultData['message'] ?? null;
			$error = $resultData['error'] ?? null;

			$responseData = [
				'type' => $message,
				'code' => $result->getStatus(),
				'title' => $message,
				'status' => $result->getStatus(),
				'instance' => $request->getId(),
				'detail' => $error,
			];

			$responseData = $this->parseMessage(response: $responseData, responseData: $resultData);

			return new JSONResponse(data: $responseData, statusCode: $result->getStatus());
		}

		return $result;
	}//end transformError()

	/**
	 * Handles incoming requests to endpoints, applying inbound rate-limit headers.
	 *
	 * Thin wrapper over {@see doHandleRequest()} that attaches the IETF
	 * `RateLimit-*` / `Retry-After` headers computed during inbound rate-limit
	 * enforcement to whatever response the request produced, at a single choke
	 * point so every return path carries them (REQ-CON-RL-003).
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration to handle
	 * @param IRequest $request The incoming request object
	 * @param string $path The specific path or sub-route being requested
	 *
	 * @return Response Response containing the result
	 * @throws Exception When endpoint configuration is invalid
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: IETF RateLimit response headers (REQ-CON-RL-003)
	 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008
	 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009
	 */
	public function handleRequest(ObjectEntity $endpoint, IRequest $request, string $path): Response {
		$this->rateLimitHeaders = [];
		$this->deprecationHeaders = [];
		$startTime = microtime(true);

		// Resolve the endpoint's api_product (if any) once, up front, so both
		// the deprecation headers and the inbound observability log (below)
		// reflect the SAME product resolution the rest of the pipeline used
		// (design.md Decision 5/6, endpoint-runtime REQ-EP-008/REQ-EP-009).
		$product = $this->resolveProductForEndpoint(endpoint: $endpoint);
		if ($product !== null) {
			$this->deprecationHeaders = $this->buildDeprecationHeaders(product: $product);
		}

		$response = $this->doHandleRequest(endpoint: $endpoint, request: $request, path: $path);

		foreach ($this->rateLimitHeaders as $headerName => $headerValue) {
			$response->addHeader($headerName, $headerValue);
		}

		foreach ($this->deprecationHeaders as $headerName => $headerValue) {
			$response->addHeader($headerName, $headerValue);
		}

		if ($product !== null) {
			$durationMs = ((microtime(true) - $startTime) * 1000);
			$this->recordInboundCallLog(
				endpoint: $endpoint,
				product: $product,
				statusCode: $response->getStatus(),
				durationMs: $durationMs
			);
		}

		return $response;
	}//end handleRequest()

	/**
	 * Trigger an endpoint from a WorkflowEngine "Call endpoint" operation
	 * (no live inbound HTTP request exists in that context).
	 *
	 * `handleRequest()` requires a live `OCP\IRequest`; there is no OCP-blessed
	 * way to construct one outside an HTTP request (design.md Decision 5 /
	 * discovery.md finding 4). This synthesizes one via NC's concrete
	 * `\OC\AppFramework\Http\Request` — the same class NC's own HTTP kernel
	 * constructs for every real request — and delegates to the existing
	 * `handleRequest()` unchanged; no routing/proxy/auth logic is duplicated
	 * here.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration to trigger.
	 * @param array $parameters Optional static key/value parameters configured on the Flow rule.
	 *
	 * @return Response The response `handleRequest()` produced, or a 500 `JSONResponse`
	 *                  when synthetic-request construction fails (see `buildSyntheticRequest()`).
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operation-s-onevent-must-dispatch-to-endpointservice-triggerfromflow-req-003
	 */
	public function triggerFromFlow(ObjectEntity $endpoint, array $parameters = []): Response {
		try {
			$request = $this->buildSyntheticRequest(parameters: $parameters);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WorkflowEngine "Call endpoint" operation could not synthesize a request: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(['error' => 'Unable to synthesize a request for this endpoint trigger'], 500);
		}

		return $this->handleRequest(endpoint: $endpoint, request: $request, path: '');
	}//end triggerFromFlow()

	/**
	 * Synthesize a minimal `OCP\IRequest` for `triggerFromFlow()`.
	 *
	 * Isolated in its own method so a future NC version that changes
	 * `\OC\AppFramework\Http\Request`'s constructor degrades one operation
	 * (caught by the caller, {@see triggerFromFlow()}) instead of crashing
	 * the triggering NC request (design.md Risk 2).
	 *
	 * @param array $parameters Optional static key/value parameters, merged into both `get` and `params`.
	 *
	 * @return IRequest A synthetic GET request carrying `$parameters`.
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operation-s-onevent-must-dispatch-to-endpointservice-triggerfromflow-req-003
	 */
	private function buildSyntheticRequest(array $parameters): IRequest {
		return new \OC\AppFramework\Http\Request(
			vars: [
				'method' => 'GET',
				'get' => $parameters,
				'params' => $parameters,
			],
			requestId: $this->requestId,
			config: $this->config,
		);

	}//end buildSyntheticRequest()

	/**
	 * Handles incoming requests to endpoints
	 *
	 * This method determines how to handle the request based on the endpoint configuration.
	 * It either routes to a schema within a register or proxies to an external source.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration to handle
	 * @param IRequest $request The incoming request object
	 * @param string $path The specific path or sub-route being requested
	 *
	 * @return Response Response containing the result
	 * @throws Exception When endpoint configuration is invalid
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function doHandleRequest(ObjectEntity $endpoint, IRequest $request, string $path): Response {
		$endpointData = $endpoint->getObject();
		$errors = $this->checkConditions(endpoint: $endpoint, request: $request);

		if ($errors !== []) {
			return new JSONResponse(['error' => 'The following parameters are not correctly set', 'fields' => $errors], 400);
		}

		// Execution-trace REQ-001: mint the traceId before any downstream
		// work begins — one of the four execution entry points.
		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: $endpoint->getUuid(), triggeredBy: 'http');

		try {
			$flowToken = new FlowToken(requestOriginal: $request, path: $path);

			// Process initial data.
			// $responseBody = $this->parseContent(
			// request: $request,
			//
			// );
			//
			// if ($responseBody == '') {
			// $responseBody = [];
			// }.
			$currentDate = (new DateTime())->format('c');
			// This is double becuase mapping needs it in body but other rules seek directly in data.
			// $incomingMethod = $request->getMethod();
			// $incomingHeaders = $this->getHeaders($request->server, true);
			// $incomingParams = array_merge($request->getParams(), $responseBody);
			//
			// $incomingData = [
			// 'method' => $incomingMethod,
			// 'headers' => $incomingHeaders,
			// 'params' => $incomingParams
			// ];
			// @todo: This should eventually be merged into the flow tokens.
			$data = [
				'utility' => [
					'currentDate' => $currentDate,
				],
				'parameters' => array_merge(
					$flowToken->getRequestOriginal()['parameters'],
					$this->getPathParameters(
						endpointArray: ($endpointData['endpointArray'] ?? []),
						path: $path
					)
				),
				'headers' => $flowToken->getRequestOriginal()['headers'],
				'path' => $flowToken->getRequestOriginal()['path'],
				'method' => $flowToken->getRequestOriginal()['method'],
				'body' => array_merge(
					[
						'_utility' => [
							'currentDate' => $currentDate,
						],
						'_parameters' => $flowToken->getRequestOriginal()['parameters'],
						'_headers' => $flowToken->getRequestOriginal()['headers'],
						'_path' => $flowToken->getRequestOriginal()['path'],
						'_method' => $flowToken->getRequestOriginal()['method'],
					],
					$flowToken->getRequestOriginal()['parameters']
				),
			];
			// Process rules before handling the request.
			$ruleResult = $this->processRules(
				endpoint: $endpoint,
				request: $request,
				data: $data,
				timing: 'before',
				flowToken: $flowToken,
				trace: $trace,
			);

			$response = $this->dispatchAfterBeforeRules(
				endpoint: $endpoint,
				request: $request,
				path: $path,
				flowToken: $flowToken,
				ruleResult: $ruleResult,
				enforceRateLimit: true,
				trace: $trace
			);

			$this->finalizeTrace(trace: $trace, response: $response);

			return $response;
		} catch (Exception $e) {
			// C3 fix: never disclose the stack trace in the response body.
			// This endpoint is @PublicPage — unauthenticated callers must not see internal file
			// paths or class names.  Log the full trace server-side for support lookup.
			$this->logger->error(
				'Error handling endpoint request: ' . $e->getMessage(),
				['exception' => $e]
			);

			$this->finalizeTrace(
				trace: $trace,
				response: null,
				error: [
					'message' => $e->getMessage(),
					'ruleType' => null,
					'ruleName' => null,
				]
			);

			return new JSONResponse(
				['error' => 'Internal server error'],
				400
			);
		}//end try
	}//end doHandleRequest()

	/**
	 * Persist the assembled `ExecutionTraceContext` for one completed
	 * execution (execution-trace REQ-004): status is derived from the final
	 * `Response` (or from `$error` on an uncaught exception, per
	 * `rule-pipeline` REQ-RULE-001's HTTP 500 path). Best-effort — a
	 * persistence failure MUST NOT fail the endpoint response it is
	 * observing.
	 *
	 * @param ExecutionTraceContext|null $trace The context to persist; a no-op when null (`executionTraceService`
	 *                                          unavailable, e.g. legacy test instantiation without it).
	 * @param Response|null $response The final response, when the execution completed without throwing.
	 * @param array|null $error The terminal error {message, ruleType, ruleName}, when set via the
	 *                          uncaught-exception path.
	 * @param boolean $resume Whether this finalizes an approval-resume continuation (design.md
	 *                        Decision 2) — updates the SAME trace instead of creating a new one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
	 */
	private function finalizeTrace(?ExecutionTraceContext $trace, ?Response $response, ?array $error = null, bool $resume = false): void {
		if ($trace === null || $this->executionTraceService === null) {
			return;
		}

		if ($error !== null) {
			$status = 'failed';
		} elseif ($response !== null) {
			$statusCode = $response->getStatus();
			if ($statusCode === 202) {
				$status = 'running';
			} elseif ($statusCode >= 200 && $statusCode < 300) {
				$status = 'success';
			} elseif ($statusCode >= 400) {
				$status = 'failed';
			} else {
				$status = 'short_circuited';
			}
		} else {
			$status = 'short_circuited';
		}

		try {
			$this->executionTraceService->persist(trace: $trace, status: $status, error: $error, resume: $resume);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'EndpointService: failed to persist execution_trace.',
				['traceId' => $trace->getTraceId(), 'exception' => $exception->getMessage()]
			);
		}

	}//end finalizeTrace()

	/**
	 * Continue an endpoint request after the `before`-phase rule pipeline
	 * has run (or been resumed past a suspended `approval` rule):
	 * short-circuit on an `error`-style rule result, enforce the inbound
	 * rate limit, then dispatch to the schema/target write or source proxy
	 * exactly as the tail of `doHandleRequest()` always has. Shared by
	 * `doHandleRequest()` (the original request) and `resumeFromApproval()`
	 * (the approver's own request) so suspension/resume needs no separate
	 * dispatch implementation (rule-pipeline REQ-RULE-008 Notes).
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration.
	 * @param IRequest $request The current request (approver's own request on resume).
	 * @param string $path The endpoint sub-path.
	 * @param FlowToken $flowToken The (possibly rehydrated) FlowToken.
	 * @param array|Response $ruleResult The before-phase `processRules()` result.
	 * @param boolean $enforceRateLimit Whether to (re-)apply the inbound rate limit —
	 *                                  false on resume, since the original request already
	 *                                  passed it before suspending (design.md
	 *                                  `resumeFromApproval` notes).
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded into the
	 *                                          `after`-phase `processRules()` call and the
	 *                                          source-proxy dispatch (execution-trace REQ-001).
	 * @param boolean $dryRun Whether write-shaped rule dispatch is suppressed
	 *                        (rule-pipeline REQ-RULE-011) — threaded into the
	 *                        `after`-phase `processRules()` call.
	 *
	 * @return Response
	 *
	 * @throws Exception When endpoint configuration is invalid.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#requirement-resume-on-approval-req-003
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-trace-step-emission-during-rule-pipeline-execution-req-rule-010
	 */
	private function dispatchAfterBeforeRules(
		ObjectEntity $endpoint,
		IRequest $request,
		string $path,
		FlowToken $flowToken,
		array|Response $ruleResult,
		bool $enforceRateLimit,
		?ExecutionTraceContext $trace = null,
		bool $dryRun = false,
	): Response {
		$endpointData = $endpoint->getObject();

		if ($ruleResult instanceof JSONResponse === true) {
			return $this->transformError(result: $ruleResult, request: $request);
		}

		if ($enforceRateLimit === true) {
			// Inbound consumer source-scope (REQ-CON-SCOPE-001). Runs AFTER
			// authentication resolved a consumer and BEFORE the rate limit, so a
			// caller outside the allowlist gets 403 rather than consuming (and
			// being told about) the consumer's rate-limit budget. Skipped on the
			// resume-from-approval path for the same reason the rate limit is:
			// the original request already passed this check before suspending.
			$scopeResponse = $this->enforceConsumerScope(request: $request);
			if ($scopeResponse !== null) {
				return $scopeResponse;
			}

			// Inbound per-consumer rate limiting + quota (consumer-rate-limiting).
			// Runs AFTER authentication has passed (the 'before' rule pipeline,
			// which includes the authentication rule, completed without a 401/403)
			// and BEFORE the endpoint target/schema dispatch (REQ-CON-RL-002). An
			// over-limit request short-circuits with 429 here; an under-limit
			// request records its RateLimit-* headers for the response wrapper.
			$rateLimitResponse = $this->enforceInboundRateLimit(request: $request, endpoint: $endpoint);
			if ($rateLimitResponse !== null) {
				return $rateLimitResponse;
			}
		}//end if

		// Update request data with rule processing results.
		$flowToken = $this->updateRequestWithRuleData(flowToken: $flowToken, ruleData: $ruleResult);

		// Check if endpoint connects to a schema.
		if (($endpointData['targetType'] ?? '') === 'register/schema') {
			// Handle CRUD operations via ObjectService.
			$result = $this->handleSchemaRequest(endpoint: $endpoint, flowToken: $flowToken, path: $path);

			// Process initial data.
			$data = [
				'utility' => [
					'currentDate' => (new DateTime())->format('c'),
				],
				'parameters' => $flowToken->getRequestAmended()['parameters'],
				'requestHeaders' => $flowToken->getRequestAmended()['headers'],
				'headers' => $flowToken->getResponseAmended()['headers'],
				'path' => $flowToken->getRequestAmended()['path'],
				'method' => $flowToken->getRequestAmended()['method'],
				'body' => $flowToken->getResponseOriginal()['data'],
			];

			$ruleResult = $this->processRules(
				endpoint: $endpoint,
				request: $request,
				data: $data,
				timing: 'after',
				objectId: $result->getData()['id'] ?? null,
				flowToken: $flowToken,
				trace: $trace,
				dryRun: $dryRun
			);

			if ($ruleResult instanceof Response === true && $ruleResult->getStatus() >= 200 && $ruleResult->getStatus() < 300) {
				return $ruleResult;
			}

			if ($ruleResult instanceof JSONResponse === true) {
				return $this->transformError(result: $ruleResult, request: $request);
			}

			if ($result->getStatus() !== 200 && $result->getStatus() !== 201) {
				return $this->transformError(result: $result, request: $request);
			}

			// Set the proper status code for the method.
			// @TODO: we might want an override from rule processing.
			switch ($flowToken->getRequestAmended()['method']) {
				case 'POST':
					$statusCode = Http::STATUS_CREATED;
					break;
				case 'DELETE':
					$statusCode = Http::STATUS_NO_CONTENT;
					break;
				case 'GET':
				case 'PUT':
				case 'PATCH':
				default:
					$statusCode = Http::STATUS_OK;
					break;
			}

			$configurations = $endpointData['configurations'] ?? [];
			if (isset($configurations['defaultStatusCode']) === true) {
				$statusCode = $configurations['defaultStatusCode'];
			}

			return new JSONResponse(data: $ruleResult['body'], statusCode: $statusCode, headers: $ruleResult['headers'] ?? []);
		}//end if

		// Check if endpoint connects to a source.
		if (($endpointData['targetType'] ?? '') === 'api') {
			// Proxy request to source via CallService.
			return $this->handleSourceRequest(endpoint: $endpoint, request: $request, path: $path, trace: $trace);
		}

		// Invalid endpoint configuration.
		throw new Exception('Endpoint must specify either a schema or source connection');
	}//end dispatchAfterBeforeRules()

	/**
	 * Resume an endpoint rule-pipeline run suspended by an `approval` rule,
	 * inside the approving user's own HTTP request (design.md Decision 3):
	 * continue the `before`-phase pipeline starting with the first rule
	 * whose `order` is strictly greater than `resumeAfterOrder`, then — once
	 * the resumed before-phase rules complete without a further
	 * short-circuit — dispatch exactly as an unsuspended request would
	 * (schema/target write, then `after`-phase rules).
	 *
	 * The inbound rate limit is NOT re-applied here: the original caller
	 * already passed it before the run suspended, and this request belongs
	 * to the approver, not a new inbound API consumer.
	 *
	 * @param ObjectEntity $endpoint The suspended endpoint.
	 * @param IRequest $request The approver's own request.
	 * @param FlowToken $flowToken The FlowToken rehydrated from the approval_request snapshot.
	 * @param integer $resumeAfterOrder The approval rule's `order` — resume continues strictly after
	 *                                  it.
	 * @param string $path The endpoint sub-path recorded at suspension time.
	 * @param ExecutionTraceContext|null $trace The execution trace context rehydrated from the
	 *                                          approval_request snapshot
	 *                                          (`ApprovalService::rehydrateTraceContext()`),
	 *                                          pre-loaded with the original `traceId` and
	 *                                          `before`-phase steps — null when the
	 *                                          suspended run was untraced. When non-null, the
	 *                                          resumed `after`-phase steps are appended to the
	 *                                          SAME trace and `execution_trace` is UPDATED
	 *                                          (not created), per design.md Decision 2.
	 *
	 * @return Response The resumed pipeline's final result.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#requirement-resume-on-approval-req-003
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
	 */
	public function resumeFromApproval(
		ObjectEntity $endpoint,
		IRequest $request,
		FlowToken $flowToken,
		int $resumeAfterOrder,
		string $path,
		?ExecutionTraceContext $trace = null,
	): Response {
		try {
			$data = [
				'utility' => [
					'currentDate' => (new DateTime())->format('c'),
				],
				'parameters' => $flowToken->getRequestAmended()['parameters'] ?? [],
				'headers' => $flowToken->getRequestAmended()['headers'] ?? [],
				'path' => $flowToken->getRequestAmended()['path'] ?? $path,
				'method' => $flowToken->getRequestAmended()['method'] ?? $request->getMethod(),
				'body' => $flowToken->getRequestAmended()['parameters'] ?? [],
			];

			$ruleResult = $this->processRules(
				endpoint: $endpoint,
				request: $request,
				data: $data,
				timing: 'before',
				flowToken: $flowToken,
				resumeAfterOrder: $resumeAfterOrder,
				trace: $trace
			);

			$response = $this->dispatchAfterBeforeRules(
				endpoint: $endpoint,
				request: $request,
				path: $path,
				flowToken: $flowToken,
				ruleResult: $ruleResult,
				enforceRateLimit: false,
				trace: $trace
			);

			$this->finalizeTrace(trace: $trace, response: $response, resume: true);

			return $response;
		} catch (Exception $e) {
			$this->logger->error(
				'Error resuming endpoint request after approval: ' . $e->getMessage(),
				['exception' => $e]
			);

			$this->finalizeTrace(
				trace: $trace,
				response: null,
				error: [
					'message' => $e->getMessage(),
					'ruleType' => null,
					'ruleName' => null,
				],
				resume: true
			);

			return new JSONResponse(['error' => 'Internal server error'], 500);
		}//end try

	}//end resumeFromApproval()

	/**
	 * Replay an `endpoint`-entryPoint execution_trace against the same
	 * endpoint, re-running the rule pipeline against the ORIGINAL request
	 * snapshot (`execution-trace` REQ-005/REQ-006, `rule-pipeline`
	 * REQ-RULE-011). Dispatched by `ExecutionTraceService::replay()` — never
	 * called directly from a controller.
	 *
	 * Dry-run (`$dryRun: true`, the default) suppresses write-shaped rule
	 * dispatch via `processRules(dryRun: true)`; a `mapping`/`extend_input`/
	 * `authentication`/`error` rule still executes for real, matching
	 * REQ-005's "best-effort pre-rule envelope" scenario. Note (design.md
	 * Decision 4's rejected-alternative discussion): this is a rule-level
	 * dry-run only — an endpoint whose OWN schema/source target write is not
	 * gated by a rule (e.g. a `register/schema` endpoint with no `save_object`
	 * rule) is NOT suppressed by `$dryRun`; a full transactional dry-run of
	 * the target dispatch itself was explicitly rejected as out of scope for
	 * this change (design.md Decision 4).
	 *
	 * @param ObjectEntity $endpoint The endpoint to replay against.
	 * @param array $requestSnapshot The original request's `FlowToken::getRequestOriginal()` shape
	 *                               (method/headers/parameters/path), read from the stored trace's
	 *                               first `rule` step input.
	 * @param ExecutionTraceContext $trace The NEW trace context this replay populates (already flagged
	 *                                     `isReplay: true`/`replayOf` by the caller).
	 * @param boolean $dryRun Whether to suppress write-shaped rule dispatch. Defaults to true.
	 *
	 * @return Response The replayed pipeline's final result.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-point-s-real-dispatch-path-req-006
	 */
	public function replay(ObjectEntity $endpoint, array $requestSnapshot, ExecutionTraceContext $trace, bool $dryRun = true): Response {
		try {
			$syntheticRequest = $this->buildSyntheticRequest(parameters: ($requestSnapshot['parameters'] ?? []));
			$path = (string)($requestSnapshot['path'] ?? '');
			$flowToken = new FlowToken(requestOriginal: $requestSnapshot, path: $path);

			$data = [
				'utility' => [
					'currentDate' => (new DateTime())->format('c'),
				],
				'parameters' => ($requestSnapshot['parameters'] ?? []),
				'headers' => ($requestSnapshot['headers'] ?? []),
				'path' => $path,
				'method' => ($requestSnapshot['method'] ?? 'GET'),
				'body' => ($requestSnapshot['parameters'] ?? []),
			];

			$ruleResult = $this->processRules(
				endpoint: $endpoint,
				request: $syntheticRequest,
				data: $data,
				timing: 'before',
				flowToken: $flowToken,
				trace: $trace,
				dryRun: $dryRun
			);

			return $this->dispatchAfterBeforeRules(
				endpoint: $endpoint,
				request: $syntheticRequest,
				path: $path,
				flowToken: $flowToken,
				ruleResult: $ruleResult,
				enforceRateLimit: false,
				trace: $trace,
				dryRun: $dryRun
			);
		} catch (Exception $e) {
			$this->logger->error(
				'Error replaying endpoint execution_trace: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(['error' => 'Internal server error'], 500);
		}//end try

	}//end replay()

	/**
	 * Enforce the resolved consumer's inbound rate limit and quota.
	 *
	 * When the dispatched endpoint belongs to an `api_product`, this first
	 * resolves the caller's `active` `api_product_subscription` to that
	 * product and — when found — enforces the SUBSCRIPTION'S tier
	 * `rateLimit`/`quota` instead of the consumer's own, keyed separately
	 * so tier counters can never share a bucket with the consumer's plain
	 * per-endpoint counters (`REQ-APG-005`, `consumer-management`
	 * `REQ-CON-SUB-002`, design.md Decision 5). A product-attached endpoint
	 * whose caller has no `active` subscription is rejected outright with
	 * HTTP 403 — "subscribe" is opt-in access, not a silent fallback to the
	 * consumer's own limit (`REQ-APG-004`, design.md Decision 2). This is a
	 * deliberate reconciliation of two design.md passages that read as
	 * contradictory in isolation (Decision 5's "no active subscription ->
	 * behaviour is byte-for-byte unchanged" vs. Decision 2's explicit 403):
	 * the concrete Given/When/Then scenarios under REQ-APG-004 and the
	 * dedicated test-plan.md TC-10 are unambiguous that "no active
	 * subscription to a PRODUCT endpoint" blocks with 403; "byte-for-byte
	 * unchanged" is read as covering only the "endpoint is not part of any
	 * product" case and the edge case where an active subscription's tier
	 * no longer resolves to a policy (e.g. the tier was later removed from
	 * the product) — both of which fall through to the same
	 * Consumer-level path below, untouched.
	 *
	 * Keys the plain (non-product) limiter on the resolved consumer's uuid,
	 * or — when the consumer authenticates anonymously (`authorizationType:
	 * none`) — on the client IP so distinct anonymous callers get separate
	 * buckets. When no consumer was resolved (apikey/basic/oauth
	 * authenticate a Nextcloud user, or the endpoint has no authentication
	 * rule), there is no per-consumer limit and this returns null
	 * (unlimited) — unchanged from before this change, and deliberately not
	 * extended to the product-403 gate: a product-attached endpoint that
	 * resolves no consumer identity at all has no scenario coverage in this
	 * change's spec deltas, so its behaviour is left exactly as before
	 * (design.md Risks / "no regression" default).
	 *
	 * @param IRequest $request The incoming request.
	 * @param ObjectEntity $endpoint The dispatched endpoint (used to resolve its api_product, if any).
	 *
	 * @return JSONResponse|null A 429/403 response when blocked, or null when the request may proceed.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 * @spec openspec/specs/consumer-management/spec.md#requirement-per-product-tier-policy-takes-precedence-over-the-consumer-level-rate-limit-req-con-sub-002
	 */
	private function enforceInboundRateLimit(IRequest $request, ObjectEntity $endpoint): ?JSONResponse {
		$consumer = $this->authorizationService->getResolvedConsumer();
		if ($consumer === null) {
			// No per-consumer identity resolved — nothing to throttle.
			return null;
		}

		$consumerData = $consumer->getObject();
		$consumerKey = (string)($consumer->getUuid() ?? ($consumerData['uuid'] ?? 'unknown'));

		$product = $this->resolveProductForEndpoint(endpoint: $endpoint);
		if ($product !== null) {
			$subscription = $this->resolveActiveSubscription(
				consumerUuid: $consumerKey,
				productUuid: (string)$product->getUuid()
			);

			if ($subscription === null) {
				// REQ-APG-004 / design.md Decision 2: pending/rejected/revoked
				// (or entirely absent) subscription blocks access outright.
				return new JSONResponse(
					[
						'error' => 'subscription_required',
						'message' => 'No active subscription to this API product',
					],
					Http::STATUS_FORBIDDEN
				);
			}

			$tierPolicy = $this->resolveTierPolicy(product: $product, subscription: $subscription);
			if ($tierPolicy !== null) {
				$key = 'product:' . ((string)$product->getUuid()) . ':consumer:' . $consumerKey;

				return $this->applyRateLimitDecision(
					key: $key,
					rateLimit: $tierPolicy['rateLimit'],
					quota: $tierPolicy['quota'],
					consumer: $consumer
				);
			}
		}//end if

		// Fallback: the endpoint is not part of any api_product, or an
		// active subscription exists but its tier no longer resolves to a
		// policy — today's Consumer-level rateLimit/quota (REQ-CON-RL-002),
		// unchanged.
		$authType = ($consumerData['authorizationType'] ?? '');
		if ($authType === 'none' || $authType === '') {
			$key = 'ip:' . $request->getRemoteAddress();
		} else {
			$key = 'consumer:' . $consumerKey;
		}

		return $this->applyRateLimitDecision(
			key: $key,
			rateLimit: ($consumerData['rateLimit'] ?? null),
			quota: ($consumerData['quota'] ?? null),
			consumer: $consumer
		);

	}//end enforceInboundRateLimit()

	/**
	 * Reject a request whose source falls outside the resolved consumer's allowlist.
	 *
	 * The `consumer` schema advertises `ips` ("Allowed source IP addresses") and
	 * `domains` ("Allowed source domains"); this is the single point that
	 * enforces them. Fails closed — an unlisted source receives HTTP 403.
	 * A consumer with neither list configured is unrestricted, preserving the
	 * behaviour of every consumer that predates this control.
	 *
	 * When no consumer was resolved (rule-inline apiKey / basic / oauth
	 * authenticate a Nextcloud user rather than a consumer) there is no
	 * consumer allowlist to apply and the request proceeds.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return JSONResponse|null A 403 response when the source is not allowed, null otherwise.
	 *
	 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
	 */
	private function enforceConsumerScope(IRequest $request): ?JSONResponse {
		$consumer = $this->authorizationService->getResolvedConsumer();
		if ($consumer === null) {
			return null;
		}

		if ($this->consumerScopeService->isAllowed(consumer: $consumer, request: $request) === true) {
			return null;
		}

		return new JSONResponse(
			[
				'error' => 'source_not_allowed',
				'message' => 'Request source is not in this consumer\'s allowed domains or IP addresses',
			],
			Http::STATUS_FORBIDDEN
		);

	}//end enforceConsumerScope()

	/**
	 * Shared rate-limit/quota evaluation tail: unlimited short-circuit,
	 * `InboundRateLimitService::enforce()` call, RateLimit-* header stash,
	 * and 429 response construction. Extracted so both the plain
	 * Consumer-level path and the product-tier path (`REQ-APG-005`) share
	 * one implementation of `InboundRateLimitService::enforce()` unchanged
	 * (design.md Decision 5 — the service itself is never forked).
	 *
	 * @param string $key The cache key to enforce against (already namespaced by the caller).
	 * @param array|null $rateLimit `{requestsPerWindow:int, windowSeconds:int}` or null (unlimited).
	 * @param array|null $quota `{limit:int, period:"hour"|"day"|"month"}` or null (unlimited).
	 * @param ObjectEntity $consumer The resolved consumer, recorded on an over-limit 429 (REQ-CON-RL-004).
	 *
	 * @return JSONResponse|null A 429 response when throttled, or null when the request may proceed.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 */
	private function applyRateLimitDecision(string $key, ?array $rateLimit, ?array $quota, ObjectEntity $consumer): ?JSONResponse {
		if (is_array($rateLimit) === false) {
			$rateLimit = null;
		}

		if (is_array($quota) === false) {
			$quota = null;
		}

		if ($rateLimit === null && $quota === null) {
			// Unlimited — backward compatible with every existing consumer.
			return null;
		}

		$decision = $this->rateLimitService->enforce(
			consumerKey: $key,
			rateLimit: $rateLimit,
			quota: $quota
		);

		$this->rateLimitHeaders = $decision->toHeaders();

		if ($decision->allowed === false) {
			$this->recordInboundThrottle(consumer: $consumer, decision: $decision);
			return new JSONResponse(
				[
					'error' => 'rate_limited',
					'message' => 'Too Many Requests',
					'reason' => $decision->reason,
				],
				Http::STATUS_TOO_MANY_REQUESTS,
				$decision->toHeaders()
			);
		}

		return null;
	}//end applyRateLimitDecision()

	/**
	 * Resolve the `api_product` (if any) whose `endpoints` array contains
	 * the given endpoint's uuid. Bounded to the same 500-row `findAll()`
	 * cap used elsewhere for small, admin-curated collections
	 * (`ApprovalService::sweepExpired()`/`listFor()`); array-membership
	 * filtering happens in PHP after the fetch — there is no native
	 * array-contains filter operator on `ORObjectService::findAll()`
	 * (the same pattern `ConfigurationService::findByConfiguration()`
	 * already uses for an analogous array-of-ids lookup).
	 *
	 * When an endpoint's uuid is (unusually) referenced by more than one
	 * `api_product` row, the first match is used — normal usage keeps each
	 * product version's `endpoints` set disjoint from every other version's
	 * (design.md Decision 1), so this is not expected to occur in practice.
	 *
	 * @param ObjectEntity $endpoint The dispatched endpoint.
	 *
	 * @return ObjectEntity|null The grouping api_product, or null when the endpoint belongs to none.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001
	 */
	public function resolveProductForEndpoint(ObjectEntity $endpoint): ?ObjectEntity {
		$endpointUuid = (string)$endpoint->getUuid();
		if ($endpointUuid === '') {
			return null;
		}

		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => 'openconnector',
						'schema' => 'api_product',
					],
					'limit' => 500,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('openconnector: failed to resolve api_product for endpoint: ' . $e->getMessage());
			return null;
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return null;
		}

		foreach ($results as $product) {
			if (($product instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $product->getObject();
			$endpoints = ($data['endpoints'] ?? []);
			if (is_array($endpoints) === true && in_array($endpointUuid, $endpoints, true) === true) {
				return $product;
			}
		}

		return null;
	}//end resolveProductForEndpoint()

	/**
	 * Resolve a consumer's `active` `api_product_subscription` to a given
	 * product, or null when it has none (pending_approval/rejected/revoked/
	 * absent all collapse to the same null — REQ-APG-004).
	 *
	 * @param string $consumerUuid The resolved consumer's uuid.
	 * @param string $productUuid The api_product's uuid.
	 *
	 * @return ObjectEntity|null The active subscription, or null.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 */
	private function resolveActiveSubscription(string $consumerUuid, string $productUuid): ?ObjectEntity {
		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => 'openconnector',
						'schema' => 'api_product_subscription',
						'consumer' => $consumerUuid,
						'product' => $productUuid,
						'status' => 'active',
					],
					'limit' => 5,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('openconnector: failed to resolve api_product_subscription: ' . $e->getMessage());
			return null;
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = reset($results);
		if ($first instanceof ObjectEntity) {
			return $first;
		}

		return null;
	}//end resolveActiveSubscription()

	/**
	 * Resolve a subscription's tier to its `rateLimit`/`quota` policy on
	 * the owning product, or null when the tier no longer exists on the
	 * product (e.g. removed after the subscription was created).
	 *
	 * @param ObjectEntity $product The api_product.
	 * @param ObjectEntity $subscription The active api_product_subscription.
	 *
	 * @return array{rateLimit: array|null, quota: array|null}|null
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 */
	private function resolveTierPolicy(ObjectEntity $product, ObjectEntity $subscription): ?array {
		$tiers = ($product->getObject()['tiers'] ?? []);
		$tierName = (string)($subscription->getObject()['tier'] ?? '');

		if (is_array($tiers) === false || $tierName === ''
			|| isset($tiers[$tierName]) === false || is_array($tiers[$tierName]) === false
		) {
			return null;
		}

		$tier = $tiers[$tierName];
		$rateLimit = ($tier['rateLimit'] ?? null);
		$quota = ($tier['quota'] ?? null);

		if (is_array($rateLimit) === false) {
			$rateLimit = null;
		}

		if (is_array($quota) === false) {
			$quota = null;
		}

		return [
			'rateLimit' => $rateLimit,
			'quota' => $quota,
		];

	}//end resolveTierPolicy()

	/**
	 * Build the RFC 8594 `Deprecation`/`Sunset` header pair for a product's
	 * dispatched endpoint, or `[]` when the product is not `deprecated`
	 * (REQ-APG-006/REQ-EP-008).
	 *
	 * @param ObjectEntity $product The endpoint's grouping api_product.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006
	 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008
	 */
	public function buildDeprecationHeaders(ObjectEntity $product): array {
		$data = $product->getObject();
		if (($data['status'] ?? '') !== 'deprecated') {
			return [];
		}

		$headers = ['Deprecation' => 'true'];
		$sunsetDate = ($data['sunsetDate'] ?? null);

		if (is_string($sunsetDate) === true && $sunsetDate !== '') {
			try {
				$sunset = new DateTime($sunsetDate);
				$sunset->setTimezone(new \DateTimeZone('UTC'));
				$headers['Sunset'] = $sunset->format('D, d M Y H:i:s') . ' GMT';
			} catch (\Throwable $e) {
				$this->logger->warning('openconnector: invalid api_product.sunsetDate: ' . $e->getMessage());
			}
		}

		return $headers;
	}//end buildDeprecationHeaders()

	/**
	 * Persist a `direction: inbound` `call_log` row for a request dispatched
	 * through a product-attached endpoint, on every outcome (success or
	 * error) — extends the existing 429-only inbound logging
	 * (`recordInboundThrottle()`, `REQ-CON-RL-004`) to every outcome, but
	 * scoped to product-attached endpoints only (`REQ-EP-009`). Best-effort:
	 * a logging failure never blocks the response.
	 *
	 * @param ObjectEntity $endpoint The dispatched endpoint.
	 * @param ObjectEntity $product The endpoint's grouping api_product.
	 * @param integer $statusCode The final HTTP status code served.
	 * @param float $durationMs Wall-clock duration in milliseconds.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009
	 */
	public function recordInboundCallLog(ObjectEntity $endpoint, ObjectEntity $product, int $statusCode, float $durationMs): void {
		try {
			$this->orObjectService->saveObject(
				object: [
					'statusCode' => $statusCode,
					'statusMessage' => 'Inbound API product request',
					'direction' => 'inbound',
					'product' => (string)$product->getUuid(),
					'endpoint' => (string)$endpoint->getUuid(),
					'responseTime' => (int)round($durationMs),
					'created' => (new DateTime())->format('c'),
				],
				register: 'openconnector',
				schema: 'call_log'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'openconnector: failed to record inbound api-product call_log: ' . $e->getMessage()
			);
		}

	}//end recordInboundCallLog()

	/**
	 * Record an inbound rate-limit/quota 429 on the CallLog observability surface.
	 *
	 * Persists an `inbound`-direction call_log with statusCode 429 so the
	 * `openconnector_calls_total{status="429",direction="inbound"}` metric
	 * distinguishes consumer throttling from outbound source backoff
	 * (REQ-CON-RL-004). Best-effort: a logging failure never blocks the 429.
	 *
	 * @param ObjectEntity $consumer The throttled consumer.
	 * @param RateLimitDecision $decision The rejection decision.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Rate-limit rejections are observable (REQ-CON-RL-004)
	 */
	private function recordInboundThrottle(ObjectEntity $consumer, RateLimitDecision $decision): void {
		try {
			$this->orObjectService->saveObject(
				object: [
					'statusCode' => Http::STATUS_TOO_MANY_REQUESTS,
					'statusMessage' => 'Inbound rate limit exceeded (' . ((string)$decision->reason) . ')',
					'direction' => 'inbound',
					'created' => (new DateTime())->format('c'),
				],
				register: 'openconnector',
				schema: 'call_log'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'openconnector: failed to record inbound rate-limit call_log: ' . $e->getMessage()
			);
		}

	}//end recordInboundThrottle()

	/**
	 * Parses a path to get the parameters in a path.
	 *
	 * @param array $endpointArray The endpoint array from an endpoint object.
	 * @param string $path The path called by the client.
	 *
	 * @return array The parsed path with the fields having the correct name.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getPathParameters(array $endpointArray, string $path): array {
		$pathParts = explode(separator: '/', string: $path);

		$endpointArrayNormalized = array_map(
			function ($item) {
				return str_replace(
					search: ['{{', '{{ ', '}}', '}}'],
					replace: '',
					subject: $item
				);
			},
			$endpointArray
		);

		// Normalise both sides to the SHORTER length so array_combine never
		// throws even when the request path doesn't match the endpoint
		// pattern (#1015 follow-up — the existing single array_pop fallback
		// failed when the size delta was > 1, e.g. EndpointService::419 in
		// EndpointServiceTest::testHandleRequestReturnsAResponseWhenTheTargetObjectIsMissing).
		$keyCount = count($endpointArrayNormalized);
		$valueCount = count($pathParts);
		if ($keyCount > $valueCount) {
			$endpointArrayNormalized = array_slice($endpointArrayNormalized, 0, $valueCount);
		} elseif ($valueCount > $keyCount) {
			$pathParts = array_slice($pathParts, 0, $keyCount);
		}

		if (count($endpointArrayNormalized) === 0) {
			return [];
		}

		return array_combine(
			keys: $endpointArrayNormalized,
			values: $pathParts
		);
	}//end getPathParameters()

	/**
	 * Replaces internal pointers with urls and ids by endpoint urls.
	 *
	 * @param QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper The mapper used to find objects.
	 * @param ObjectEntity|null $object The object to substitute pointers in.
	 * @param array $serializedObject The serialized object (if the object is not available).
	 * @param array $extend Optional extend spec controlling which refs are inlined.
	 *
	 * @return array|null The serialized object including substituted pointers.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function replaceInternalReferences(
		QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper,
		?ObjectEntity $object = null,
		array $serializedObject = [],
		array $extend = [],
	): array {
		if ($serializedObject === [] && $object !== null) {
			$serializedObject = $object->jsonSerialize();
		} else {
			$this->objectService->getOpenRegisters()->clearCurrents();
			$object = $mapper->find($serializedObject['id']);
		}

		$uses = (new Dot($object->jsonSerialize()))->flatten();
		// If(isset($serializedObject) === true && !empty($serializedObject['@self']['relations'])) {
		// $uses = $serializedObject['@self']['relations'];
		// }.
		$useUrls = [];

		$uuidToUrlMap = [];
		// Initiate schemaMapper here once for performance.
		$schemaMapper = $this->containerInterface->get('OCA\OpenRegister\Db\SchemaMapper');
		$schema = $schemaMapper->find($object->getSchema());

		// Find property names that are uris.
		$validUriProperties = [];
		foreach ($schema->getProperties() as $propertyName => $property) {
			if (isset($property['objectConfiguration']['handling']) === true && $property['objectConfiguration']['handling'] === 'uri') {
				$validUriProperties[] = $propertyName;
			}

			if (isset($property['format']) === true && $property['format'] === 'uri') {
				$validUriProperties[] = $propertyName;
			}
		}

		foreach ($uses as $key => $use) {
			$baseKey = explode('.', $key, 2)[0];
			// Skip if the key (or its base form) is not in the valid URI properties.
			if (in_array(needle: $baseKey, haystack: $validUriProperties) === false) {
				continue;
			}

			if (empty($use) === true) {
				continue;
			}

			if (Uuid::isValid(uuid: $use) === true) {
				$useId = $use;
			} elseif (str_contains(haystack: $use, needle: 'localhost') === true
				|| str_contains(haystack: $use, needle: 'nextcloud.local') === true
				|| str_contains(haystack: $use, needle: $this->urlGenerator->getBaseUrl()) === true
			) {
				$explodedUrl = explode(separator: '/', string: $use);
				$useId = end($explodedUrl);
			} else {
				unset($uses[$key]);
				continue;
			}

			try {
				$generatedUrl = $this->generateEndpointUrl(id: $useId, parentIds: [$object->getUuid()], schemaMapper: $schemaMapper);
				$uuidToUrlMap[$useId] = $generatedUrl;
				$useUrls[] = $generatedUrl;
			} catch (Exception $exception) {
				continue;
			}
		}//end foreach

		// @TODO: correct rewriting self url. This has to be fixed with issue CONNECTOR-314.
		// Add self object URI mapping.
		// $uuidToUrlMap[$object->getUuid()] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper);.
		$uuidToUrlMap[$object->getUri()] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper);

		// @TODO: temporary fix for download endpoints. This has to be fixed with issue CONNECTOR-314.
		$uuidToUrlMap[$object->getUri() . '/download'] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper) . '/download';

		// Replace UUIDs in serializedObject recursively.
		$serializedObject = $this->replaceUuidsInArray(
			data: $serializedObject,
			uuidToUrlMap: $uuidToUrlMap,
			extend: $this->reduceExtendKeys(extend: $extend)
		);

		return $serializedObject;
	}//end replaceInternalReferences()

	/**
	 * Create a reduced list of extend keys and extends for checking purposes
	 *
	 * @param array $extend The original extend array
	 *
	 * @return array The reduced extend array
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function reduceExtendKeys(array $extend): array {
		if (empty($extend) === true) {
			return $extend;
		}

		$reducedKeys = [];

		foreach ($extend as $key => $value) {
			// Normalize input in case it's a simple array: ['something.subthing'].
			if (is_int($key) === true) {
				$actualValue = $value;
			} else {
				$actualValue = $key;
			}

			if (str_contains($actualValue, '.') === false) {
				$reducedKeys[] = $actualValue;
				continue;
			}

			[$prefix, $newKey] = explode('.', $actualValue, 2);

			if (in_array($prefix, $extend, true) === true) {
				$reducedKeys[] = $prefix . '.' . $newKey;
				continue;
			}

			$reducedKeys[] = $actualValue;
		}//end foreach

		$dot = new Dot([], parse: true);
		foreach ($reducedKeys as $path) {
			$dot->set($path, true);
			// True is a safe placeholder for Dot to serialize.
		}

		return $dot->jsonSerialize();
	}//end reduceExtendKeys()

	/**
	 * Recursively replaces UUIDs in an array with their corresponding URLs.
	 *
	 * This function traverses the given array and replaces any UUID values found in the
	 * mapping array ($uuidToUrlMap) with their associated URLs. It ensures that 'id' and 'uuid'
	 * fields remain unchanged.
	 *
	 * @param array $data The input array that may contain UUIDs.
	 * @param array $uuidToUrlMap An associative array mapping UUIDs to URLs.
	 * @param bool|null $isRelatedObject Are we currently iterating through a related object.
	 * @param array $extend Optional extend specification controlling which keys are inlined.
	 *
	 * @return array The modified array with UUIDs replaced by URLs.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function replaceUuidsInArray(array $data, array $uuidToUrlMap, ?bool $isRelatedObject = false, array $extend = []): array {
		foreach ($data as $key => $value) {
			// Don't check @self.
			if ($key === '@self') {
				continue;
			}

			// If in array of multiple objects and has id.
			if (is_array($value) === true
				&& isset($value['id']) === true
				&& isset($uuidToUrlMap[$value['id']]) === true
				&& key_exists(key: $key, array: $extend) === false
			) {
				$data[$key] = $uuidToUrlMap[$value['id']];
				continue;
			}

			// If related object and has id.
			if ($isRelatedObject === true
				&& $key === 'id'
				&& isset($uuidToUrlMap[$value]) === true
				&& key_exists(key: $key, array: $extend) === false
			) {
				$data[$key] = $uuidToUrlMap[$value];
				continue;
			}

			// Never replace 'id' or 'uuid' fields but only in previous checks.
			if ($key === 'id' || $key === 'uuid') {
				continue;
			}

			if (is_array($value) === true && array_is_list($value) === true && isset($extend[$key]) === true) {
				$extend[$key] = array_fill(0, count($value), $extend[$key]);
			}

			if (is_array($value) === true && empty($value) === false) {
				if (isset($extend[$key]) === true && is_array($extend[$key]) === true) {
					$nestedExtend = $extend[$key];
				} else {
					$nestedExtend = $extend;
				}

				$data[$key] = $this->replaceUuidsInArray(
					data: $value,
					uuidToUrlMap: $uuidToUrlMap,
					isRelatedObject: true,
					extend: $nestedExtend
				);
			} elseif (is_string($value) === true && isset($uuidToUrlMap[$value]) === true) {
				$data[$key] = $uuidToUrlMap[$value];
			}
		}//end foreach

		return $data;
	}//end replaceUuidsInArray()

	/**
	 * Inverse of replaceInternalReferences, rewriting external references to internal references for query parameters.
	 *
	 * @param array $parameters The incoming request parameters.
	 * @param ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper The ObjectService containing the request schema.
	 *
	 * @return array The updated request parameters.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function rewriteExternalReferences(array $parameters, ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper): array {
		$schemaMapper = $this->containerInterface->get('OCA\OpenRegister\Db\SchemaMapper');
		$schema = $schemaMapper->find($mapper->getSchema());

		$rewriteParameters = array_intersect(array_keys($parameters), array_keys($schema->getProperties()));

		foreach ($rewriteParameters as $rewriteParameter) {
			if (is_array($parameters[$rewriteParameter]) === true) {
				// @TODO: this is a dirty hotfix, here we should run through values
				continue;
			}

			if (filter_var($parameters[$rewriteParameter], FILTER_VALIDATE_URL) === false
				|| in_array(parse_url($parameters[$rewriteParameter], PHP_URL_HOST), $this->config->getSystemValue('trusted_domains')) === false
			) {
				continue;
			}

			$parsedPath = parse_url($parameters[$rewriteParameter], PHP_URL_PATH);
			$parsedPath = substr($parsedPath, 33);
			$epMatches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => 'openconnector',
						'schema' => 'endpoint',
						'endpointRegex' => $parsedPath,
						'method' => 'GET',
					],
				]
			);
			$epEntities = $epMatches['results'] ?? $epMatches;

			if (count($epEntities) < 1) {
				continue;
			}

			$epEntity = array_shift($epEntities);
			$epData = $epEntity->getObject();
			$pathArray = $this->getPathParameters(endpointArray: ($epData['endpointArray'] ?? []), path: $parsedPath);
			$parameters[$rewriteParameter] = [$parameters[$rewriteParameter], end($pathArray)];
		}//end foreach

		return $parameters;
	}//end rewriteExternalReferences()

	/**
	 * Fetch objects for the endpoint.
	 *
	 * @param ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper The mapper for the object type.
	 * @param array $parameters The parameters from the request.
	 * @param array $pathParams The parameters in the path.
	 * @param int $status The HTTP status to return.
	 *
	 * @return Entity|array The object(s) confirming to the request.
	 *
	 * @throws Exception
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getObjects(
		ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper,
		array $parameters,
		array $pathParams,
		int &$status = 200,
	): Entity|array {
		if (isset($pathParams['id']) === true && $pathParams['id'] === end($pathParams)) {
			try {
				$serializedObject = $mapper->find(
					$pathParams['id'],
					extend: ($parameters['extend'] ?? $parameters['_extend'] ?? null)
				)->jsonSerialize();
			} catch (DoesNotExistException $e) {
				$status = 404;
				return ['error' => 'not found', 'message' => "the object with id {$pathParams['id']} does not exist"];
			}

			$result = $this->replaceInternalReferences(
				mapper: $mapper,
				serializedObject: $serializedObject,
				extend: $parameters['extend'] ?? $parameters['_extend'] ?? []
			);

			return $result;
		} elseif (isset($pathParams['id']) === true) {
			// Set the array pointer to the location of the id, so we can fetch the parameters further down the line in order.
			while (prev($pathParams) !== $pathParams['id']) {
			}

			$property = next($pathParams);

			if (next($pathParams) !== false) {
				$id = pos($pathParams);
			}

			$main = $mapper->find($pathParams['id'])->getObject();

			if (isset($main[$property]) === false) {
				return $this->replaceInternalReferences(mapper: $mapper, object: $mapper->find($pathParams['id']));
			}

			$ids = $main[$property];

			if (empty($ids) === true) {
				$returnArray = [
					'count' => 0,
					'results' => [],
				];

				return $returnArray;
			}

			if (isset($id) === true && in_array(needle: $id, haystack: $ids) === true) {
				$object = $mapper->find($id);

				return $this->replaceInternalReferences(mapper: $mapper, object: $object);
			} elseif (isset($id) === true) {
				$status = 404;
				return ['error' => 'not found', 'message' => "the subobject with id $id does not exist"];
			}

			$results = $mapper->findAll(['ids' => $ids]);
			foreach ($results as $key => $result) {
				$results[$key] = $this->replaceInternalReferences(mapper: $mapper, object: $result);
			}

			$returnArray = [
				'count' => count($results),
				'results' => $results,
			];

			return $returnArray;
		}//end if

		$parameters = $this->rewriteExternalReferences(parameters: $parameters, mapper: $mapper);

		if (isset($parameters['_limit']) === false && isset($parameters['limit']) === false) {
			$parameters['_limit'] = 30;
		}

		$result = $mapper->findAllPaginated(requestParams: $parameters);
		$promises = [];

		foreach ($result['results'] as $index => $object) {
			$promises[$index] = new Promise(
				function ($resolve, $reject) use ($object, $mapper) {
					try {
						$updatedObject = $this->replaceInternalReferences(mapper: $mapper, serializedObject: $object->jsonSerialize());
						$resolve($updatedObject);
					} catch (\Throwable $e) {
						$reject($e);
					}
				}
			);
		}

		$result['results'] = await(all($promises));

		$returnArray = [
			'count' => $result['total'],
		];

		if ($result['page'] < $result['pages']) {
			$parameters['page'] = $result['page'] + 1;
			$parameters['_path'] = implode('/', $pathParams);

			$returnArray['next'] = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(
					routeName: 'openconnector.endpoints.handlePathRead',
					arguments: $parameters
				)
			);
		}

		if ($result['page'] > 1) {
			$parameters['page'] = $result['page'] - 1;
			$parameters['_path'] = implode('/', $pathParams);

			$returnArray['previous'] = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(
					routeName: 'openconnector.endpoints.handlePathRead',
					arguments: $parameters
				)
			);
		}

		$returnArray['results'] = $result['results'];

		return $returnArray;
	}//end getObjects()

	/**
	 * Handles requests for schema-based endpoints.
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration.
	 * @param FlowToken $flowToken The current flow token (passed by reference for amendment).
	 * @param string $path The path called by the client.
	 *
	 * @return JSONResponse
	 *
	 * @throws DoesNotExistException|LoaderError|MultipleObjectsReturnedException|SyntaxError
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function handleSchemaRequest(ObjectEntity $endpoint, FlowToken &$flowToken, string $path): JSONResponse {
		$endpointData = $endpoint->getObject();
		// @TODO: CONVERT TO FLOWTOKENS
		// Get request method
		$method = $flowToken->getRequestAmended()['method'];
		$target = explode('/', $endpointData['targetId'] ?? '');

		$register = $target[0];
		$schema = $target[1];

		$mapper = $this->objectService->getMapper(schema: (int)$schema, register: (int)$register);

		$parameters = $flowToken->getRequestAmended()['parameters'];

		if (($endpointData['inputMapping'] ?? null) !== null) {
			$inputMapping = $this->mappingService->getMapping($endpointData['inputMapping']);
			$parameters = $this->mappingService->executeMapping(mapping: $inputMapping, input: $parameters);
		}

		$pathParams = $this->getPathParameters(endpointArray: ($endpointData['endpointArray'] ?? []), path: $path);

		if (isset($pathParams['id']) === true) {
			$parameters['id'] = $pathParams['id'];
		}

		foreach ($this::UNSET_PARAMETERS as $parameter) {
			unset($parameters[$parameter]);
		}

		$status = 200;

		$headers = [];
		if (isset($flowToken->getRequestAmended()['headers']['Accept-Crs']) === true
			&& $flowToken->getRequestAmended()['headers']['Accept-Crs'] !== ''
		) {
			$headers['Content-Crs'] = $flowToken->getRequestAmended()['headers']['Accept-Crs'];
		}

		// Route to appropriate ObjectService method based on HTTP method.
		try {
			switch ($method) {
				case 'GET':
					$expand = [];
					if (($endpointData['vngFilterTranslation'] ?? false) === true) {
						$expandParam = $parameters['expand'] ?? '';
						if ($expandParam !== '') {
							$expand = explode(',', (string)$expandParam);
						}

						unset($parameters['expand']);

						$lookupFilters = array_filter($parameters, fn ($key) => str_starts_with($key, '_') === false, ARRAY_FILTER_USE_KEY);
						$systemFilters = array_diff_key($parameters, $lookupFilters);
						$parameters = array_merge($systemFilters, $this->mappingService->translateVngFilterOperators(filters: $lookupFilters));
					}

					$objects = $this->getObjects(mapper: $mapper, parameters: $parameters, pathParams: $pathParams, status: $status);
					if ($expand !== [] && isset($objects['results']) === true && is_array($objects['results']) === true) {
						$objects['results'] = array_map(
							fn (array $result) => $this->mappingService->expandRelations(data: $result, expand: $expand),
							$objects['results']
						);
					} elseif ($expand !== [] && is_array($objects) === true && isset($objects['error']) === false) {
						$objects = $this->mappingService->expandRelations(data: $objects, expand: $expand);
					}

					$response = new JSONResponse(
						$objects,
						statusCode: $status,
						headers: $headers
					);
					$flowToken->setResponseOriginal($response);
					$flowToken->setResponseAmended($flowToken->getResponseOriginal());
					return $response;
				case 'POST':
					$response = new JSONResponse(
						$this->replaceInternalReferences(
							mapper: $mapper,
							serializedObject: $mapper->createFromArray(object: $parameters)->jsonSerialize()
						)
					);
					$flowToken->setResponseOriginal($response);
					$flowToken->setResponseAmended($flowToken->getResponseOriginal());
					return $response;
				case 'PUT':
					if (($endpointData['putPatchSemantics'] ?? false) === true) {
						$missingFields = $this->checkPutMandatoryFields(parameters: $flowToken->getRequestAmended()['parameters'], mapper: $mapper);
						if ($missingFields !== []) {
							$response = new JSONResponse(
								data: [
									'error' => 'PUT requires all mandatory fields',
									'fields' => $missingFields,
								],
								statusCode: 400
							);
							$flowToken->setResponseOriginal($response);
							$flowToken->setResponseAmended($flowToken->getResponseOriginal());
							return $response;
						}
					}

					$putUpdated = $mapper->updateFromArray(
						$parameters['id'],
						$flowToken->getRequestAmended()['parameters'],
						true,
						false
					);
					$response = new JSONResponse(
						$this->replaceInternalReferences(
							mapper: $mapper,
							serializedObject: $putUpdated->jsonSerialize()
						)
					);
					$flowToken->setResponseOriginal($response);
					$flowToken->setResponseAmended($flowToken->getResponseOriginal());
					return $response;
				case 'PATCH':
					$patchUpdated = $mapper->updateFromArray(
						$parameters['id'],
						$flowToken->getRequestAmended()['parameters'],
						true,
						true
					);
					$response = new JSONResponse(
						$this->replaceInternalReferences(
							mapper: $mapper,
							serializedObject: $patchUpdated->jsonSerialize()
						)
					);
					$flowToken->setResponseOriginal($response);
					$flowToken->setResponseAmended($flowToken->getResponseOriginal());
					return $response;
				case 'DELETE':
					if (isset($parameters['id']) === false) {
						$response = new JSONResponse(data: ['error' => 'No id given to delete'], statusCode: 400);
						$flowToken->setResponseOriginal($response);
						return $response;
					}

					if ($mapper->delete(['id' => $parameters['id']]) !== true) {
						$response = new JSONResponse(
							data: ['error' => sprintf('Something went wrong deleting object: %s', $parameters['id'])],
							statusCode: 500
						);
						$flowToken->setResponseOriginal($response);
						$flowToken->setResponseAmended($flowToken->getResponseOriginal());
						return $response;
					}

					$response = new JSONResponse(statusCode: 204);
					$flowToken->setResponseOriginal($response);
					$flowToken->setResponseAmended($flowToken->getResponseOriginal());
					return $response;
				default:
					throw new Exception('Unsupported HTTP method');
			}//end switch
		} catch (Exception $exception) {
			$validationExceptions = [
				'OCA\OpenRegister\Exception\ValidationException',
				'OCA\OpenRegister\Exception\CustomValidationException',
			];
			if (in_array(get_class($exception), $validationExceptions) === true) {
				return $mapper->getValidateHandler()->handleValidationException(exception: $exception);
			}

			throw $exception;
		}//end try

	}//end handleSchemaRequest()

	/**
	 * Determine which of a schema's mandatory fields are missing from a PUT body.
	 *
	 * Generic dispatch-semantics helper (REQ-EP-007): PUT is defined to
	 * require every mandatory field of the target schema (unlike PATCH, which
	 * is a partial update and is left untouched by this check). Only called
	 * when the endpoint has opted in via `putPatchSemantics: true`, so
	 * existing endpoints keep their current (pre-change) PUT behaviour.
	 *
	 * @param array $parameters The incoming PUT request body.
	 * @param QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper The mapper bound to the target schema.
	 *
	 * @return array<int, string> The names of mandatory fields absent from $parameters (empty when none are missing).
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function checkPutMandatoryFields(array $parameters, QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper): array {
		$schemaMapper = $this->containerInterface->get('OCA\OpenRegister\Db\SchemaMapper');
		$schema = $schemaMapper->find($mapper->getSchema());

		$required = [];
		if (method_exists($schema, 'getRequired') === true) {
			$required = $schema->getRequired() ?? [];
		}

		$missing = [];
		foreach ($required as $field) {
			if (array_key_exists($field, $parameters) === false || $parameters[$field] === null) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end checkPutMandatoryFields()

	/**
	 * Gets the raw content for a http request from the input stream.
	 *
	 * @return string The raw content body for a http request
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getRawContent(): string {
		return file_get_contents(filename: 'php://input');
	}//end getRawContent()

	/**
	 * Get all headers for a HTTP request.
	 *
	 * @param array $server The server data from the request.
	 * @param bool $proxyHeaders Whether the proxy headers should be returned.
	 *
	 * @return array The resulting headers.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getHeaders(array $server, bool $proxyHeaders = false): array {
		$headers = array_filter(
			array: $server,
			callback: function (string $key) use ($proxyHeaders) {
				if (str_starts_with($key, 'HTTP_') === false) {
					return false;
				} elseif ($proxyHeaders === false
					&& (str_starts_with(haystack: $key, needle: 'HTTP_X_FORWARDED') === true
					|| $key === 'HTTP_X_REAL_IP' || $key === 'HTTP_X_ORIGINAL_URI')
				) {
					return false;
				}

				return true;
			},
			mode: ARRAY_FILTER_USE_KEY
		);

		$keys = array_keys($headers);

		return array_combine(
			array_map(
				callback: function ($key) {
					return strtolower(string: substr(string: $key, offset: 5));
				},
				array: $keys
			),
			$headers
		);
	}//end getHeaders()

	/**
	 * Check conditions for using an endpoint.
	 *
	 * @param ObjectEntity $endpoint The endpoint for which the checks should be done.
	 * @param IRequest $request The inbound request.
	 *
	 * @return array
	 * @throws Exception
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function checkConditions(ObjectEntity $endpoint, IRequest $request): array {
		$endpointData = $endpoint->getObject();
		$conditions = ($endpointData['conditions'] ?? []);
		$data['parameters'] = $request->getParams();
		$data['headers'] = $this->getHeaders(server: $_SERVER, proxyHeaders: true);

		$result = JsonLogic::apply(logic: $conditions, data: $data);

		if ($result === true || $result === [] || $result === null) {
			return [];
		}

		return $result;
	}//end checkConditions()

	/**
	 * Handles requests for source-based endpoints
	 *
	 * @param ObjectEntity $endpoint The endpoint configuration
	 * @param IRequest $request The incoming request
	 * @param string $path The inbound sub-path, used to resolve the endpoint's own
	 *                     named path segments into the upstream path template
	 *                     (ocon#1069).
	 * @param ExecutionTraceContext|null $trace The active execution trace context (execution-trace REQ-001).
	 *
	 * @return JSONResponse
	 * @throws GuzzleException|LoaderError|SyntaxError|\OCP\DB\Exception
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011
	 */
	private function handleSourceRequest(
		ObjectEntity $endpoint,
		IRequest $request,
		string $path = '',
		?ExecutionTraceContext $trace = null,
	): JSONResponse {
		$endpointData = $endpoint->getObject();
		$headers = $this->getHeaders(server: $_SERVER);
		$rawBody = $this->getRawContent();

		// Fetch the source entity by targetId.
		//
		// System context (ocon#147): the `source` schema is admin-only now. An Endpoint is
		// the app's own proxy surface — the whole point is that a caller reaches the target
		// WITHOUT being able to see (or authenticate to) it directly. It is the engine that
		// needs the source; the caller must not be able to read it.
		$source = $this->orObjectService->find(
			id: ($endpointData['targetId'] ?? ''),
			register: 'openconnector',
			schema: 'source',
			_rbac: false,
			_multitenancy: false
		);

		// Render the upstream path from the inbound request (ocon#1069), so a
		// `targetType: api` endpoint can proxy `/hydra/label/{owner}/{repo}`
		// onto `/repos/{owner}/{repo}/issues/{issue}/labels` instead of sending
		// the braces literally. CallService owns both the rendering and the
		// post-substitution SSRF containment check; a refusal is a 400 here and
		// never a dispatched request.
		try {
			$upstreamPath = $this->callService->renderEndpointPath(
				endpoint: (string)($endpointData['endpoint'] ?? ''),
				context: $this->buildUpstreamPathContext(
					endpointData: $endpointData,
					request: $request,
					path: $path,
					rawBody: $rawBody
				)
			);
		} catch (UnexpectedValueException $exception) {
			// The message names the offending rendered path, which is built
			// from caller-supplied values — log it, do not return it.
			$this->logger->warning(
				'openconnector: refused upstream endpoint path for endpoint '
				. ($endpointData['name'] ?? $endpoint->getUuid()) . ': ' . $exception->getMessage()
			);

			return new JSONResponse(
				['error' => 'The upstream path for this endpoint could not be resolved to a contained path'],
				Http::STATUS_BAD_REQUEST
			);
		}//end try

		// Proxy the request to the source via CallService.
		$callLog = $this->callService->call(
			source: $source,
			endpoint: $upstreamPath,
			method: $request->getMethod(),
			config: [
				'query' => $request->getParams(),
				'headers' => $headers,
				'body' => $rawBody,
			],
			trace: $trace
		);
		$callLogData = $callLog->getObject();

		return new JSONResponse(
			$callLogData['response'] ?? [],
			$callLogData['statusCode'] ?? 200
		);
	}//end handleSourceRequest()

	/**
	 * Build the template context the upstream path is rendered against
	 * (ocon#1069).
	 *
	 * Three named scopes, plus a flattened view so a path may simply say
	 * `{owner}`:
	 *
	 *  - `path`       — the endpoint's OWN named inbound segments, resolved by
	 *                   {@see getPathParameters()} against the request path.
	 *                   Keys are trimmed, because that helper leaves the
	 *                   surrounding whitespace of a `{{ id }}` segment on them.
	 *  - `parameters` — the request's query/form parameters.
	 *  - `body`       — the decoded JSON request body, when the body is a JSON
	 *                   object. A non-JSON or non-object body yields an empty
	 *                   scope rather than a guess.
	 *
	 * Precedence in the flattened view is body < parameters < path: the most
	 * specific, most structural source wins. The three named scopes are merged
	 * in LAST so a caller cannot shadow them with a body key called `path`.
	 *
	 * The source object is deliberately NOT in the context — see
	 * {@see CallService::renderEndpointPath()} for why a URL is the wrong place
	 * for anything a Source holds.
	 *
	 * @param array $endpointData The endpoint's object data.
	 * @param IRequest $request The inbound request.
	 * @param string $path The inbound sub-path.
	 * @param string $rawBody The raw request body.
	 *
	 * @return array The rendering context.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function buildUpstreamPathContext(
		array $endpointData,
		IRequest $request,
		string $path,
		string $rawBody,
	): array {
		$pathParameters = [];
		foreach ($this->getPathParameters(endpointArray: ($endpointData['endpointArray'] ?? []), path: $path) as $key => $value) {
			$pathParameters[trim((string)$key)] = $value;
		}

		$parameters = $request->getParams();

		$body = [];
		if (trim($rawBody) !== '') {
			$decoded = json_decode($rawBody, true);
			if (is_array($decoded) === true) {
				$body = $decoded;
			}
		}

		return array_merge(
			$body,
			$parameters,
			$pathParameters,
			[
				'path' => $pathParameters,
				'parameters' => $parameters,
				'body' => $body,
			]
		);

	}//end buildUpstreamPathContext()

	/**
	 * Generates url based on available endpoints for the object type.
	 *
	 * @param string $id The id of the object to generate an url for.
	 * @param \OCA\OpenRegister\Db\SchemaMapper $schemaMapper The mapper to get schemas
	 * @param int|null $register The register of the object (aids performance).
	 * @param int|null $schema The schema of the object (aids performance).
	 * @param array $parentIds The ids of the main object on subobjects.
	 *
	 * @return string The generated url.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function generateEndpointUrl(
		string $id,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		?int $register = null,
		?int $schema = null,
		array $parentIds = [],
	): string {
		if ($register === null) {
			$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($id);
			$register = $object->getRegister();
			$schema = $object->getSchema();
		}

		$target = "$register/$schema";
		$epMatches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'endpoint',
					'targetId' => $target,
					'method' => 'GET',
				],
			]
		);
		$endpoints = $epMatches['results'] ?? $epMatches;

		if (count($endpoints) === 0) {
			return $id;
		}

		$epEntity = $endpoints[0];
		$filteredEndpoints = array_filter(
			$endpoints,
			function (ObjectEntity $epEntity) {
				$epData = $epEntity->getObject();
				return in_array(needle: '{{id}}', haystack: ($epData['endpointArray'] ?? [])) === true;
			}
		);

		if (count($filteredEndpoints) > 0) {
			$epEntity = array_shift($filteredEndpoints);
		}

		$location = ($epEntity->getObject())['endpointArray'] ?? [];

		// Determine schema title (lowercased).
		$schemaTitle = strtolower($schemaMapper->find($schema)->getTitle());

		// Use first parentId if available.
		$parentId = ($parentIds[0] ?? null);

		// Make sure we are dealing with a sub endpoint.
		$isSubEndpoint = false;
		foreach ($location as $key => $part) {
			if (preg_match('#{{([^}]+)}}#', $part, $matches) === 1) {
				$placeholder = trim($matches[1]);
				if ($placeholder === "{$schemaTitle}_id") {
					$isSubEndpoint = true;
				}
			}
		}

		foreach ($location as $key => $part) {
			if (preg_match('#{{([^}]+)}}#', $part, $matches) === 1) {
				$placeholder = trim($matches[1]);

				if ($placeholder === 'id' && $parentId !== null && $isSubEndpoint === true) {
					// Replace {{id}} with parent id if set.
					$location[$key] = $parentId;
				} elseif ($placeholder === 'id') {
					// Otherwise, replace {{id}} with current object id.
					$location[$key] = $id;
				} elseif ($placeholder === "{$schemaTitle}_id") {
					// Replace {{schematitle_id}} with object id.
					$location[$key] = $id;
				}
			}
		}

		$path = implode(separator: '/', array: $location);
		return $this->urlGenerator->getBaseUrl() . '/apps/openconnector/api/endpoint/' . $path;
	}//end generateEndpointUrl()

	/**
	 * Saves object to OpenRegister.
	 *
	 * @param ObjectEntity $rule The save_object rule to apply.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 *
	 * @return array The updated $data with the saved object merged into the body.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processSaveObjectRule(ObjectEntity $rule, array $data): array {
		$configuration = $rule->getObject()['configuration'] ?? [];
		$register = $configuration['save_object']['register'];
		$schema = $configuration['save_object']['schema'];
		$mapping = $configuration['save_object']['mapping'] ?? null;

		if (isset($mapping) === true) {
			$data = $this->processMapping(rule: $rule, mapping: $this->mappingService->getMapping($mapping), data: $data);
		}

		$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
		$data['body'] = $objectService->saveObject(register: $register, schema: $schema, object: $data['body']);

		return $data;
	}//end processSaveObjectRule()

	/**
	 * Processes rules for an endpoint request.
	 *
	 * @param ObjectEntity $endpoint The endpoint being processed.
	 * @param IRequest $request The incoming request.
	 * @param array $data Current request data envelope.
	 * @param string $timing Rule timing to filter by ("before" or "after").
	 * @param string|null $objectId Optional object id (for rules scoped to a single object).
	 * @param FlowToken|null $flowToken Optional flow token threaded through the rule chain.
	 * @param integer|null $resumeAfterOrder When resuming a suspended `approval` rule (design.md
	 *                                       Decision 3), skip every rule whose `order` is not
	 *                                       strictly greater than this value — they already
	 *                                       ran before the pipeline suspended. Null for a normal
	 *                                       (non-resumed) run.
	 * @param ExecutionTraceContext|null $trace The active execution trace context. When non-null, one ordered
	 *                                          step is appended per evaluated rule (execution-trace REQ-002,
	 *                                          rule-pipeline REQ-RULE-010). When null, behaviour is
	 *                                          byte-for-byte identical to the pre-existing, untraced
	 *                                          pipeline.
	 * @param boolean $dryRun When true, write-shaped rule types (`save_object`, `override`,
	 *                        `locking`, `write_file`, `fileparts_create`,
	 *                        `filepart_upload`, `composite_fanout`) do not perform their
	 *                        write — a `skipped_dry_run` step is recorded instead and
	 *                        evaluation continues against the pre-rule data envelope
	 *                        (rule-pipeline REQ-RULE-011). Defaults to false, preserving
	 *                        existing behaviour.
	 *
	 * @return array|JSONResponse Returns modified data or error response if rule fails.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 * @spec openspec/specs/approval-workflow/spec.md#requirement-resume-on-approval-req-003
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-trace-step-emission-during-rule-pipeline-execution-req-rule-010
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011
	 */
	private function processRules(
		ObjectEntity $endpoint,
		IRequest $request,
		array $data,
		string $timing,
		?string $objectId = null,
		?FlowToken $flowToken = null,
		?int $resumeAfterOrder = null,
		?ExecutionTraceContext $trace = null,
		bool $dryRun = false,
	): array|Response {
		$endpointData = $endpoint->getObject();
		$rules = $endpointData['rules'] ?? [];
		if (empty($rules) === true) {
			return $data;
		}

		try {
			// Get all rules at once and sort by order.
			$ruleEntities = array_filter(
				array_map(
					fn ($ruleId) => $this->getRuleById(id: $ruleId),
					$rules
				)
			);

			// Sort rules by order.
			usort($ruleEntities, fn ($a, $b) => (($a->getObject())['order'] ?? 0) - (($b->getObject())['order'] ?? 0));

			// Process each rule in order.
			foreach ($ruleEntities as $rule) {
				// Skip if rule action doesn't match request method.
				// if (strtolower($ruleData['action']) !== strtolower($request->getMethod())) {
				// continue;
				// }.
				$ruleData = $rule->getObject();
				$logicResult = null;

				// Resume: rules at/before the suspended approval rule's order
				// already ran before the pipeline suspended — skip them.
				if ($resumeAfterOrder !== null && (($ruleData['order'] ?? 0) <= $resumeAfterOrder)) {
					continue;
				}

				$data['flowToken'] = $flowToken->__serialize();

				$conditionsPassed = $this->checkRuleConditions(rule: $rule, data: $data, logicResult: $logicResult);
				$timingMatches = (($ruleData['timing'] ?? 'before') === $timing);

				// Check rule conditions.
				if ($conditionsPassed === false || $timingMatches === false) {
					$this->logger->info(
						'Rule condition check failed for endpoint ' . ($endpointData['name'] ?? '')
						. ' and rule ' . ($ruleData['name'] ?? '')
						. ' of type: ' . ($ruleData['type'] ?? '')
					);

					// Execution-trace REQ-002 / rule-pipeline REQ-RULE-010: a
					// rule skipped by its own condition/timing check still
					// produces a step.
					if ($trace !== null) {
						$trace->addStep(
							type: 'rule',
							name: ($ruleData['name'] ?? ($ruleData['type'] ?? 'rule')),
							timing: $timing,
							status: 'skipped',
						);
					}

					continue;
				}//end if

				if (is_string($logicResult) === true && json_decode(json: $logicResult, associative: true) !== null) {
					$data['logicResult'] = json_decode($logicResult, true);
				} else {
					$data['logicResult'] = $logicResult;
				}

				$this->logger->info(
					'Applying rule for endpoint ' . ($endpointData['name'] ?? '')
					. ' with rule ' . ($ruleData['name'] ?? '')
					. ' of type ' . ($ruleData['type'] ?? '')
				);

				// At this moment, setting flowToken in $data when processing rules will result in data contamination.
				unset($data['flowToken']);

				$ruleType = ($ruleData['type'] ?? '');

				// Rule-pipeline REQ-RULE-011: under a dry-run replay, write-shaped
				// rule types do not perform their write — record a
				// `skipped_dry_run` step and continue against the pre-rule
				// envelope. `synchronization` is a deliberate partial exception
				// (handled inside processSyncRule() via its own $dryRun forward
				// to SynchronizationService's isTest — it is NOT in this set).
				if ($dryRun === true && in_array($ruleType, self::DRY_RUN_SUPPRESSED_RULE_TYPES, true) === true) {
					if ($trace !== null) {
						$trace->addStep(
							type: 'rule',
							name: ($ruleData['name'] ?? $ruleType),
							timing: $timing,
							status: 'skipped_dry_run',
						);
					}

					continue;
				}

				$ruleStepStart = microtime(true);

				// Process rule based on type.
				try {
					$result = match ($ruleType) {
						'save_object' => $this->processSaveObjectRule(rule: $rule, data: $data),
						'authentication' => $this->processAuthenticationRule(rule: $rule, data: $data),
						'error' => $this->processErrorRule(rule: $rule, data: $data),
						'mapping' => $this->processMappingRule(rule: $rule, data: $data),
						'synchronization' => $this->processSyncRule(rule: $rule, data: $data, flowToken: $flowToken, trace: $trace, dryRun: $dryRun),
						'javascript' => $this->processJavaScriptRule(rule: $rule, data: $data),
						'fileparts_create' => $this->processFilePartRule(rule: $rule, data: $data, endpoint: $endpoint, objectId: $objectId),
						'filepart_upload' => $this->processFilePartUploadRule(rule: $rule, data: $data, request: $request, objectId: $objectId),
						'download' => $this->processDownloadRule(rule: $rule, data: $data, objectId: $objectId),
						'extend_input' => $this->processExtendInputRule(rule: $rule, data: $data),
						'extend_external_input' => $this->ruleService->extendExternalUrl(rule: $rule, data: $data),
						'audit_trail' => $this->processAuditTrailRule(rule: $rule, endpoint: $endpoint, data: $data, objectId: $objectId),
						'write_file' => $this->processWriteFileRule(rule: $rule, data: $data, objectId: $objectId, flowToken: $flowToken),
						'locking' => $this->processLockingRule(rule: $rule, data: $data, objectId: $objectId),
						'override' => $this->processOverrideRule(rule: $rule, data: $data, objectId: $objectId),
						'webhook_signature' => $this->processWebhookSignatureRule(rule: $rule, data: $data, request: $request),
						'custom' => $this->processCustomRule(rule: $rule, data: $data),
						'composite_fanout' => $this->processCompositeFanoutRule(rule: $rule, data: $data),
						'referentienummer' => $this->processReferenceNumberRule(rule: $rule, data: $data),
						'avg_bsn_policy' => $this->processAvgBsnPolicyRule(rule: $rule, data: $data, timing: $timing),
						'selfurl_hal' => $this->processSelfUrlHalRule(rule: $rule, endpoint: $endpoint, data: $data),
						'approval' => $this->processApprovalRule(
							rule: $rule,
							endpoint: $endpoint,
							flowToken: $flowToken,
							timing: $timing,
							trace: $trace
						),
						'flow' => $this->processFlowRule(rule: $rule, data: $data),
						default => throw new Exception('Unsupported rule type: ' . $ruleType),
					};//end match
				} catch (Exception $e) {
					$message = 'Failed to apply rule for endpoint ' . ($endpointData['name'] ?? '')
						. ' with rule ' . ($ruleData['name'] ?? '')
						. ' of type ' . $ruleType
						. '. With error message: ' . $e->getMessage();
					$this->logger->error($message);

					if ($trace !== null) {
						$trace->addStep(
							type: 'rule',
							name: ($ruleData['name'] ?? $ruleType),
							timing: $timing,
							status: 'error',
							output: [
								'endpoint' => ($endpointData['name'] ?? null),
								'rule' => ($ruleData['name'] ?? null),
								'ruleType' => $ruleType,
								'message' => $e->getMessage(),
							],
							startedAtMicrotime: $ruleStepStart,
							finishedAtMicrotime: microtime(true),
						);
					}

					return new JSONResponse(['error' => 'Rule processing failed'], 500);
				}//end try

				if ($trace !== null) {
					$ruleStepInputData = [];
					if (is_array($data) === true) {
						$ruleStepInputData = $data;
					}

					$ruleStepOutputData = [];
					if (is_array($result) === true) {
						$ruleStepOutputData = $result;
					}

					$trace->addStep(
						type: 'rule',
						name: ($ruleData['name'] ?? $ruleType),
						timing: $timing,
						status: 'success',
						input: (new SensitiveFieldRegistry())->redactArray(data: $ruleStepInputData),
						output: (new SensitiveFieldRegistry())->redactArray(data: $ruleStepOutputData),
						startedAtMicrotime: $ruleStepStart,
						finishedAtMicrotime: microtime(true),
					);
				}//end if

				// If result is JSONResponse, return error immediately.
				if ($result instanceof JSONResponse === true || $result instanceof DataDownloadResponse === true) {
					return $result;
				}

				// Update data with rule result.
				$data = $result;

				$this->logger->info(
					'Successfully applied rule for endpoint ' . ($endpointData['name'] ?? '')
					. ' with rule ' . ($ruleData['name'] ?? '')
					. ' of type ' . $ruleType
				);
			}//end foreach

			unset($data['body']['_extendedInput']);

			return $data;
		} catch (Exception $e) {
			$this->logger->error('Error processing rules: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Rule processing failed'], 500);
		}//end try
	}//end processRules()

	/**
	 * This rule, that only can be run on timing 'after' overrides the content of a written object by the updated contents in the flow token.
	 *
	 * @param ObjectEntity $rule The rule to process.
	 * @param array $data The data from the flow token.
	 * @param string $objectId The object to override.
	 *
	 * @return array The updated object.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processOverrideRule(ObjectEntity $rule, array $data, string $objectId): array {

		$this->objectService->getOpenRegisters()->clearCurrents();
		$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($objectId);
		$object->setObject($data['body']);
		$object = $this->objectService->getOpenRegisters()->saveObject(
			object: $object,
			register: $object->getRegister(),
			schema: $object->getSchema(),
			uuid: $object->getUuid()
		);
		$this->objectService->getOpenRegisters()->clearCurrents();

		$data['body'] = $object->jsonSerialize();

		return $data;
	}//end processOverrideRule()

	/**
	 * `approval` rule action type: suspend the pipeline for human sign-off
	 * (rule-pipeline REQ-RULE-008 / approval-workflow REQ-001). Valid only
	 * for `timing: before` — a `before`-phase rule dispatches here because
	 * `processRules()`'s own `$timingMatches` check already filters out
	 * `after`-configured rules during a `before` run; an `approval` rule
	 * mis-configured `timing: after` still reaches this method during an
	 * `after` run (its own timing matches the loop's phase there), so the
	 * explicit `$timing !== 'before'` guard below is what actually rejects
	 * that misconfiguration (design.md Decision 1).
	 *
	 * @param ObjectEntity $rule The `approval` rule whose conditions passed.
	 * @param ObjectEntity $endpoint The endpoint whose pipeline is suspending.
	 * @param FlowToken $flowToken The in-flight FlowToken at suspension time.
	 * @param string $timing The phase `processRules()` is currently running.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, carried into the persisted
	 *                                          `approval_request.snapshot` so resume appends to the SAME
	 *                                          trace (execution-trace REQ-004's approval-resume
	 *                                          continuation).
	 *
	 * @return JSONResponse HTTP 202 with the approval_request id and a status-polling URL.
	 *
	 * @throws Exception When configured with `timing: after` (invalid configuration).
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-approval-rule-action-type-suspends-the-pipeline-req-rule-008
	 * @spec openspec/specs/approval-workflow/spec.md#requirement-endpoint-rule-pipeline-suspension-on-approval-action-req-001
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
	 */
	private function processApprovalRule(
		ObjectEntity $rule,
		ObjectEntity $endpoint,
		FlowToken $flowToken,
		string $timing,
		?ExecutionTraceContext $trace = null,
	): JSONResponse {
		if ($timing !== 'before') {
			throw new Exception('approval rule type only supports timing: before (pre-write gating)');
		}

		$approvalRequest = $this->approvalService->suspend(endpoint: $endpoint, rule: $rule, flowToken: $flowToken, trace: $trace);

		return new JSONResponse(
			data: [
				'status' => 'pending_approval',
				'approvalRequestId' => $approvalRequest->getUuid(),
				'statusUrl' => $this->urlGenerator->linkToRouteAbsolute(
					'openconnector.approvals.show',
					['id' => $approvalRequest->getUuid()]
				),
			],
			statusCode: 202
		);

	}//end processApprovalRule()

	/**
	 * `flow` rule action type: run a `flow` synchronously mid-pipeline
	 * (rule-pipeline REQ-RULE-009). Valid for either `timing` — a flow can
	 * be a pre-write side-effect (`before`) or a post-write follow-up
	 * (`after`), matching `synchronization`/`mapping`'s own either-timing
	 * validity. Adds one new dispatch entry only — REQ-RULE-001's
	 * ordering/condition/short-circuit contract is unchanged, and the
	 * pipeline's `$data` passes through unmodified (a flow's effects
	 * happen via its OWN steps calling their OWN target services, not by
	 * mutating this rule's `$data`).
	 *
	 * @param ObjectEntity $rule The `flow` rule whose conditions passed.
	 * @param array $data The current pipeline data, passed as the flow's initial input.
	 *
	 * @return array The unmodified `$data` (a flow rule never rewrites pipeline data).
	 *
	 * @throws Exception When `configuration.flow` is missing/unresolvable, or the flow
	 *                   run ends `failed`/`stopped`/`dead_letter` — surfaced through
	 *                   `processRules()`'s existing rule-failure contract (matching the
	 *                   `approval`/`error` rule types' precedent; no new pipeline-level
	 *                   failure mode is introduced).
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-flow-rule-action-type-triggers-a-flow-run-req-rule-009
	 */
	private function processFlowRule(ObjectEntity $rule, array $data): array {
		$config = $rule->getObject()['configuration'] ?? [];
		$flowId = (string)($config['flow'] ?? '');

		if ($flowId === '') {
			throw new Exception('flow rule type requires configuration.flow (the id of the flow to run)');
		}

		try {
			$flow = $this->flowRunnerService->findFlow(id: $flowId);
		} catch (Exception $e) {
			throw new Exception('flow rule: referenced flow not found: ' . $flowId);
		}

		$flowRun = $this->flowRunnerService->run(flow: $flow, input: $data, triggerSource: 'endpoint');
		$flowRunData = $flowRun->getObject();
		$status = (string)($flowRunData['status'] ?? '');

		if (in_array($status, ['failed', 'stopped', 'dead_letter'], true) === true) {
			throw new Exception('flow rule: flow run ended with status "' . $status . '"');
		}

		return $data;
	}//end processFlowRule()

	/**
	 * Get a rule by its ID using OR ObjectService
	 *
	 * @param string $id The unique identifier of the rule
	 *
	 * @return ObjectEntity|null The rule entity if found, or null if not found
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function getRuleById(string $id): ?ObjectEntity {
		try {
			// SECURITY (ocon#147 / openregister#459 / #463): an `authentication`-type rule stores
			// its inbound apiKey => userId impersonation map at `configuration.authentication.keys`,
			// now declared write-only (99-rule-nested-auth-writeonly.json). The write-only strip is
			// SCHEMA-gated (RenderObject::schemaHasWriteOnlyRule), NOT `_rbac`-gated — so `_rbac: false`
			// alone still comes back stripped and `processAuthenticationRule()` would call
			// authorizeApiKey() with an EMPTY keys map, refusing every inbound apiKey. Only `_render: false`
			// returns the raw entity BEFORE renderEntity()'s strip, so the engine keeps seeing the keys.
			// This mirrors CallService::resolveSourceForDispatch()'s raw source re-resolve (ocon#215/#236).
			return $this->orObjectService->find(
				id: $id,
				register: 'openconnector',
				schema: 'rule',
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Exception $e) {
			$this->logger->error('Error fetching rule: ' . $e->getMessage());
			return null;
		}//end try
	}//end getRuleById()

	/**
	 * Get an endpoint by its ID using OR ObjectService. Public so
	 * `ApprovalsController` can resolve the suspended endpoint recorded on
	 * an `approval_request` before calling {@see resumeFromApproval()}.
	 *
	 * @param string $id The endpoint's OpenRegister id/uuid.
	 *
	 * @return ObjectEntity|null The endpoint entity, or null when not found.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#requirement-resume-on-approval-req-003
	 */
	public function getEndpointById(string $id): ?ObjectEntity {
		try {
			return $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'endpoint', _rbac: false, _multitenancy: false);
		} catch (Exception $e) {
			$this->logger->error('Error fetching endpoint: ' . $e->getMessage());
			return null;
		}
	}//end getEndpointById()

	/**
	 * Verify an inbound webhook signature over the RAW request body.
	 *
	 * Acts as a pre-pipeline gate: it reads the raw body bytes (before any
	 * decode/mapping), verifies the configured signature header with
	 * constant-time comparison via WebhookSignatureService, and short-circuits
	 * the pipeline with an undifferentiated 401 on ANY verification failure
	 * (missing/malformed header, digest mismatch, stale timestamp). On success
	 * the unchanged $data flows on to the remaining rules.
	 *
	 * Operators MUST order this rule ahead of any body-transforming or
	 * side-effecting rule (lowest `order`); it never mutates $data.
	 *
	 * @param ObjectEntity $rule The webhook_signature rule.
	 * @param array $data The request data of the pipeline.
	 * @param IRequest $request The inbound request (for raw body access).
	 *
	 * @return array|JSONResponse The unchanged $data on pass, or a 401 JSONResponse.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md
	 */
	private function processWebhookSignatureRule(ObjectEntity $rule, array $data, IRequest $request): array|JSONResponse {
		$configuration = ($rule->getObject()['configuration'] ?? []);
		// Config lives in the type slot (configuration.webhook_signature), with
		// a root-level fallback for hand-authored rules.
		$config = ($configuration['webhook_signature'] ?? $configuration);

		$scheme = ($config['scheme'] ?? 'openconnector');
		$secret = (string)($config['secret'] ?? '');
		$headerName = ($config['header'] ?? 'X-OpenConnector-Signature');
		$tolerance = (int)($config['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS);

		// Read the raw body bytes BEFORE any decode/mapping.
		$rawBody = $this->getRawContent();

		// Case-insensitive header lookup.
		$headerValue = (string)$request->getHeader($headerName);

		$verified = $this->webhookSignatureService->verify(
			rawBody: $rawBody,
			headerValue: $headerValue,
			config: [
				'scheme' => $scheme,
				'secret' => $secret,
				'toleranceSeconds' => $tolerance,
			]
		);

		if ($verified === false) {
			// Undifferentiated error body: never leak which check failed.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		return $data;
	}//end processWebhookSignatureRule()

	/**
	 * Processes authentication rules
	 *
	 * @param ObjectEntity $rule The rule to process
	 * @param array $data The data of the request
	 *
	 * @return array|JSONResponse the unchanged $data array if authentication succeeds, or a JSONResponse containing an error on authentication.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processAuthenticationRule(ObjectEntity $rule, array $data): array|JSONResponse {
		$configuration = $rule->getObject()['configuration'] ?? [];

		// Normalise all incoming header keys to lowercase once so all subsequent
		// lookups are case-insensitive without multiple fallback variants.
		$normalisedHeaders = [];
		foreach (($data['headers'] ?? []) as $key => $value) {
			$normalisedHeaders[strtolower((string)$key)] = $value;
		}

		// Default to the Authorization header (lowercase-normalised lookup).
		$header = ($normalisedHeaders['authorization'] ?? '');

		if (isset($configuration['authentication']) === false) {
			return $data;
		}

		$authenticationType = (string)($configuration['authentication']['type'] ?? '');

		// The `nc-session` type authorises the CURRENT Nextcloud session user
		// (ocon#1068). It is dispatched BEFORE the header-presence guard below
		// because it is the one type presenting no `Authorization` header — a
		// browser calling from inside a Nextcloud page carries a session cookie
		// and a `requesttoken`, nothing more. Running it after the guard would
		// 403 every such request before the session was ever consulted, which
		// is exactly the defect being fixed. CSRF is verified inside
		// {@see AuthorizationService::authorizeNcSession()}, because the
		// dispatch route is #[NoCSRFRequired] and NC has therefore already
		// skipped its own check by this point.
		if ($authenticationType === 'nc-session') {
			try {
				$this->authorizationService->authorizeNcSession(
					users: ($configuration['authentication']['users'] ?? []),
					groups: ($configuration['authentication']['groups'] ?? [])
				);
			} catch (AuthenticationException $exception) {
				return new JSONResponse(
					data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
					statusCode: 401
				);
			}

			return $data;
		}//end if

		if (isset($configuration['authentication']['header']) === true) {
			// Convert configured header name to lowercase + underscore variant
			// for a single normalised lookup against $normalisedHeaders.
			$lookupKey = strtolower((string)$configuration['authentication']['header']);
			$header = ($normalisedHeaders[$lookupKey] ?? null);
		}

		if ($header === '' || $header === null) {
			return new JSONResponse(
				['error' => 'forbidden', 'details' => 'you are not allowed to access this endpoint unauththenticated'],
				Http::STATUS_FORBIDDEN
			);
		}

		switch ($authenticationType) {
			case 'apikey':
				try {
					$this->authorizationService->authorizeApiKey(header: $header, keys: $configuration['authentication']['keys']);
				} catch (AuthenticationException $exception) {
					return new JSONResponse(
						data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
						statusCode: 401
					);
				}
				break;
			case 'jwt':
			case 'jwt-zgw':
				try {
					$this->authorizationService->authorizeJwt(authorization: $header);
				} catch (AuthenticationException $exception) {
					return new JSONResponse(
						data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
						statusCode: 401
					);
				}
				break;
			case 'basic':
				try {
					$this->authorizationService->authorizeBasic(
						$header,
						$configuration['authentication']['users'],
						$configuration['authentication']['groups']
					);
				} catch (AuthenticationException $exception) {
					return new JSONResponse(
						data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
						statusCode: 401
					);
				}
				break;
			case 'oauth':
				try {
					$this->authorizationService->authorizeOAuth(
						$header,
						$configuration['authentication']['users'],
						$configuration['authentication']['groups']
					);
				} catch (AuthenticationException $exception) {
					return new JSONResponse(
						data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
						statusCode: 401
					);
				}
				break;
			default:
				return new JSONResponse(data: ['error' => 'The authentication method is not supported'], statusCode: Http::STATUS_NOT_IMPLEMENTED);
		}//end switch

		return $data;
	}//end processAuthenticationRule()

	/**
	 * Processes an error rule.
	 *
	 * @param ObjectEntity $rule The rule object containing error details.
	 * @param array $data Optional rule data containing JSON-logic result for inclusion.
	 *
	 * @return JSONResponse Response containing error details and HTTP status code.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processErrorRule(ObjectEntity $rule, array $data = []): JSONResponse {
		$config = $rule->getObject()['configuration'] ?? [];

		$response = [
			'error' => $config['error']['name'],
			'message' => $config['error']['message'],
		];

		// Include the json logic result as errors array.
		if ($config['error']['includeJsonLogicResult'] === true) {
			$response['errors'] = $data['logicResult'];
		}

		return new JSONResponse(
			$response,
			$config['error']['code']
		);
	}//end processErrorRule()

	/**
	 * Executes mapping on data from endpoint flow.
	 *
	 * @param ObjectEntity $rule The mapping rule context.
	 * @param Mapping $mapping The mapping object to apply.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 *
	 * @return array The updated $data with mapped body merged in.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processMapping(ObjectEntity $rule, Mapping $mapping, array $data): array {
		$config = $rule->getObject()['configuration'] ?? [];
		$ruleData = $rule->getObject();
		// Todo: We should just remove this if statement and use mapping to loop through results instead.
		if (isset($data['body']['results']) === true
			&& strtolower($ruleData['action'] ?? '') === 'get'
			&& (isset($config['mapResults']) === false || $config['mapResults'] === true)
		) {
			foreach (($data['body']['results']) as $key => $result) {
				$data['body']['results'][$key] = $this->mappingService->executeMapping($mapping, $result);
			}

			return $data;
		}

		$data['body'] = $this->mappingService->executeMapping($mapping, $data['body']);

		return $data;
	}//end processMapping()

	/**
	 * Processes a mapping rule
	 *
	 * @param ObjectEntity $rule The rule object containing mapping details
	 * @param array $data The data to be processed through the mapping rule
	 *
	 * @return array The processed data after applying the mapping rule
	 * @throws DoesNotExistException When the mapping configuration does not exist
	 * @throws MultipleObjectsReturnedException When multiple mapping objects are returned unexpectedly
	 * @throws LoaderError When there is an error loading the mapping
	 * @throws SyntaxError When there is a syntax error in the mapping configuration
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processMappingRule(ObjectEntity $rule, array $data): array {
		$config = $rule->getObject()['configuration'] ?? [];
		$mapping = $this->mappingService->getMapping($config['mapping']);

		$data = $this->processMapping(rule: $rule, mapping: $mapping, data: $data);

		return $data;
	}//end processMappingRule()

	/**
	 * Extends input for performing business logic
	 *
	 * @param ObjectEntity $rule The rule containing the configuration which parameters could be extended
	 * @param array $data The data array containing the input parameters.
	 *
	 * @return array The data array with the extended parameters in the 'extendedParameters' key.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processExtendInputRule(ObjectEntity $rule, array $data): array {
		$parameters = new Dot($data['parameters']);
		$config = $rule->getObject()['configuration'] ?? [];
		$extendedParameters = new Dot();

		foreach ($config['extend_input']['properties'] as $property) {
			$value = $parameters->get($property);

			if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
				$exploded = explode(separator: '/', string: $value);
				$value = end($exploded);
			}

			// Only allow uuids in the extend_input rule.
			if (Uuid::isValid($value) === false) {
				continue;
			}

			$extends = [];
			if (isset($config['extend_input']['extends']) === true && isset($config['extend_input']['extends'][$property]) === true) {
				$extends = $config['extend_input']['extends'][$property];
			}

			try {
				$object = $this->objectService->getOpenRegisters()->find(id: $value, _extend: $extends);
				$this->objectService->getOpenRegisters()->clearCurrents();
			} catch (DoesNotExistException $exception) {
				$this->objectService->getOpenRegisters()->clearCurrents();
				continue;
			}

			$extendedParameters->add($property, $object->jsonSerialize());
		}//end foreach

		if (isset($data['extendedParameters']) === true) {
			$data['extendedParameters'] = array_merge($extendedParameters->all(), $data['extendedParameters']);
		} else {
			$data['extendedParameters'] = $extendedParameters->all();
		}

		$data['body']['_extendedInput'] = $data['extendedParameters'];

		return $data;
	}//end processExtendInputRule()

	/**
	 * Fetches the audit trail for an object, returns a specific audit rule if the path parameter audittrail-id is specified.
	 *
	 * @param ObjectEntity $rule The rule to execute
	 * @param ObjectEntity $endpoint The endpoint on which the rule is executed
	 * @param array $data The data from the request.
	 * @param string $objectId The object id for which the request was done.
	 *
	 * @return array|Response The updated data array, or a json response with a not found error.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processAuditTrailRule(ObjectEntity $rule, ObjectEntity $endpoint, array $data, string $objectId): array|Response {
		$endpointData = $endpoint->getObject();
		$pathParameters = $this->getPathParameters(endpointArray: ($endpointData['endpointArray'] ?? []), path: $data['path']);

		if (isset($pathParameters['audittrail-id']) === true) {
			$auditrule = $this->objectService->getOpenRegisters()->getLogs($objectId, filters: ['uuid' => $pathParameters['audittrail-id']]);

			if (count($auditrule) === 1) {
				$data['body'] = $auditrule[0];
				return $data;
			}

			return new JSONResponse(
				data: ['error' => 'Not found', 'reason' => 'The resource you are looking for does not exist'],
				statusCode: HTTP::STATUS_NOT_FOUND
			);
		}

		$audittrail = $this->objectService->getOpenRegisters()->getLogs($objectId);

		$data['body'] = $audittrail;

		return $data;
	}//end processAuditTrailRule()

	/**
	 * Process a locking rule, either locking or unlocking a resource.
	 *
	 * @param ObjectEntity $rule Rule containing configuration for the execution of the rule.
	 * @param array $data The data to update.
	 * @param string $objectId The object id of the object to lock or unlock.
	 *
	 * @return array The updated data array.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\Files\NotFoundException
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processLockingRule(ObjectEntity $rule, array $data, string $objectId): array {
		$config = $rule->getObject()['configuration'] ?? [];

		if ($config['locking']['action'] === 'lock') {
			$process = (Uuid::v4())->jsonSerialize();
			$object = $this->objectService->getOpenRegisters()->lockObject(
				identifier: $objectId,
				process: $process,
				duration: ($config['locking']['duration'] ?? 3600)
			);
		} elseif ($config['locking']['action'] === 'unlock') {
			$object = $this->objectService->getOpenRegisters()->unlockObject(identifier: $objectId);
		} else {
			// Unknown locking action — leave $data untouched.
			return $data;
		}

		if (is_bool($object) === true) {
			$data['body'] = ['unlocked' => $object];
		} else {
			$data['body'] = $object;
		}

		return $data;
	}//end processLockingRule()

	/**
	 * Process a custom rule
	 *
	 * @param ObjectEntity $rule The rule to process
	 * @param array $data The data to process
	 *
	 * @return array The updated data array.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processCustomRule(ObjectEntity $rule, array $data): array|JSONResponse {
		return $this->ruleService->processCustomRule(rule: $rule, data: $data);
	}//end processCustomRule()

	/**
	 * Process a composite transactional fan-out rule.
	 *
	 * Dialect-agnostic gateway mechanic (first consumer: the VNG
	 * Klantinteracties `maak-klantcontact` composite endpoint). Delegates to
	 * {@see CompositeFanoutRule} which creates a configured parent object plus
	 * its configured children as one logical operation, rolling every write
	 * back on any child failure.
	 *
	 * @param ObjectEntity $rule The composite-fanout rule to apply.
	 * @param array $data The data to process.
	 *
	 * @return array The updated data array with the created parent merged into the body.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processCompositeFanoutRule(ObjectEntity $rule, array $data): array {
		return $this->compositeFanoutRule->apply(rule: $rule, data: $data);
	}//end processCompositeFanoutRule()

	/**
	 * Process a referentienummer generation rule.
	 *
	 * Dialect-agnostic gateway mechanic. Delegates to
	 * {@see ReferenceNumberRule} which stamps a UUIDv4 (or configured
	 * scheme) reference onto the response body.
	 *
	 * @param ObjectEntity $rule The referentienummer rule to apply.
	 * @param array $data The data to process.
	 *
	 * @return array The updated data array carrying the generated reference.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processReferenceNumberRule(ObjectEntity $rule, array $data): array {
		return $this->referenceNumberRule->apply(rule: $rule, data: $data);
	}//end processReferentienummerRule()

	/**
	 * Process an AVG BSN policy rule.
	 *
	 * Dialect-agnostic gateway mechanic. Delegates to
	 * {@see AvgBsnPolicyRule}, which hashes an inbound BSN before storage
	 * ("before" timing) or strips any raw BSN that survived to the outbound
	 * representation ("after" timing).
	 *
	 * @param ObjectEntity $rule The AVG BSN policy rule to apply.
	 * @param array $data The data to process.
	 * @param string $timing The current pipeline timing ("before"/"after").
	 *
	 * @return array The updated data array.
	 *
	 * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
	 */
	private function processAvgBsnPolicyRule(ObjectEntity $rule, array $data, string $timing): array {
		return $this->avgBsnPolicyRule->apply(rule: $rule, data: $data, timing: $timing);
	}//end processAvgBsnPolicyRule()

	/**
	 * Process a self-URL / HAL `_links` output rule.
	 *
	 * Dialect-agnostic gateway mechanic: renders an absolute `url` self-link
	 * and HAL `_links` on the response body via {@see renderSelfUrlAndHal()}.
	 *
	 * @param ObjectEntity $rule The selfurl_hal rule (carries no configuration; presence is the opt-in).
	 * @param ObjectEntity $endpoint The endpoint being processed, used to resolve the public path.
	 * @param array $data The data to process.
	 *
	 * @return array The updated data array with `url` and `_links` stamped on the body.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function processSelfUrlHalRule(ObjectEntity $rule, ObjectEntity $endpoint, array $data): array {
		unset($rule);
		if (is_array($data['body'] ?? null) === true) {
			$data['body'] = $this->renderSelfUrlAndHal(resource: $data['body'], endpoint: $endpoint);
		}

		return $data;
	}//end processSelfUrlHalRule()

	/**
	 * Render an absolute self-URL and HAL `_links` block onto a resource.
	 *
	 * Generic output helper (REQ-EP-006): usable by any dialect, not just VNG
	 * Klantinteracties. Builds the absolute URL from {@see IURLGenerator} and
	 * the endpoint's own path, so the value is correct regardless of host or
	 * environment (no hard-coded host). Relation keys already present on the
	 * resource as an embedded object carrying an `id`/`uuid` are also given a
	 * `_links` entry pointing at their own resolvable identifier.
	 *
	 * @param array $resource The resource (object body) to decorate.
	 * @param ObjectEntity $endpoint The endpoint whose path resolves the resource's own URL.
	 *
	 * @return array The resource carrying `url` and `_links.self.href` (plus relation links).
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function renderSelfUrlAndHal(array $resource, ObjectEntity $endpoint): array {
		$endpointData = $endpoint->getObject();
		$id = $resource['id'] ?? ($resource['uuid'] ?? null);

		$endpointPath = trim((string)($endpointData['endpoint'] ?? ''), '/');
		$baseEndpointUrl = rtrim($this->urlGenerator->getBaseUrl(), '/') . '/apps/openconnector/api/endpoint/' . $endpointPath;
		$selfUrl = $baseEndpointUrl;
		if ($id !== null) {
			$selfUrl = $baseEndpointUrl . '/' . $id;
		}

		$resource['url'] = $selfUrl;
		$resource['_links']['self'] = ['href' => $selfUrl];

		foreach ($resource as $key => $value) {
			if ($key === '_links' || $key === 'url') {
				continue;
			}

			if (is_array($value) === true && (isset($value['id']) === true || isset($value['uuid']) === true)) {
				$relatedId = $value['id'] ?? $value['uuid'];
				$resource['_links'][$key] = ['href' => $baseEndpointUrl . '/' . $relatedId];
			}
		}

		return $resource;
	}//end renderSelfUrlAndHal()

	/**
	 * Process a rule to write files.
	 *
	 * @param ObjectEntity $rule The rule ObjectEntity containing configuration.
	 * @param array $data The data to write.
	 * @param string $objectId The object to write the data to.
	 * @param FlowToken $flowToken The flow token carrying the request/response state.
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processWriteFileRule(ObjectEntity $rule, array $data, string $objectId, FlowToken $flowToken): array {
		$ruleConfig = $rule->getObject()['configuration'] ?? [];
		if (isset($ruleConfig['write_file']) === false) {
			throw new Exception('No configuration found for write_file');
		}

		$config = $ruleConfig['write_file'];
		$dataDot = new Dot($data);
		$flowTokenArray = $flowToken->getRequestOriginal();
		$flowTokenArray['body'] = $flowTokenArray['parameters'];
		$flowTokenDot = new Dot($flowTokenArray);

		$files = $dataDot[$config['filePath']] ?? $flowTokenDot[$config['filePath']];
		if (isset($files) === false || empty($files) === true) {
			return $dataDot->jsonSerialize();
		}

		// Check if associative array.
		if (is_array($files) === true && isset($files[0]) === true & array_keys($files[0]) !== range(0, count($files[0]) - 1)) {
			$result = [];
			foreach ($files as $key => $value) {
				// Check for tags.
				$tags = [];
				$fileName = null;
				if (is_array($value) === true) {
					$content = $value['content'];
					if (isset($value['label']) === true && isset($config['tags']) === true
						&& in_array(needle: $value['label'], haystack: $config['tags']) === true
					) {
						$tags = [$value['label']];
					}

					if (isset($value['filename']) === true) {
						$fileName = basename($value['filename']);
					}
				} else {
					$content = $value;
				}

				try {
					// Write file with OpenRegister ObjectService.
					$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
					$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
					$file = $fileService->addFile(
						objectEntity: $objectService->find($objectId),
						fileName: $fileName,
						content: base64_decode($content)
					);

					$tags = array_merge($config['tags'] ?? [], ["object:$objectId"]);
					if ($file instanceof \OCP\Files\File === true) {
						// $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
					}

					$result[$key] = $file->getPath();
				} catch (Exception $exception) {
					// Swallow per-file write errors so the rule can continue with the remaining files.
				}
			}//end foreach

			$dataDot[$config['filePath']] = $result;
		} else {
			$content = $files;
			$fileName = basename($dataDot[$config['fileNamePath']] ?? $flowTokenDot[$config['fileNamePath']]);

			try {
				// Write file with OpenRegister ObjectService.
				$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
				$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
				$file = $fileService->addFile(
					objectEntity: $objectService->find($objectId),
					fileName: $fileName,
					content: base64_decode($content)
				);

				$tags = array_merge($config['tags'] ?? [], ["object:$objectId"]);
				if ($file instanceof File === true) {
					// $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
				}

				$dataDot[$config['filePath']] = $file->getPath();
			} catch (Exception $exception) {
				// Swallow single-file write errors so the rule can complete.
			}
		}//end if

		return $dataDot->jsonSerialize();
	}//end processWriteFileRule()

	/**
	 * Processes a synchronization rule.
	 *
	 * @param ObjectEntity $rule The rule object containing synchronization details.
	 * @param array $data The data to be synchronized.
	 * @param FlowToken $flowToken The current flow token threaded through the synchronization.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded into
	 *                                          `SynchronizationService::synchronize()` so the
	 *                                          sync's own item/call steps join this execution's
	 *                                          trace (execution-trace REQ-001/REQ-002).
	 * @param boolean $dryRun When true (a dry-run replay, rule-pipeline REQ-RULE-011), forces
	 *                        `isTest: true` regardless of the rule's own configured
	 *                        test/force flags — the target synchronization already knows
	 *                        how to no-op safely.
	 *
	 * @return array The data after synchronization processing.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011
	 */
	private function processSyncRule(
		ObjectEntity $rule,
		array $data,
		FlowToken $flowToken,
		?ExecutionTraceContext $trace = null,
		bool $dryRun = false,
	): array {
		$config = $rule->getObject()['configuration'] ?? [];

		// Check if base requirement is in config.
		if (isset($config['synchronization']) === false) {
			return $data;
		}

		if (is_array($config['synchronization']) === true && isset($config['synchronization']['synchronization']) === true) {
			$synchronizationId = $config['synchronization']['synchronization'];
		} else {
			$synchronizationId = $config['synchronization'];
		}

		$this->logger->debug('[EndpointService] processSyncRule ruleId=' . $rule->getUuid() . ' synchronizationId=' . $synchronizationId);

		// Fetch the synchronization.
		if (is_numeric($synchronizationId) === true) {
			$synchronization = $this->synchronizationService->getSynchronization(id: (int)$synchronizationId);
		} else {
			$synchronization = $this->synchronizationService->getSynchronization(filters: ['reference' => $synchronizationId]);
		}

		$this->logger->debug(
			'[EndpointService] processSyncRule synchronization fetched id=' . $synchronization->getUuid()
			. ' name=' . $synchronization->getName()
		);

		// Check if the synchronization should be in test mode.
		if ($dryRun === true) {
			// Rule-pipeline REQ-RULE-011: a dry-run replay forwards isTest:
			// true unconditionally, reusing synchronization-engine REQ-011's
			// existing no-write guarantee rather than a second mechanism.
			$test = true;
		} elseif (isset($data['body']['isTest']) === true) {
			$test = $data['body']['isTest'];
		} elseif (isset($config['isTest']) === true) {
			$test = $config['isTest'];
		} else {
			$test = false;
		}

		// Check if the synchronization should be forced.
		if (isset($data['body']['force']) === true) {
			$force = $data['body']['force'];
		} elseif (isset($config['force']) === true) {
			$force = $config['force'];
		} else {
			$force = false;
		}

		$object = null;
		if (isset($data['body']) === true) {
			$object = $data['body'];
		}

		// Set $object to a different variable becuase we might update $object with reference and want to keep what we send to synchronize.
		$sendObject = $object;

		// If we have an objectIdPath, pull the id from the body and fetch the object from the database.
		$fetchedObject = null;

		if (isset($config['synchronization']['preDelay']) === true && is_int($config['synchronization']['preDelay']) === true) {
			sleep($config['synchronization']['preDelay']);
		}

		if (isset($config['synchronization']['objectIdPath']) === true) {
			$dataDot = new Dot($data['body']);

			$id = $dataDot->get($config['synchronization']['objectIdPath']);

			if (filter_var(value: $id, filter: FILTER_VALIDATE_URL) !== false) {
				$idExploded = explode(separator: '/', string: $id);
				$id = end($idExploded);
			}

			$this->objectService->getOpenRegisters()->clearCurrents();
			$fetchedObject = $this->objectService->getOpenRegisters()->find($id);
			$foData = $fetchedObject->jsonSerialize();
			$foData['synchronization_trigger'] = true;
			$fetchedObject->setObject($foData);
		}

		// Run synchronization.
		$mutationType = null;
		// `getSynchronization()` returns an OpenRegister `ObjectEntity`, whose
		// `sourceConfig` lives in the object BODY — it is not an Entity column,
		// and `ObjectEntity` overrides no `__call`. The previous
		// `$synchronization->getSourceConfig()` therefore always threw
		// `BadFunctionCallException('sourceConfig is not a valid attribute')`
		// from `Entity::getter()`, which `processRules()`'s `catch (Exception)`
		// swallowed into a blanket HTTP 500 — i.e. EVERY `synchronization` rule
		// on an endpoint 500'd, with the real cause hidden. Read the body the
		// same way every other sourceConfig consumer in this codebase does
		// (SynchronizationService reads `$synchronization['sourceConfig']`);
		// this was the only `getSourceConfig()` call site in the app.
		$sourceConfig = ($synchronization->getObject()['sourceConfig'] ?? []);
		if (isset($sourceConfig['synchronizationType']) === true && $sourceConfig['synchronizationType'] === 'delete') {
			$mutationType = 'delete';
		}

		$this->logger->debug(
			'[EndpointService] processSyncRule calling synchronize syncId=' . $synchronization->getUuid()
			. ' isTest=' . var_export($test, true)
			. ' force=' . var_export($force, true)
			. ' mutationType=' . var_export($mutationType, true)
		);
		$log = $this->synchronizationService->synchronize(
			synchronization: $synchronization,
			isTest: $test,
			force: $force,
			object: $fetchedObject,
			mutationType: $mutationType,
			data: $object,
			flowToken: $flowToken,
			trace: $trace
		);
		$this->logger->debug(
			'[EndpointService] processSyncRule synchronize complete syncId=' . $synchronization->getUuid()
		);

		// $object got updated through reference.
		$returnedObject = $object;

		if (isset($config['synchronization']['retainResponse']) === true) {
			$retainResponse = (bool)$config['synchronization']['retainResponse'];
		} else {
			$retainResponse = false;
		}

		if (isset($config['synchronization']['postDelay']) === true && is_int($config['synchronization']['postDelay']) === true) {
			sleep($config['synchronization']['postDelay']);
		}

		if (isset($config['synchronizationConfig']['mergeResultToKey']) === true && $retainResponse === false) {
			// Merge result to root send object.
			if ($config['synchronizationConfig']['mergeResultToKey'] === '#') {
				$data['body'] = array_merge($sendObject, $returnedObject);
				// Merge result to configured key in send object.
			} else {
				$sendObject[$config['synchronizationConfig']['mergeResultToKey']] = $returnedObject;
				$data['body'] = $sendObject;
			}

			// Overwrite body with result.
		} elseif (isset($config['synchronizationConfig']['overwriteObjectWithResult']) === true
			&& filter_var(
				$config['synchronizationConfig']['overwriteObjectWithResult'],
				FILTER_VALIDATE_BOOLEAN,
				FILTER_NULL_ON_FAILURE
			) === true
			&& $retainResponse === false
		) {
			$data['body'] = $returnedObject;
		} elseif ($retainResponse === false) {
			$data['body'] = $log;
		}//end if

		return $data;
	}//end processSyncRule()

	/**
	 * Processes a file part creation rule.
	 *
	 * @param ObjectEntity $rule The rule to process.
	 * @param array $data The created object in array form.
	 * @param ObjectEntity $endpoint The endpoint the file part rule is bound to.
	 * @param string|null $objectId The id of the resulting object.
	 *
	 * @return array The updated object data.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws DoesNotExistException
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws MultipleObjectsReturnedException
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCA\OpenRegister\Exception\ValidationException
	 * @throws \OCP\Files\InvalidPathException
	 * @throws \OCP\Files\NotFoundException
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processFilePartRule(ObjectEntity $rule, array $data, ObjectEntity $endpoint, ?string $objectId = null): array|JSONResponse {
		if ($objectId === null) {
			throw new Exception('Filepart rules can only be applied after the object has been created');
		}

		$ruleConfig = $rule->getObject()['configuration'] ?? [];
		if (isset($ruleConfig['fileparts_create']) === false) {
			throw new Exception('No configuration found for fileparts_create');
		}

		$config = $ruleConfig['fileparts_create'];
		$endpointData = $endpoint->getObject();
		$targetId = explode('/', $endpointData['targetId'] ?? '');

		$registerId = $targetId[0];
		$superSchemaId = $targetId[1];

		$sizeLocation = $config['sizeLocation'];
		$schemaId = $config['schemaId'];
		$filenameLocation = $config['filenameLocation'] ?? 'filename';
		$filePartLocation = $config['filePartLocation'] ?? 'fileParts';

		$mapping = null;
		if (isset($config['mappingId']) === true) {
			$mapping = $this->mappingService->getMapping($config['mappingId']);
		}

		$openRegister = $this->objectService->getOpenRegisters();
		$openRegister->setRegister($registerId);
		$openRegister->setSchema($superSchemaId);

		$object = $openRegister->find(id: $objectId);
		// $location = $object->getFolder();
		$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
		$location = $fileService->getObjectFolder($object)->getPath();

		$dataDot = new Dot($data);
		$size = $dataDot[$sizeLocation];
		$filename = $dataDot[$filenameLocation];

		$fileParts = $this->storageService->createUpload($location, $filename, $size, $objectId);

		$fileParts = array_map(
			function ($filePart) use ($mapping, $registerId, $schemaId, $openRegister) {

				if ($mapping !== null) {
					$formatted = $this->mappingService->executeMapping(mapping: $mapping, input: $filePart);
				} else {
					$formatted = $filePart;
				}

				try {
					$object = $this->objectService->getOpenRegisters()->saveObject(
						register: $registerId,
						schema: $schemaId,
						object: $formatted,
						uuid: $formatted['id']
					);
					return $this->replaceInternalReferences(mapper: $openRegister, object: $object);
				} catch (ValidationException $exception) {
					return $this->objectService->getOpenRegisters()->handleValidationException($exception);
				}

			},
			$fileParts
		);

		$errors = array_filter(
			$fileParts,
			function ($part) {
				if ($part instanceof JSONResponse) {
					return true;
				}
			}
		);

		if (count($errors) > 0) {
			return array_shift($errors);
		}

		$dataDot[$filePartLocation] = $fileParts;

		$filepartIds = array_map(
			function ($filePart) {
				return $filePart['id'];
			},
			$fileParts
		);

		$saveObject = clone $dataDot;
		$saveObject[$filePartLocation] = $filepartIds;

		$openRegister->saveObject(register: $registerId, schema: $schemaId, object: $saveObject->jsonSerialize());

		return $dataDot->jsonSerialize();
	}//end processFilePartRule()

	/**
	 * Processes the upload of a file part.
	 *
	 * @param ObjectEntity $rule The rule to process.
	 * @param array $data The data from the object in array form.
	 * @param IRequest $request The current request used to read the uploaded part body.
	 * @param string|null $objectId The id of the object.
	 *
	 * @return array The updated object data.
	 *
	 * @throws DoesNotExistException
	 * @throws LoaderError
	 * @throws MultipleObjectsReturnedException
	 * @throws SyntaxError
	 * @throws \OCP\Files\InvalidPathException
	 * @throws \OCP\Files\NotFoundException
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processFilePartUploadRule(ObjectEntity $rule, array $data, IRequest $request, ?string $objectId = null): array {
		$ruleConfig = $rule->getObject()['configuration'] ?? [];
		if (isset($ruleConfig['filepart_upload']) === false) {
			throw new Exception('No configuration found for filepart_upload');
		}

		$config = $ruleConfig['filepart_upload'];

		$mappedData = $data;

		if (isset($config['mappingId']) === true) {
			$mapping = $this->mappingService->getMapping($config['mappingId']);
			$mappedData = $this->mappingService->executeMapping(mapping: $mapping, input: $mappedData);
		}

		$mappedData['successful'] = $this->storageService->writePart(
			partId: $mappedData['order'],
			partUuid: $mappedData['id'],
			data: $mappedData['data']
		);

		unset($data['data']);

		if (isset($config['mappingOutId']) === true) {
			$mappedData = $this->mappingService->executeMapping(
				mapping: $this->mappingService->getMapping(mappingId: $config['mappingOutId']),
				input: $mappedData
			);
		}

		$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($objectId);
		$object->setObject($mappedData);
		$this->objectService->getOpenRegisters()->getMapper('objectEntity')->update($object);

		$data['body'] = $mappedData;

		return $data;
	}//end processFilePartUploadRule()

	/**
	 * Processes a JavaScript rule
	 *
	 * @param ObjectEntity $rule The rule object containing JavaScript execution details
	 * @param array $data The input data to be processed by the JavaScript rule
	 *
	 * @return array The processed data after executing the JavaScript rule
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processJavaScriptRule(ObjectEntity $rule, array $data): array {
		$config = $rule->getObject()['configuration'] ?? [];
		// @todo: Here we need to implement the JavaScript execution logic
		// For now, just return the data unchanged
		return $data;
	}//end processJavaScriptRule()

	/**
	 * Downloads a file based upon configuration
	 *
	 * @param ObjectEntity $rule The rule to execute.
	 * @param array $data The data to perform the rule on.
	 * @param string $objectId The id of the requested object.
	 *
	 * @return Response A response containing the file requested.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws DoesNotExistException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\Files\NotFoundException
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processDownloadRule(ObjectEntity $rule, array $data, string $objectId): Response {
		$config = $rule->getObject()['configuration'] ?? [];

		/*
		 * @var ObjectEntity $object
		 */

		$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find(identifier: $objectId);

		if (isset($data['parameters']['filename']) === true) {
			$filename = $data['parameters']['filename'];
		}

		if (isset($config['filenamePosition']) === true) {
			$dot = new Dot($object->jsonSerialize());
			$filename = $dot->get($config['filenamePosition']);
		}

		$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
		$files = $fileService->getFiles(object: $object, sharedFilesOnly: false);

		// Try to get filename from object its files (only works when object has 1 file).
		if (isset($filename) === false && count($object->getFiles()) === 1) {
			$filename = $object->getFiles()[0]['title'];
		}

		// Try to get filename from files found with fileservice (only works when object has 1 file).
		if (isset($filename) === false && count($files) === 1) {
			$filename = $files[0]->getName();
		}

		if (isset($filename) === false) {
			throw new Exception('File could not be determined');
		}

		if (isset($data['parameters']['version']) === true) {
			/*
			 * @var File $file
			 */

			// OpenRegister beta FileService::getFile() has no version argument yet.
			$file = $fileService->getFile(object: $object, file: $filename);
		} elseif (isset($data['parameters']['versie']) === true) {
			/*
			 * @var File $file
			 *
			 * @TODO: This can be nicer by mapping, but let's first get something sure.
			 */

			// OpenRegister beta FileService::getFile() has no versie argument yet.
			$file = $fileService->getFile(object: $object, file: $filename);
		} else {
			/*
			 * @var File $file
			 */

			$file = $fileService->getFile(object: $object, file: $filename);
		}//end if

		$response = new DataDownloadResponse(data: $file->getContent(), filename: $file->getName(), contentType: $file->getMimeType());

		return $response;
	}//end processDownloadRule()

	/**
	 * Checks if rule conditions are met.
	 *
	 * @param ObjectEntity $rule The rule object containing conditions to be checked.
	 * @param array $data The input data against which the conditions are evaluated.
	 * @param mixed $logicResult Reference parameter — receives the raw JSON-logic apply result.
	 *
	 * @return bool True if conditions are met, false otherwise.
	 *
	 * @throws Exception
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function checkRuleConditions(ObjectEntity $rule, array $data, mixed &$logicResult): bool {
		$conditions = $rule->getObject()['conditions'] ?? [];
		if (empty($conditions) === true) {
			return true;
		}

		return ($logicResult = JsonLogic::apply($conditions, $data)) == true;
	}//end checkRuleConditions()

	/**
	 * Updates request object with processed rule data.
	 *
	 * @param FlowToken $flowToken The current flow token to be amended.
	 * @param array $ruleData The processed rule data to update the request with.
	 *
	 * @return FlowToken The updated flow token with the amended request.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function updateRequestWithRuleData(FlowToken $flowToken, array $ruleData): FlowToken {
		$parameters = $ruleData['body']['_parameters'] ?? $flowToken->getRequestAmended()['parameters'];
		$method = $ruleData['body']['_method'] ?? $flowToken->getRequestAmended()['method'];
		$headers = $ruleData['body']['_headers'] ?? $flowToken->getRequestAmended()['headers'];

		$requestAmended = $flowToken->getRequestAmended();

		$requestAmended['method'] = $method;
		$requestAmended['parameters'] = $parameters;
		$requestAmended['headers'] = $headers;

		$flowToken->setRequestAmended($requestAmended);

		return $flowToken;
		// Return the overridden request.
	}//end updateRequestWithRuleData()

	/**
	 * Parse raw content into structured data based on content type.
	 *
	 * @param IRequest $request The current request, used to inspect headers and raw body.
	 *
	 * @return mixed Parsed data (array for JSON/XML) or original string.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function parseContent(IRequest $request): mixed {
		$contentType = $request->getHeader('Content-Type');

		if (str_contains($contentType, 'multipart/form-data') === true) {
			[$post, $files] = request_parse_body();

			$parsedFiles = array_map(
				function ($file) {
					return file_get_contents($file['tmp_name']);
				},
				$files
			);

			return array_merge($post, $parsedFiles);
		}

		$content = $this->getRawContent();

		// Try JSON decode first.
		$json = json_decode($content, true);
		if ($json !== null) {
			return $json;
		}

		// Try XML decode if content type suggests XML or content looks like XML.
		if ($contentType === 'application/xml' || $contentType === 'text/xml'
			|| ($contentType === '' && $this->looksLikeXml(content: $content) === true)
		) {
			libxml_use_internal_errors(true);
			$xml = SafeXmlParser::parse($content);
			libxml_clear_errors();

			if ($xml !== false) {
				return json_decode(json_encode($xml), true);
			}
		}

		// Return original content as fallback.
		return $request->getParams();
	}//end parseContent()

	/**
	 * Check if content appears to be XML.
	 *
	 * @param string $content Content to check.
	 *
	 * @return bool True if content is valid XML.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	private function looksLikeXml(string $content): bool {
		// Suppress XML errors.
		libxml_use_internal_errors(true);

		// Use the safe parser so the XXE loader cannot leak in from SOAPService.
		$result = SafeXmlParser::parse($content) !== false;

		// Clear any XML errors.
		libxml_clear_errors();

		return $result;
	}//end looksLikeXml()
}//end class
