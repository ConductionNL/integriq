<?php

/**
 * Contract of the EndpointService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Exception;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Public operations of {@see EndpointService} that other components call.
 *
 * The method signatures repeat those of EndpointService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface EndpointServiceInterface {
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
	public function handleRequest(ObjectEntity $endpoint, IRequest $request, string $path): Response;

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
	public function triggerFromFlow(ObjectEntity $endpoint, array $parameters = []): Response;

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
	): Response;

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
	public function replay(ObjectEntity $endpoint, array $requestSnapshot, ExecutionTraceContext $trace, bool $dryRun = true): Response;

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
	public function resolveProductForEndpoint(ObjectEntity $endpoint): ?ObjectEntity;

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
	public function buildDeprecationHeaders(ObjectEntity $product): array;

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
	public function recordInboundCallLog(ObjectEntity $endpoint, ObjectEntity $product, int $statusCode, float $durationMs): void;

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
	public function checkPutMandatoryFields(array $parameters, QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper): array;

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
	): string;

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
	public function getEndpointById(string $id): ?ObjectEntity;

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
	public function renderSelfUrlAndHal(array $resource, ObjectEntity $endpoint): array;
}
