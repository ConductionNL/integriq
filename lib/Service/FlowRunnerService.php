<?php

/**
 * OpenConnector Flow Runner Service.
 *
 * Executes a `flow` OpenRegister object's ordered `steps[]` by dispatching
 * each step to the EXISTING service that already implements that step
 * type's logic — `CallService::call()`, `MappingService::executeMapping()`,
 * `SynchronizationService::synchronize()`, `EventService::emitCloudEvent()`,
 * and `ApprovalService`-backed suspend/resume for `approval` steps. This
 * service owns exactly three things a flow adds that nothing else does:
 * step ordering (by each step's stable `order` field, not array position),
 * condition/branch evaluation (`JWadhams\JsonLogic::apply()`, the same call
 * `EndpointService::checkRuleConditions()` already uses), and per-step
 * error-policy + trace recording. It reuses `flow-token-helper`'s
 * `FlowToken` as-is as the step-to-step data channel — `syncInputAmended`/
 * `syncOutputAmended` thread one step's output into the next step's input
 * (design.md Decision 2); no new generic context object is introduced.
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
 * @spec openspec/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use JWadhams\JsonLogic;
use OCA\OpenConnector\Exception\FlowRunException;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sequential, declarative multi-step flow executor.
 *
 * `EventService` is resolved lazily via the DI container (not
 * constructor-injected) because `EventService::attemptDelivery()` can
 * itself dispatch an `action.kind = 'flow'` message back into this
 * service (Task 14) — a direct two-way constructor dependency would be
 * circular. This mirrors the same lazy-resolution idiom
 * `EndpointService`/`JobService` already use for their own
 * `ContainerInterface $containerInterface` dependency.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/flow-orchestration/spec.md
 */
class FlowRunnerService {

	/**
	 * OpenRegister register slug holding flow objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for a flow definition.
	 *
	 * @var string
	 */
	public const SCHEMA_FLOW = 'flow';

	/**
	 * OR schema slug for a flow run record.
	 *
	 * @var string
	 */
	public const SCHEMA_FLOW_RUN = 'flow_run';

	/**
	 * OR schema slug for a per-step flow run log entry.
	 *
	 * @var string
	 */
	public const SCHEMA_FLOW_RUN_LOG = 'flow_run_log';

	/**
	 * Flow_run.status values that end the run and stamp `finishedAt`.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['completed', 'stopped', 'dead_letter', 'failed'];

	/**
	 * Constructor.
	 *
	 * @param CallService $callService Runs `call` steps.
	 * @param MappingService $mappingService Runs `mapping` steps.
	 * @param SynchronizationService $synchronizationService Runs `synchronization` steps.
	 * @param ApprovalService $approvalService Suspend/rehydrate for `approval` steps.
	 * @param OrObjectService $orObjectService OpenRegister object persistence.
	 * @param ContainerInterface $containerInterface Lazily resolves EventService (breaks the cycle documented above).
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly MappingService $mappingService,
		private readonly SynchronizationService $synchronizationService,
		private readonly ApprovalService $approvalService,
		private readonly OrObjectService $orObjectService,
		private readonly ContainerInterface $containerInterface,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function run(ObjectEntity $flow, array $input = [], ?string $triggerSource = null, array $requestContext = []): ObjectEntity {
		$flowData = $flow->getObject();
		$steps = $this->sortedSteps(steps: ($flowData['steps'] ?? []));
		$this->assertUniqueStepOrders(steps: $steps);

		$flowToken = new FlowToken(requestOriginal: $requestContext);
		$flowToken->setSyncInputAmended(syncInputAmended: $input);

		$flowRun = $this->createFlowRun(flow: $flow, triggerSource: $triggerSource);

		return $this->executeFrom(flow: $flow, steps: $steps, flowToken: $flowToken, flowRun: $flowRun, startOrder: null);
	}//end run()

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
	public function resumeFromApproval(ObjectEntity $approvalRequest): ObjectEntity {
		$data = $approvalRequest->getObject();
		$flowRunId = (string)($data['flowRunId'] ?? '');
		$resumeStepOrder = (int)($data['resumeStepOrder'] ?? 0);

		$flowRun = $this->findFlowRun(id: $flowRunId);
		$flowRunData = $flowRun->getObject();
		$flow = $this->findFlow(id: (string)($flowRunData['flowId'] ?? ''));
		$steps = $this->sortedSteps(steps: ($flow->getObject()['steps'] ?? []));

		$flowToken = $this->approvalService->rehydrateFlowToken(($data['snapshot'] ?? []));

		$flowRunData['status'] = 'running';
		$flowRun = $this->orObjectService->saveObject(
			object: $flowRunData,
			register: self::REGISTER,
			schema: self::SCHEMA_FLOW_RUN,
			uuid: $flowRun->getUuid()
		);

		return $this->executeFrom(flow: $flow, steps: $steps, flowToken: $flowToken, flowRun: $flowRun, startOrder: $resumeStepOrder);
	}//end resumeFromApproval()

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
	public function stopFromApprovalOutcome(ObjectEntity $approvalRequest): ?ObjectEntity {
		$data = $approvalRequest->getObject();
		$flowRunId = (string)($data['flowRunId'] ?? '');
		if ($flowRunId === '') {
			return null;
		}

		try {
			$flowRun = $this->findFlowRun(id: $flowRunId);
		} catch (DoesNotExistException $e) {
			$this->logger->warning('FlowRunnerService: flow_run for rejected approval_request no longer exists', ['flowRunId' => $flowRunId]);
			return null;
		}

		$approvalStatus = (string)($data['status'] ?? 'rejected');
		$flowRunStatus = 'stopped';
		if ($approvalStatus === 'dead_letter') {
			$flowRunStatus = 'dead_letter';
		}

		return $this->finalizeFlowRun(flowRun: $flowRun, status: $flowRunStatus);
	}//end stopFromApprovalOutcome()

	/**
	 * The main step loop, shared by a fresh `run()` and a `resumeFromApproval()`
	 * re-entry. Iterates `$steps` (already sorted by `order` ascending)
	 * starting either from the first step (`$startOrder === null`) or from
	 * the step whose `order` equals `$startOrder`.
	 *
	 * @param ObjectEntity $flow The flow definition (only used for its uuid in log entries via $flowRun).
	 * @param array $steps Steps sorted ascending by `order`.
	 * @param FlowToken $flowToken The in-flight FlowToken.
	 * @param ObjectEntity $flowRun The flow_run being executed/resumed.
	 * @param integer|null $startOrder Resume point (an existing step `order`), or null to start at the first step.
	 *
	 * @return ObjectEntity The flow_run in its terminal or `suspended` state.
	 *
	 * @throws FlowRunException When `$startOrder` does not resolve to an existing step.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	private function executeFrom(ObjectEntity $flow, array $steps, FlowToken $flowToken, ObjectEntity $flowRun, ?int $startOrder): ObjectEntity {
		if (count($steps) === 0) {
			return $this->finalizeFlowRun(flowRun: $flowRun, status: 'completed');
		}

		$indexByOrder = [];
		foreach ($steps as $i => $step) {
			$indexByOrder[(int)$step['order']] = $i;
		}

		$index = 0;
		if ($startOrder !== null) {
			if (isset($indexByOrder[$startOrder]) === false) {
				$this->finalizeFlowRun(flowRun: $flowRun, status: 'failed');
				throw new FlowRunException(message: 'Resume step order ' . $startOrder . ' does not resolve to an existing step');
			}

			$index = $indexByOrder[$startOrder];
		}

		while ($index < count($steps)) {
			$step = $steps[$index];
			$order = (int)$step['order'];
			$type = (string)($step['type'] ?? '');

			$context = $this->buildContext(flowToken: $flowToken);

			if ($this->conditionPasses(condition: ($step['condition'] ?? null), context: $context) === false) {
				$this->appendLog(flowRun: $flowRun, stepOrder: $order, type: $type, status: 'skipped');
				$index++;
				continue;
			}

			if ($type === 'branch') {
				$target = $this->selectBranchTarget(step: $step, context: $context);
				$this->appendLog(flowRun: $flowRun, stepOrder: $order, type: $type, status: 'completed');

				if ($target === null) {
					$index++;
					continue;
				}

				if (isset($indexByOrder[$target]) === false) {
					return $this->finalizeFlowRun(flowRun: $flowRun, status: 'failed');
				}

				$index = $indexByOrder[$target];
				continue;
			}//end if

			if ($type === 'approval') {
				$nextOrder = ($steps[($index + 1)]['order'] ?? null);
				$resumeStepOrder = $order;
				if ($nextOrder !== null) {
					$resumeStepOrder = (int)$nextOrder;
				}

				$this->dispatchApproval(step: $step, flowRun: $flowRun, flowToken: $flowToken, resumeStepOrder: $resumeStepOrder);
				$this->appendLog(flowRun: $flowRun, stepOrder: $order, type: $type, status: 'completed');
				return $this->suspendFlowRun(flowRun: $flowRun);
			}

			$startedAt = new DateTime();
			try {
				$result = $this->dispatchStep(step: $step, flowToken: $flowToken);
				// Design.md Decision 2: a step's result becomes the NEXT
				// step's syncInputAmended, not just its own syncOutputAmended
				// snapshot — dispatchStep() always reads syncInputAmended for
				// "the previous step's output (or the flow's initial input)",
				// so both slots must carry the same value here.
				$flowToken->setSyncOutputAmended(syncOutputAmended: $result);
				$flowToken->setSyncInputAmended(syncInputAmended: $result);
				$this->appendLog(flowRun: $flowRun, stepOrder: $order, type: $type, status: 'completed', startedAt: $startedAt);
				$index++;
			} catch (Throwable $e) {
				$this->appendLog(flowRun: $flowRun, stepOrder: $order, type: $type, status: 'failed', startedAt: $startedAt, error: $e->getMessage());

				$onError = (string)($step['onError'] ?? 'stop');
				if ($onError === 'continue') {
					$index++;
					continue;
				}

				$terminal = 'stopped';
				if ($onError === 'dead_letter') {
					$terminal = 'dead_letter';
				}

				return $this->finalizeFlowRun(flowRun: $flowRun, status: $terminal);
			}//end try
		}//end while

		return $this->finalizeFlowRun(flowRun: $flowRun, status: 'completed');
	}//end executeFrom()

	/**
	 * Dispatch a `call`/`mapping`/`synchronization`/`event` step to the
	 * existing service that implements it (design.md Decision 3). Only
	 * resolves `configRef`/`config` and forwards — no step arm reimplements
	 * source-calling, mapping-transform, or sync-batching logic.
	 *
	 * @param array $step The step definition.
	 * @param FlowToken $flowToken The in-flight FlowToken (read for input, not mutated here).
	 *
	 * @return array The step's result, threaded into the next step via `syncOutputAmended`.
	 *
	 * @throws FlowRunException For an unsupported step type.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	private function dispatchStep(array $step, FlowToken $flowToken): array {
		return match ($step['type'] ?? '') {
			'call' => $this->dispatchCall(step: $step, flowToken: $flowToken),
			'mapping' => $this->dispatchMapping(step: $step, flowToken: $flowToken),
			'synchronization' => $this->dispatchSynchronization(step: $step, flowToken: $flowToken),
			'event' => $this->dispatchEvent(step: $step, flowToken: $flowToken),
			default => throw new FlowRunException(message: 'Unsupported flow step type: ' . ((string)($step['type'] ?? ''))),
		};

	}//end dispatchStep()

	/**
	 * `call` step: resolve `configRef` to a Source and forward to
	 * `CallService::call()` unchanged.
	 *
	 * @param array $step The step definition (`configRef` = Source id, `config.endpoint`/`config.method`/`config.requestConfig`).
	 * @param FlowToken $flowToken Unused directly — `call` does not consume the prior step's
	 *                             output as its own input (the Source/endpoint/config fully
	 *                             determine the request).
	 *
	 * @return array The response block (`statusCode`, `headers`, `body`, …), with a JSON
	 *               body decoded into an array when possible.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	private function dispatchCall(array $step, FlowToken $flowToken): array {
		$config = (array)($step['config'] ?? []);
		$source = $this->orObjectService->find(
			id: (string)($step['configRef'] ?? ''),
			register: self::REGISTER,
			schema: 'source',
			_rbac: false,
			_multitenancy: false
		);

		$callLog = $this->callService->call(
			source: $source,
			endpoint: (string)($config['endpoint'] ?? ''),
			method: (string)($config['method'] ?? 'GET'),
			config: (array)($config['requestConfig'] ?? [])
		);
		$responseData = (array)($callLog->getObject()['response'] ?? []);

		$decodedBody = json_decode((string)($responseData['body'] ?? ''), true);
		if (is_array($decodedBody) === true) {
			$responseData['body'] = $decodedBody;
		}

		return $responseData;
	}//end dispatchCall()

	/**
	 * `mapping` step: resolve `configRef` to a Mapping and forward the
	 * current `syncInputAmended` to `MappingService::executeMapping()`
	 * unchanged.
	 *
	 * @param array $step The step definition (`configRef` = Mapping id).
	 * @param FlowToken $flowToken Read for `syncInputAmended` (the previous step's output, or the flow's initial input).
	 *
	 * @return array The mapped result.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002
	 */
	private function dispatchMapping(array $step, FlowToken $flowToken): array {
		return $this->mappingService->executeMapping(
			mapping: (string)($step['configRef'] ?? ''),
			input: $flowToken->getSyncInputAmended()
		);

	}//end dispatchMapping()

	/**
	 * `synchronization` step: resolve `configRef` to a Synchronization and
	 * forward to `SynchronizationService::synchronize()` unchanged,
	 * threading `$flowToken` through by reference so `sync-safety`'s guards
	 * (batch-approval gate, dedup, etc.) run exactly as they do for a
	 * directly-triggered sync. Deliberately never passes `approvalRequestId`
	 * — a flow step MUST NOT bypass the batch-approval gate (design.md
	 * Decision 3 / Task 20).
	 *
	 * @param array $step The step definition (`configRef` = Synchronization id,
	 *                    optional `config.isTest`/`config.force`/`config.mutationType`).
	 * @param FlowToken $flowToken Read for `syncInputAmended`; threaded through by reference
	 *                             to `synchronize()`.
	 *
	 * @return array The synchronization run's result log.
	 *
	 * @spec openspec/changes/archive/2026-07-15-visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation
	 */
	private function dispatchSynchronization(array $step, FlowToken $flowToken): array {
		$config = (array)($step['config'] ?? []);
		$synchronization = $this->synchronizationService->getSynchronization(id: (string)($step['configRef'] ?? ''));

		$mutationType = null;
		if (isset($config['mutationType']) === true) {
			$mutationType = (string)$config['mutationType'];
		}

		$result = $this->synchronizationService->synchronize(
			synchronization: $synchronization,
			isTest: (bool)($config['isTest'] ?? false),
			force: (bool)($config['force'] ?? false),
			mutationType: $mutationType,
			data: $flowToken->getSyncInputAmended(),
			flowToken: $flowToken
		);

		return (array)$result;
	}//end dispatchSynchronization()

	/**
	 * `event` step: forward to `EventService::emitCloudEvent()` unchanged
	 * (design.md Decision 5 — v1 targets the CloudEvents pipeline only, not
	 * raw `IEventDispatcher`). `EventService` is resolved lazily via the DI
	 * container to avoid a constructor cycle (see class docblock).
	 *
	 * @param array $step The step definition (`config.type`/`config.source`/`config.subject`).
	 * @param FlowToken $flowToken Read for `syncInputAmended`, forwarded as the CloudEvent's `data`.
	 *
	 * @return array A small summary (`emitted`, `messageCount`) — the flow step's own
	 *               "result" for context-threading purposes; `emitCloudEvent()` itself
	 *               has no transformable return value to pass to the next step.
	 *
	 * @spec openspec/changes/archive/2026-07-15-visual-flow-orchestration/design.md#decision-5-event-step-targets-eventservices-cloudevents-pipeline-not-raw-ieventdispatcher
	 */
	private function dispatchEvent(array $step, FlowToken $flowToken): array {
		$config = (array)($step['config'] ?? []);
		$eventService = $this->containerInterface->get(EventService::class);

		$subject = null;
		if (isset($config['subject']) === true) {
			$subject = (string)$config['subject'];
		}

		$messages = $eventService->emitCloudEvent(
			type: (string)($config['type'] ?? ''),
			source: (string)($config['source'] ?? ''),
			subject: $subject,
			data: $flowToken->getSyncInputAmended()
		);

		return ['emitted' => true, 'messageCount' => count($messages)];
	}//end dispatchEvent()

	/**
	 * `approval` step: persist a `pending` `approval_request` carrying
	 * `flowRunId`/`resumeStepOrder` via `ApprovalService::suspendForFlow()`
	 * (mirroring `ApprovalService::suspend()`'s own persistence shape —
	 * design.md Decision 4).
	 *
	 * @param array $step The step definition (`config.approverGroup`/`config.onReject`/`config.onTimeout`/`config.ttlSeconds`).
	 * @param ObjectEntity $flowRun The in-flight flow_run being suspended.
	 * @param FlowToken $flowToken The in-flight FlowToken, snapshotted into the approval_request.
	 * @param integer $resumeStepOrder The step `order` to resume at once approved.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	private function dispatchApproval(array $step, ObjectEntity $flowRun, FlowToken $flowToken, int $resumeStepOrder): ObjectEntity {
		$config = (array)($step['config'] ?? []);

		return $this->approvalService->suspendForFlow(
			flowRun: $flowRun,
			resumeStepOrder: $resumeStepOrder,
			config: $config,
			flowToken: $flowToken
		);

	}//end dispatchApproval()

	/**
	 * Evaluate a `branch` step's `branches[]` in array order via
	 * `JsonLogic::apply()`; the first matching entry's `nextStepOrder` wins.
	 * Falls back to `defaultNextStepOrder` when present, or `null` (continue
	 * sequentially) when neither matches (flow-orchestration REQ-004).
	 *
	 * @param array $step The `branch` step definition.
	 * @param array $context The current step-evaluation context (see {@see buildContext()}).
	 *
	 * @return integer|null The selected step `order`, or null to continue in sequence.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
	 */
	private function selectBranchTarget(array $step, array $context): ?int {
		foreach (($step['branches'] ?? []) as $branch) {
			$condition = ($branch['condition'] ?? null);
			if (empty($condition) === true) {
				continue;
			}

			if ((bool)JsonLogic::apply($condition, $context) === true) {
				$nextStepOrder = null;
				if (isset($branch['nextStepOrder']) === true) {
					$nextStepOrder = (int)$branch['nextStepOrder'];
				}

				return $nextStepOrder;
			}
		}

		if (isset($step['defaultNextStepOrder']) === true) {
			return (int)$step['defaultNextStepOrder'];
		}

		return null;
	}//end selectBranchTarget()

	/**
	 * Evaluate a step's optional `condition` via `JsonLogic::apply()` — the
	 * same static call `EndpointService::checkRuleConditions()` already
	 * makes for endpoint rules. Absent/empty condition always passes
	 * (flow-orchestration REQ-003).
	 *
	 * @param array|null $condition The step's JsonLogic condition, or null/empty.
	 * @param array $context The current step-evaluation context.
	 *
	 * @return boolean
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- JWadhams\JsonLogic exposes only a static
	 * `apply()` entry point, same convention EndpointService's rule-condition engine
	 * already uses.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003
	 */
	private function conditionPasses(?array $condition, array $context): bool {
		if (empty($condition) === true) {
			return true;
		}

		return (bool)JsonLogic::apply($condition, $context) === true;
	}//end conditionPasses()

	/**
	 * Build the JsonLogic evaluation context for a step's `condition`/
	 * `branches[].condition` from the current FlowToken slots.
	 *
	 * @param FlowToken $flowToken The in-flight FlowToken.
	 *
	 * @return array{syncInputAmended: array, syncOutputAmended: array, requestOriginal: array, requestAmended: array}
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002
	 */
	private function buildContext(FlowToken $flowToken): array {
		return [
			'syncInputAmended' => $flowToken->getSyncInputAmended(),
			'syncOutputAmended' => $flowToken->getSyncOutputAmended(),
			'requestOriginal' => $flowToken->getRequestOriginal(),
			'requestAmended' => $flowToken->getRequestAmended(),
		];

	}//end buildContext()

	/**
	 * Sort a flow's `steps[]` by their `order` field ascending — execution
	 * sequence is `order`, never array position (flow-orchestration
	 * REQ-001).
	 *
	 * @param array $steps Raw `steps[]` from `flow.getObject()`.
	 *
	 * @return array Steps sorted ascending by `order`.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	private function sortedSteps(array $steps): array {
		usort(
			$steps,
			static fn (array $a, array $b): int => (((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)))
		);

		return $steps;
	}//end sortedSteps()

	/**
	 * Reject a flow whose `steps[]` carry duplicate `order` values — an
	 * ambiguous execution sequence is a fatal configuration error, checked
	 * before any step runs (Task 1 acceptance criterion).
	 *
	 * @param array $steps Steps already sorted by `order` (duplicates are adjacent).
	 *
	 * @return void
	 *
	 * @throws FlowRunException When two or more steps share the same `order`.
	 *
	 * @spec openspec/changes/archive/2026-07-15-visual-flow-orchestration/tasks.md#task-1
	 */
	private function assertUniqueStepOrders(array $steps): void {
		$seen = [];
		foreach ($steps as $step) {
			$order = (int)($step['order'] ?? 0);
			if (isset($seen[$order]) === true) {
				throw new FlowRunException(message: 'Flow has duplicate step order: ' . $order);
			}

			$seen[$order] = true;
		}

	}//end assertUniqueStepOrders()

	/**
	 * Create a fresh `flow_run` (status `running`, `startedAt` now).
	 *
	 * @param ObjectEntity $flow The flow being run.
	 * @param string|null $triggerSource `cron`|`endpoint`|`event`|`manual`.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008
	 */
	private function createFlowRun(ObjectEntity $flow, ?string $triggerSource): ObjectEntity {
		return $this->orObjectService->saveObject(
			object: [
				'flowId' => $flow->getUuid(),
				'triggerSource' => $triggerSource,
				'status' => 'running',
				'startedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_FLOW_RUN
		);

	}//end createFlowRun()

	/**
	 * Set a flow_run's status to `suspended` (no `finishedAt` — the run is
	 * paused, not terminal).
	 *
	 * @param ObjectEntity $flowRun The flow_run to suspend.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	private function suspendFlowRun(ObjectEntity $flowRun): ObjectEntity {
		$data = $flowRun->getObject();
		$data['status'] = 'suspended';

		return $this->orObjectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_FLOW_RUN,
			uuid: $flowRun->getUuid()
		);

	}//end suspendFlowRun()

	/**
	 * Set a flow_run's terminal status and, for a genuinely terminal status,
	 * stamp `finishedAt`.
	 *
	 * @param ObjectEntity $flowRun The flow_run to finalize.
	 * @param string $status One of `completed`|`stopped`|`dead_letter`|`failed`.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008
	 */
	private function finalizeFlowRun(ObjectEntity $flowRun, string $status): ObjectEntity {
		$data = $flowRun->getObject();
		$data['status'] = $status;
		if (in_array($status, self::TERMINAL_STATUSES, true) === true) {
			$data['finishedAt'] = (new DateTime())->format('c');
		}

		return $this->orObjectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_FLOW_RUN,
			uuid: $flowRun->getUuid()
		);

	}//end finalizeFlowRun()

	/**
	 * Append one `flow_run_log` entry.
	 *
	 * @param ObjectEntity $flowRun The owning flow_run.
	 * @param integer $stepOrder The step's `order` value.
	 * @param string $type The step's type.
	 * @param string $status `completed`|`skipped`|`failed`.
	 * @param DateTime|null $startedAt Defaults to now when omitted (e.g. a `skipped` entry).
	 * @param string|null $error Present only when `status === 'failed'`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008
	 */
	private function appendLog(
		ObjectEntity $flowRun,
		int $stepOrder,
		string $type,
		string $status,
		?DateTime $startedAt = null,
		?string $error = null,
	): void {
		$startedAt ??= new DateTime();

		$entry = [
			'flowRunId' => $flowRun->getUuid(),
			'stepOrder' => $stepOrder,
			'type' => $type,
			'status' => $status,
			'startedAt' => $startedAt->format('c'),
			'finishedAt' => (new DateTime())->format('c'),
		];

		if ($error !== null) {
			$entry['error'] = $error;
		}

		$this->orObjectService->saveObject(
			object: $entry,
			register: self::REGISTER,
			schema: self::SCHEMA_FLOW_RUN_LOG
		);

	}//end appendLog()

	/**
	 * Find a flow_run by id.
	 *
	 * @param string $id The flow_run uuid.
	 *
	 * @return ObjectEntity
	 *
	 * @throws DoesNotExistException When no such flow_run exists.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	private function findFlowRun(string $id): ObjectEntity {
		return $this->orObjectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA_FLOW_RUN, _rbac: false, _multitenancy: false);
	}//end findFlowRun()

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
	public function findFlow(string $id): ObjectEntity {
		return $this->orObjectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA_FLOW, _rbac: false, _multitenancy: false);
	}//end findFlow()
}//end class
