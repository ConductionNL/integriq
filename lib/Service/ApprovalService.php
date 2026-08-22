<?php

/**
 * OpenConnector Approval Service.
 *
 * Core of the human-in-the-loop (HITL) approval workflow: persists/reads
 * `approval_request` OpenRegister objects, enforces the state machine
 * (pending -> approved|rejected|expired|dead_letter|error), the two-layer
 * authorization model (ADR-023 action matrix + per-request approverGroup
 * membership), FlowToken snapshot stripping/rehydration, the imperative
 * actionable-notification dispatch, and expiry sweeping. Callers
 * (`EndpointService`, `SynchronizationService`, `ApprovalsController`,
 * `ApprovalTimeoutSweepJob`) depend on this service; it deliberately depends
 * on neither of the two former to avoid a circular service graph — the
 * suspend/resume ORCHESTRATION (rehydrating the pipeline, re-invoking
 * synchronize()) is composed by the controller and by EndpointService's own
 * public resume method, not by this service.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
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
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateInterval;
use DateTime;
use OCA\OpenConnector\Exception\ApprovalStateException;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * State machine + persistence for `approval_request` objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class ApprovalService {

	/**
	 * OpenRegister register slug holding approval requests.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for an approval_request record.
	 *
	 * @var string
	 */
	public const SCHEMA = 'approval_request';

	/**
	 * Default suspension TTL (24 hours) when a rule/synchronization does not configure one.
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = 86400;

	/**
	 * Request/response header names (lowercase) stripped from a persisted
	 * snapshot before it is written — REQ-001's "sensitive headers" hard
	 * requirement (design.md Security Considerations).
	 *
	 * @var array<int, string>
	 */
	private const STRIPPED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'x-api-key'];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for approval_request persistence.
	 * @param IUserSession $userSession Resolves the requesting/approving NC user.
	 * @param IGroupManager $groupManager Resolves approver-group membership.
	 * @param INotificationManager $notificationManager Dispatches the imperative actionable notification.
	 * @param IURLGenerator $urlGenerator Builds the Pending Approvals deep link.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param ExecutionTraceService|null $executionTraceService Persists the traced run's execution_trace at
	 *                                                          suspension/resume (execution-trace REQ-004). Nullable + defaulted so
	 *                                                          pre-existing positional test instantiations keep working unmodified.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly INotificationManager $notificationManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ?ExecutionTraceService $executionTraceService = null,
	) {
	}//end __construct()

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
	public function suspend(ObjectEntity $endpoint, ObjectEntity $rule, FlowToken $flowToken, ?ExecutionTraceContext $trace = null): ObjectEntity {
		$ruleData = $rule->getObject();
		$config = ($ruleData['configuration']['approval'] ?? []);

		$approverGroup = (string)($config['approverGroup'] ?? '');
		$ttlSeconds = (int)($config['ttlSeconds'] ?? self::DEFAULT_TTL_SECONDS);
		$onReject = (string)($config['onReject'] ?? 'error');
		$onTimeout = (string)($config['onTimeout'] ?? 'error');

		$now = new DateTime();
		$expiresAt = (clone $now)->add(new DateInterval('PT' . max($ttlSeconds, 1) . 'S'));

		$requesterUserId = $this->userSession->getUser()?->getUID();

		$snapshot = $this->stripSensitiveHeaders(snapshot: $flowToken->__serialize());
		if ($trace !== null) {
			// Execution-trace REQ-004: carry the traceId + before-phase steps
			// alongside the FlowToken serialization so resume appends to the
			// SAME trace instead of creating a disconnected one.
			$snapshot['traceId'] = $trace->getTraceId();
			$snapshot['traceSteps'] = $trace->getSteps();
		}

		$record = $this->objectService->saveObject(
			object: [
				'status' => 'pending',
				'endpointId' => $endpoint->getUuid(),
				'ruleId' => $rule->getUuid(),
				'timing' => 'before',
				'resumeOrder' => (int)($ruleData['order'] ?? 0),
				'snapshot' => $snapshot,
				'requesterUserId' => $requesterUserId,
				'approverGroup' => $approverGroup,
				'onReject' => $onReject,
				'onTimeout' => $onTimeout,
				'createdAt' => $now->format('c'),
				'expiresAt' => $expiresAt->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA
		);

		if ($trace !== null) {
			// Execution-trace REQ-004: the suspended run's execution_trace is
			// persisted here (status: 'running') so it is visible while
			// pending, not only after resume.
			try {
				$this->executionTraceService?->persist(trace: $trace, status: 'running');
			} catch (Throwable $exception) {
				$this->logger->warning(
					'ApprovalService: failed to persist the running execution_trace at suspension time.',
					['traceId' => $trace->getTraceId(), 'exception' => $exception->getMessage()]
				);
			}
		}

		$this->notifyApprovers(approvalRequest: $record);

		return $record;
	}//end suspend()

	/**
	 * Create the single `approval_request` gating a Synchronization batch
	 * run (synchronization-engine REQ-015). Unlike the endpoint-rule case
	 * there is no FlowToken snapshot to persist — resume re-runs
	 * `synchronize()` rather than replaying a payload (design.md Decision 6).
	 *
	 * @param string $synchronizationId The gated synchronization's id.
	 * @param string $approverGroup The configured approver group.
	 * @param string $onReject Outcome on reject.
	 * @param string $onTimeout Outcome on timeout.
	 * @param integer $ttlSeconds TTL in seconds before expiry.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function suspendForSynchronization(
		string $synchronizationId,
		string $approverGroup,
		string $onReject,
		string $onTimeout,
		int $ttlSeconds,
	): ObjectEntity {
		$now = new DateTime();
		$expiresAt = (clone $now)->add(new DateInterval('PT' . max($ttlSeconds, 1) . 'S'));

		$record = $this->objectService->saveObject(
			object: [
				'status' => 'pending',
				'synchronizationId' => $synchronizationId,
				'timing' => 'before',
				'snapshot' => [],
				'requesterUserId' => $this->userSession->getUser()?->getUID(),
				'approverGroup' => $approverGroup,
				'onReject' => $onReject,
				'onTimeout' => $onTimeout,
				'createdAt' => $now->format('c'),
				'expiresAt' => $expiresAt->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA
		);

		$this->notifyApprovers(approvalRequest: $record);

		return $record;
	}//end suspendForSynchronization()

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
	public function suspendForFlow(ObjectEntity $flowRun, int $resumeStepOrder, array $config, FlowToken $flowToken): ObjectEntity {
		$approverGroup = (string)($config['approverGroup'] ?? '');
		$ttlSeconds = (int)($config['ttlSeconds'] ?? self::DEFAULT_TTL_SECONDS);
		$onReject = (string)($config['onReject'] ?? 'error');
		$onTimeout = (string)($config['onTimeout'] ?? 'error');

		$now = new DateTime();
		$expiresAt = (clone $now)->add(new DateInterval('PT' . max($ttlSeconds, 1) . 'S'));

		$requesterUserId = $this->userSession->getUser()?->getUID();

		$record = $this->objectService->saveObject(
			object: [
				'status' => 'pending',
				'flowRunId' => $flowRun->getUuid(),
				'resumeStepOrder' => $resumeStepOrder,
				'timing' => 'before',
				'snapshot' => $this->stripSensitiveHeaders(snapshot: $flowToken->__serialize()),
				'requesterUserId' => $requesterUserId,
				'approverGroup' => $approverGroup,
				'onReject' => $onReject,
				'onTimeout' => $onTimeout,
				'createdAt' => $now->format('c'),
				'expiresAt' => $expiresAt->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA
		);

		$this->notifyApprovers(approvalRequest: $record);

		return $record;
	}//end suspendForFlow()

	/**
	 * Create the `approval_request` gating an `api_product_subscription`
	 * whose chosen tier has `requiresApproval: true` (api-product-gateway
	 * REQ-APG-004). Structurally identical to
	 * {@see suspendForSynchronization()} — no FlowToken snapshot, no
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
	): ObjectEntity {
		$now = new DateTime();
		$expiresAt = (clone $now)->add(new DateInterval('PT' . max($ttlSeconds, 1) . 'S'));

		$record = $this->objectService->saveObject(
			object: [
				'status' => 'pending',
				'subscriptionId' => $subscriptionId,
				'timing' => 'before',
				'snapshot' => [],
				'requesterUserId' => $this->userSession->getUser()?->getUID(),
				'approverGroup' => $approverGroup,
				'onReject' => $onReject,
				'onTimeout' => 'error',
				'createdAt' => $now->format('c'),
				'expiresAt' => $expiresAt->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA
		);

		$this->notifyApprovers(approvalRequest: $record);

		return $record;
	}//end suspendForSubscription()

	/**
	 * Find an approved, not-yet-consumed approval_request for a
	 * synchronization (the batch-gate's "has this run already been
	 * approved" check).
	 *
	 * @param string $synchronizationId The synchronization id.
	 *
	 * @return ObjectEntity|null The approved, unconsumed request, or null.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function findApprovedUnconsumedForSynchronization(string $synchronizationId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'synchronizationId' => $synchronizationId,
					'status' => 'approved',
				],
				'limit' => 10,
			]
		);
		$results = ($matches['results'] ?? $matches);

		foreach ($results as $candidate) {
			$data = $candidate->getObject();
			if (empty($data['consumedAt']) === true) {
				return $candidate;
			}
		}

		return null;
	}//end findApprovedUnconsumedForSynchronization()

	/**
	 * Mark a Synchronization batch-gate approval_request consumed once its
	 * gated write phase has completed, so it cannot re-authorize a later run.
	 *
	 * @param ObjectEntity $approvalRequest The approved approval_request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function markConsumed(ObjectEntity $approvalRequest): void {
		$data = $approvalRequest->getObject();
		$data['consumedAt'] = (new DateTime())->format('c');

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $approvalRequest->getUuid()
		);

	}//end markConsumed()

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
	public function rehydrateFlowToken(array $snapshot): FlowToken {
		$flowToken = new FlowToken();
		$flowToken->setRequestOriginal(requestOriginal: ($snapshot['requestOriginal'] ?? []));
		$flowToken->setRequestAmended(requestAmended: ($snapshot['requestAmended'] ?? []));
		$flowToken->setResponseOriginal(responseOriginal: ($snapshot['responseOriginal'] ?? []));
		$flowToken->setResponseAmended(responseAmended: ($snapshot['responseAmended'] ?? []));
		$flowToken->setSyncInputOriginal(syncInputOriginal: ($snapshot['syncInputOriginal'] ?? []));
		$flowToken->setSyncInputAmended(syncInputAmended: ($snapshot['syncInputAmended'] ?? []));
		$flowToken->setSyncOutputOriginal(syncOutputOriginal: ($snapshot['syncOutputOriginal'] ?? []));
		$flowToken->setSyncOutputAmended(syncOutputAmended: ($snapshot['syncOutputAmended'] ?? []));

		return $flowToken;
	}//end rehydrateFlowToken()

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
	public function rehydrateTraceContext(array $snapshot): ?ExecutionTraceContext {
		if (isset($snapshot['traceId']) === false || is_string($snapshot['traceId']) === false || $snapshot['traceId'] === '') {
			return null;
		}

		return new ExecutionTraceContext(
			entryPoint: 'endpoint',
			traceId: $snapshot['traceId'],
			priorSteps: ($snapshot['traceSteps'] ?? [])
		);

	}//end rehydrateTraceContext()

	/**
	 * Find an approval_request by id.
	 *
	 * @param string $id The approval_request uuid.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ApprovalStateException (404) When no such request exists.
	 *
	 * @spec openspec/changes/hitl-approval-rule-action/design.md#api-design
	 */
	public function find(string $id): ObjectEntity {
		try {
			return $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		// Throwable alone: DoesNotExistException is a Throwable, so naming it
		// separately caught nothing extra.
		} catch (Throwable $e) {
			throw new ApprovalStateException(message: 'No such approval request', httpStatus: 404, previous: $e);
		}

	}//end find()

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
	public function isAuthorizedApprover(ObjectEntity $approvalRequest, IUser $user): bool {
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$approverGroup = (string)($approvalRequest->getObject()['approverGroup'] ?? '');
		if ($approverGroup === '') {
			return false;
		}

		return $this->groupManager->isInGroup($user->getUID(), $approverGroup);
	}//end isAuthorizedApprover()

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
	public function assertActionable(ObjectEntity $approvalRequest): void {
		$data = $approvalRequest->getObject();

		if (($data['status'] ?? null) !== 'pending') {
			throw new ApprovalStateException(
				message: 'Approval request is not pending (status: ' . ((string)($data['status'] ?? 'unknown')) . ')',
				httpStatus: 409
			);
		}

		$expiresAt = ($data['expiresAt'] ?? null);
		if ($expiresAt !== null && new DateTime($expiresAt) < new DateTime()) {
			throw new ApprovalStateException(message: 'Approval request has expired', httpStatus: 409);
		}

	}//end assertActionable()

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
	): ObjectEntity {
		$data = $approvalRequest->getObject();
		$data['status'] = 'approved';
		$data['approverUserId'] = $approver->getUID();
		$data['approvedAt'] = (new DateTime())->format('c');
		$data['resumeResult'] = $resumeResult;
		if ($comment !== null && $comment !== '') {
			$data['comment'] = $comment;
		}

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $approvalRequest->getUuid()
		);

	}//end completeApproval()

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
	public function reject(ObjectEntity $approvalRequest, IUser $approver, string $comment): ObjectEntity {
		if (trim($comment) === '') {
			throw new ApprovalStateException(message: 'A comment is required to reject an approval request', httpStatus: 400);
		}

		$data = $approvalRequest->getObject();

		$onReject = ($data['onReject'] ?? 'error');
		$data['status'] = 'rejected';
		if ($onReject === 'dead_letter') {
			$data['status'] = 'dead_letter';
		}

		$data['approverUserId'] = $approver->getUID();
		$data['rejectedAt'] = (new DateTime())->format('c');
		$data['comment'] = $comment;

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $approvalRequest->getUuid()
		);

	}//end reject()

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
	public function sweepExpired(): array {
		$now = new DateTime();
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'status' => 'pending',
				],
				'limit' => 500,
			]
		);
		$results = ($matches['results'] ?? $matches);

		$swept = 0;
		$deadLettered = 0;

		foreach ($results as $approvalRequest) {
			$data = $approvalRequest->getObject();
			$expiresAt = ($data['expiresAt'] ?? null);
			if ($expiresAt === null || new DateTime($expiresAt) >= $now) {
				continue;
			}

			$onTimeout = (string)($data['onTimeout'] ?? 'error');

			$data['status'] = 'expired';
			if ($onTimeout === 'dead_letter') {
				$data['status'] = 'dead_letter';
			}

			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $approvalRequest->getUuid()
			);

			$swept++;
			if ($onTimeout === 'dead_letter') {
				$deadLettered++;
			}
		}//end foreach

		return ['swept' => $swept, 'deadLettered' => $deadLettered];
	}//end sweepExpired()

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
	public function listFor(IUser $user, ?string $statusFilter = null): array {
		$filters = [
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
		];

		if ($statusFilter !== null && $statusFilter !== '') {
			$filters['status'] = explode(',', $statusFilter);
		}

		$matches = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 500]);
		$results = ($matches['results'] ?? $matches);

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return $results;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		return array_values(
			array_filter(
				$results,
				function (ObjectEntity $row) use ($userGroups, $user) {
					$data = $row->getObject();
					return in_array(($data['approverGroup'] ?? ''), $userGroups, true) === true
						|| ($data['requesterUserId'] ?? null) === $user->getUID();
				}
			)
		);

	}//end listFor()

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
	public function notifyApprovers(ObjectEntity $approvalRequest): void {
		$data = $approvalRequest->getObject();
		$approverGroup = (string)($data['approverGroup'] ?? '');
		if ($approverGroup === '') {
			$this->logger->warning(
				'ApprovalService: approval_request created with no approverGroup — no notification sent',
				['id' => $approvalRequest->getUuid()]
			);
			return;
		}

		$group = $this->groupManager->get($approverGroup);
		if ($group === null) {
			$this->logger->warning('ApprovalService: approverGroup does not exist — no notification sent', ['approverGroup' => $approverGroup]);
			return;
		}

		$link = $this->urlGenerator->linkToRouteAbsolute('openconnector.ui.dashboard') . '#/approvals/' . $approvalRequest->getUuid();

		foreach ($group->getUsers() as $groupUser) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('openconnector')
					->setUser($groupUser->getUID())
					->setDateTime(new DateTime())
					->setObject('approval_request', (string)$approvalRequest->getUuid())
					->setSubject('approval_pending', ['approverGroup' => $approverGroup])
					->setLink($link);

				$approveAction = $notification->createAction();
				$approveAction->setLabel('approve')->setLink($link . '?action=approve', 'WEB');
				$notification->addAction($approveAction);

				$rejectAction = $notification->createAction();
				$rejectAction->setLabel('reject')->setLink($link . '?action=reject', 'WEB');
				$notification->addAction($rejectAction);

				$this->notificationManager->notify($notification);
			} catch (Throwable $e) {
				// Best-effort: a single poisoned recipient must not block the
				// others or the suspension itself.
				$this->logger->warning(
					'ApprovalService: failed to notify approver: ' . $e->getMessage(),
					['approverGroup' => $approverGroup]
				);
			}//end try
		}//end foreach

	}//end notifyApprovers()

	/**
	 * Strip sensitive headers (at minimum `Authorization`) from a FlowToken
	 * snapshot's request slots before persisting it — security-hard
	 * requirement, not a nice-to-have (design.md Security Considerations).
	 *
	 * @param array $snapshot The `FlowToken::__serialize()` 8-key array.
	 *
	 * @return array The snapshot with sensitive request headers redacted.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function stripSensitiveHeaders(array $snapshot): array {
		foreach (['requestOriginal', 'requestAmended'] as $slot) {
			if (isset($snapshot[$slot]['headers']) === false || is_array($snapshot[$slot]['headers']) === false) {
				continue;
			}

			foreach (array_keys($snapshot[$slot]['headers']) as $headerName) {
				if (in_array(strtolower((string)$headerName), self::STRIPPED_HEADERS, true) === true) {
					$snapshot[$slot]['headers'][$headerName] = '***redacted***';
				}
			}
		}

		return $snapshot;
	}//end stripSensitiveHeaders()
}//end class
