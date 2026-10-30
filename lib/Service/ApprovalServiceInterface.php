<?php

/**
 * Contract of the ApprovalService.
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

use OCA\Integriq\Exception\ApprovalStateException;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see ApprovalService} that other components call.
 */
interface ApprovalServiceInterface {
	/**
	 * Default suspension TTL (24 hours) when a rule/synchronization does not configure one.
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = 86400;

	/**
	 * Suspend an endpoint rule-pipeline run on a `before`-timing `approval`
	 * rule whose conditions passed: persist a `pending` `approval_request`
	 * carrying a sensitive-header-stripped FlowToken snapshot, and notify
	 * the configured approver group.
	 *
	 * @param ObjectEntity $endpoint The endpoint whose pipeline suspended.
	 * @param ObjectEntity $rule The `approval` rule that suspended it.
	 * @param FlowToken $flowToken The in-flight FlowToken at suspension time.
	 * @param ExecutionTraceContext|null $trace The active execution trace context at suspension time, when the
	 *                                          suspended run is traced (execution-trace REQ-004's
	 *                                          approval-resume continuation). Its `traceId` and `before`-phase
	 *                                          steps are carried in the persisted snapshot, alongside the
	 *                                          FlowToken serialization, so {@see rehydrateTraceContext()} can
	 *                                          reconstruct the SAME trace on resume.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
	 */
	public function suspend(ObjectEntity $endpoint, ObjectEntity $rule, FlowToken $flowToken, ?ExecutionTraceContext $trace = null): ObjectEntity;

	/**
	 * Hand a just-created, pending approval_request to its approvers: mirror
	 * it into one shared task offered to the approver group, or notify the
	 * group directly when there is no offered mirror. Every suspend
	 * path ends here; {@see SynchronizationApprovalGate} calls it for the
	 * synchronization gate.
	 *
	 * @param ObjectEntity $approvalRequest The just-created, pending approval_request.
	 *
	 * @return ObjectEntity The record, carrying `taskUuid` when the mirror was created.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function announce(ObjectEntity $approvalRequest): ObjectEntity;

	/**
	 * Suspend a `FlowRunnerService::run()` invocation on an `approval` flow
	 * step: persist a `pending` `approval_request` carrying `flowRunId`/
	 * `resumeStepOrder` and a sensitive-header-stripped `FlowToken`
	 * snapshot, and notify the configured approver group. Mirrors
	 * {@see suspend()}'s own persistence shape exactly (design.md Decision
	 * 4) — the only difference is which FK is set (`flowRunId` here vs.
	 * `endpointId`/`ruleId` there), since a flow step has no `rule` object
	 * of its own.
	 *
	 * @param ObjectEntity $flowRun The in-flight flow_run being suspended.
	 * @param integer $resumeStepOrder The step `order` to resume at once approved.
	 * @param array $config The approval step's `config` block (`approverGroup`/`onReject`/`onTimeout`/`ttlSeconds`).
	 * @param FlowToken $flowToken The in-flight FlowToken at suspension time.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	public function suspendForFlow(ObjectEntity $flowRun, int $resumeStepOrder, array $config, FlowToken $flowToken): ObjectEntity;

	/**
	 * Suspend an OpenRegister ENGINE flow run on an
	 * `openconnector.approval-request` step: persist a `pending`
	 * `approval_request` carrying `engineRunUuid`/`signalNodeId` instead of
	 * `flowRunId`/`resumeStepOrder`, and notify the configured approver
	 * group. The engine run resumes through
	 * `FlowRunSignalService::signalAs()` (delivered by
	 * `ApprovalsController`), never through a FlowToken rehydration — the
	 * engine holds the run's own state, so `snapshot` stays empty and this
	 * record remains purely the human-decision system of record
	 * (hitl-on-shared-tasks D-1 unchanged: the mirror task and
	 * notifications behave exactly as for every other suspension kind).
	 *
	 * @param string $engineRunUuid The suspended OpenRegister flow run's uuid.
	 * @param string $signalNodeId The graph node id awaiting the decision, so the
	 *                             signal addresses the right resume slot.
	 * @param array $config The approval step's config (`question`/`approverGroup`/`ttlSeconds`/`failOnReject`).
	 * @param string $requesterUid The run owner's uid (`context.triggeredBy`), or '' when unattributed.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function suspendForEngineRun(
		string $engineRunUuid,
		string $signalNodeId,
		array $config,
		string $requesterUid = '',
	): ObjectEntity;

	/**
	 * Create the `approval_request` gating an `api_product_subscription`
	 * whose chosen tier has `requiresApproval: true` (api-product-gateway
	 * REQ-APG-004). Structurally identical to
	 * {@see SynchronizationApprovalGate::suspendForSynchronization()} — no FlowToken snapshot, no
	 * resumed pipeline; a *different* subject (`ProductSubscriptionsController`)
	 * resolves on `completeApproval()`/`reject()` and flips the
	 * subscription's own `status`, since that orchestration is not this
	 * service's concern (design.md Decision 4 — deliberately NOT a
	 * generalisation of `suspend()`, whose snapshot/resumeOrder fields are
	 * meaningless for a subscription).
	 *
	 * @param string $subscriptionId The gated api_product_subscription's id.
	 * @param string $approverGroup The tier's configured approver group.
	 * @param string $onReject Outcome on reject.
	 * @param integer $ttlSeconds TTL in seconds before expiry.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 * @spec openspec/changes/archive/2026-07-15-api-product-gateway/design.md#decision-4-subscription-approval-reuses-approvalservices-generic-state-machine-via-one-new-creation-method-not-suspend
	 */
	public function suspendForSubscription(
		string $subscriptionId,
		string $approverGroup,
		string $onReject,
		int $ttlSeconds,
	): ObjectEntity;

	/**
	 * Record how a resumed run ended on an already approved request.
	 *
	 * @param ObjectEntity $approvalRequest The approved approval_request.
	 * @param string $resumeResult `success`, `error` or `superseded`.
	 *
	 * @return ObjectEntity The stored request.
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-accepting-writes-the-previewed-change-set-or-asks-again-req-inav-004
	 */
	public function recordResumeResult(ObjectEntity $approvalRequest, string $resumeResult): ObjectEntity;

	/**
	 * Rehydrate a FlowToken from a persisted snapshot via the public
	 * setters — there is no `__unserialize()` (flow-token-helper's
	 * documented gap; discovery.md Finding 2).
	 *
	 * @param array $snapshot The `FlowToken::__serialize()` 8-key array.
	 *
	 * @return FlowToken The rehydrated token.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function rehydrateFlowToken(array $snapshot): FlowToken;

	/**
	 * Reconstruct the `ExecutionTraceContext` recorded alongside the
	 * FlowToken serialization at suspension time (design.md Decision 2 /
	 * {@see suspend()}), pre-loaded with the original `traceId` and
	 * `before`-phase steps so `resumeFromApproval()` appends the `after`-
	 * phase steps to the SAME trace instead of starting a new,
	 * disconnected one (execution-trace REQ-004's approval-resume
	 * continuation scenario).
	 *
	 * @param array $snapshot The persisted `approval_request.snapshot` array.
	 *
	 * @return ExecutionTraceContext|null The rehydrated context, or null when the suspended run was untraced
	 *                                    (no `traceId` recorded — e.g. a pre-existing approval_request created
	 *                                    before this change, or a request that predates the traced entry points).
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
	 */
	public function rehydrateTraceContext(array $snapshot): ?ExecutionTraceContext;

	/**
	 * Find an approval_request by id.
	 *
	 * @param string $id The approval_request uuid.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ApprovalStateException (404) When no such request exists.
	 *
	 * @spec openspec/changes/archive/2026-07-15-hitl-approval-rule-action/design.md#api-design
	 */
	public function find(string $id): ObjectEntity;

	/**
	 * Whether the given user may act on this specific approval_request:
	 * an NC admin (break-glass), or a member of its configured
	 * `approverGroup`. Does NOT check the ADR-023 action-matrix layer —
	 * that is the controller's `ActionAuthService::requireAction()` call,
	 * independent of this per-object check (design.md Decision 5).
	 *
	 * @param ObjectEntity $approvalRequest The request to check.
	 * @param IUser $user The acting user.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function isAuthorizedApprover(ObjectEntity $approvalRequest, IUser $user): bool;

	/**
	 * Guard: the request must be `pending` and not past its `expiresAt`.
	 * `approve()`/`reject()` MUST call this synchronously — the sweep job's
	 * cadence alone is not sufficient (REQ-005 race-close requirement).
	 *
	 * @param ObjectEntity $approvalRequest The request to check.
	 *
	 * @return void
	 *
	 * @throws ApprovalStateException (409) When not pending or already expired.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function assertActionable(ObjectEntity $approvalRequest): void;

	/**
	 * Record a resumed chain's outcome on approval: `status: approved`,
	 * `approverUserId`, `approvedAt`, optional `comment`, and
	 * `resumeResult`.
	 *
	 * @param ObjectEntity $approvalRequest The pending approval_request being resolved.
	 * @param IUser $approver The approving user.
	 * @param string $resumeResult `success` or `error`.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return ObjectEntity The updated approval_request.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function completeApproval(
		ObjectEntity $approvalRequest,
		IUser $approver,
		string $resumeResult,
		?string $comment = null,
	): ObjectEntity;

	/**
	 * Reject a `pending`, non-expired approval_request. Self-contained: the
	 * `error`/`skip` outcomes are resolved purely by state (the original
	 * caller's eventual status poll of `GET /api/approvals/{id}` reflects
	 * the rejection); only `dead_letter` shares the terminal-state concept
	 * with the timeout sweep (REQ-005).
	 *
	 * @param ObjectEntity $approvalRequest The pending approval_request.
	 * @param IUser $approver The rejecting user.
	 * @param string $comment Mandatory rejection comment.
	 *
	 * @return ObjectEntity The updated approval_request.
	 *
	 * @throws ApprovalStateException (400) When the comment is empty.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function reject(ObjectEntity $approvalRequest, IUser $approver, string $comment): ObjectEntity;

	/**
	 * Sweep every `pending` approval_request whose `expiresAt` has passed
	 * and apply its configured `onTimeout` outcome. Bounded to
	 * already-expired rows only (Non-Functional Requirements: no full-table
	 * scan of resolved requests).
	 *
	 * @return array{swept: integer, deadLettered: integer} Counts for the cron log.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function sweepExpired(): array;

	/**
	 * Resolve a pending record whose mirror OpenRegister's timer sweep
	 * closed: expired, or dead-lettered when `onTimeout` says so, exactly
	 * as the local sweep would (hitl-on-shared-tasks 2.1).
	 *
	 * @param ObjectEntity $approvalRequest The pending approval_request.
	 *
	 * @return ObjectEntity The resolved record.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-the-shared-sweep-owns-the-mirrors-expiry
	 */
	public function expireFromSharedTask(ObjectEntity $approvalRequest): ObjectEntity;

	/**
	 * List approval_request rows visible to the given user: every row for
	 * an admin; for a non-admin, only rows whose `approverGroup` the user
	 * belongs to plus rows the user themself requested (design.md API
	 * Design, `GET /api/approvals`).
	 *
	 * @param IUser $user The requesting user.
	 * @param string|null $statusFilter Optional comma-separated status filter.
	 *
	 * @return array<int, ObjectEntity>
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function listFor(IUser $user, ?string $statusFilter = null): array;

	/**
	 * Dispatch the actionable approver notification imperatively via
	 * `OCP\Notification\IManager`, scoped to this single method (design.md
	 * Decision 4 / Risk 1 — the declarative dialect cannot express a
	 * per-rule dynamic approver group or interactive actions).
	 *
	 * @param ObjectEntity $approvalRequest The just-created approval_request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function notifyApprovers(ObjectEntity $approvalRequest): void;
}
