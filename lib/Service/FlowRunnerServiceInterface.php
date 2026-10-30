<?php

/**
 * Contract of the FlowRunnerService.
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

use OCA\Integriq\Exception\FlowRunException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Public operations of {@see FlowRunnerService} that other components call.
 */
interface FlowRunnerServiceInterface {
	/**
	 * Execute a flow's `steps[]` in `order` sequence (flow-orchestration
	 * REQ-001). Creates a fresh `flow_run` (status `running`) and a fresh
	 * `FlowToken`, seeded with `$input` as the first step's
	 * `syncInputAmended` and, for an endpoint-triggered flow,
	 * `$requestContext` as `requestOriginal` (REQ-002).
	 *
	 * @param ObjectEntity $flow The flow definition to execute.
	 * @param array $input Initial input for the first step (endpoint pipeline data, or empty for cron/event/manual).
	 * @param string|null $triggerSource One of `cron`|`endpoint`|`event`|`manual` (REQ-007/REQ-008).
	 * @param array $requestContext Optional triggering-request array (FlowToken's `IRequest|array` shape) to seed `requestOriginal` (REQ-002).
	 *
	 * @return ObjectEntity The resulting `flow_run`, in its terminal or `suspended` state.
	 *
	 * @throws FlowRunException When the flow's own definition is fatally invalid (duplicate step `order`).
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002
	 */
	public function run(ObjectEntity $flow, array $input = [], ?string $triggerSource = null, array $requestContext = []): ObjectEntity;

	/**
	 * Resume a flow run suspended by an `approval` step, once its
	 * `approval_request` has been approved. Rehydrates the `FlowToken` from
	 * the request's stored snapshot (`ApprovalService::rehydrateFlowToken()`,
	 * reused unmodified) and re-enters the step loop at `resumeStepOrder`
	 * (flow-orchestration REQ-005).
	 *
	 * @param ObjectEntity $approvalRequest The approved `approval_request` (must carry `flowRunId`/`resumeStepOrder`).
	 *
	 * @return ObjectEntity The resumed, now-terminal (or re-`suspended`) `flow_run`.
	 *
	 * @throws DoesNotExistException When the flow_run/flow the request points at no longer exists.
	 * @throws FlowRunException When `resumeStepOrder` no longer resolves to an existing step.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	public function resumeFromApproval(ObjectEntity $approvalRequest): ObjectEntity;

	/**
	 * Mark a suspended flow_run terminal following a rejected or
	 * dead-lettered approval outcome (flow-orchestration REQ-005 — no later
	 * step executes; `stopped` on plain rejection, `dead_letter` when the
	 * approval step's `onReject`/`onTimeout` config says so, mirrored from
	 * the `approval_request`'s own resolved status).
	 *
	 * @param ObjectEntity $approvalRequest The rejected/expired `approval_request` (must carry `flowRunId`).
	 *
	 * @return ObjectEntity|null The finalized `flow_run`, or null when the request carries no `flowRunId` (not a flow-sourced suspension).
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	public function stopFromApprovalOutcome(ObjectEntity $approvalRequest): ?ObjectEntity;

	/**
	 * Find a flow definition by id.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return ObjectEntity
	 *
	 * @throws DoesNotExistException When no such flow exists.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	public function findFlow(string $id): ObjectEntity;
}
