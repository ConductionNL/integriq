<?php

/**
 * Integriq Approval Request flow node.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `openconnector.approval-request` — the `approval` step of the retired
 * app-local flow runner, re-homed on OpenRegister's engine. The node persists
 * a `pending` `approval_request` (the HITL system of record, mirrored onto the
 * shared task service by `ApprovalService`) and then suspends the run exactly
 * the way `openregister.await-signal` does: a `FlowSuspension` with a
 * heartbeat, answered either by a delivered signal
 * (`FlowRunSignalService::answerByUuid()` from `ApprovalsController`) or — when
 * that delivery was lost — by the heartbeat re-reading the approval_request
 * itself. The record is the authority; the signal is the fast path.
 *
 * DECISION SEMANTICS
 * ------------------
 * - **approved** — the decision payload is written onto every item's record
 *   under `config.signalKey` (default `approval`), so the steps after this one
 *   can read who decided and route on it. The run continues on its edges.
 * - **rejected** — with `failOnReject: true` the run ends as `failed`
 *   (`FlowStop`, error). Without it the decision is written like an approval
 *   and the author routes the reject edge on
 *   `json.<signalKey>.decision` — being told "no" is the flow working.
 * - **expired** — fails closed, always (`FlowStop`, error). An approval nobody
 *   answered must never quietly count as answered.
 *
 * WHY THE HEARTBEAT RE-READS THE RECORD
 * -------------------------------------
 * A signal can be delivered while the run has not suspended yet, or its
 * delivery can simply fail. `AwaitSignalNode`'s answer to that is a heartbeat
 * that re-asks; this node has something better to re-ask than "did anything
 * arrive?" — the approval_request row, which `ApprovalsController` resolves
 * regardless of whether the signal made it. A lost signal therefore costs one
 * heartbeat, never the flow.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use DateTime;
use OCA\Integriq\Kernel\FlowNodeIds;
use OCA\Integriq\Service\ApprovalServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Asks a person, parks the run, and carries their answer onto the items.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The count is the engine
 * vocabulary itself — FlowSuspension/FlowStop/FlowItems/FlowNodeResumeState
 * plus the three node interfaces — the same fan-in every sibling node
 * carries (they sit in phpmd.baseline.xml for the identical reason).
 * @SuppressWarnings(PHPMD.StaticAccess) FlowConfigGuard and FlowNodeSupport
 * are the shared static config guards every Integriq node validates
 * through; instantiating them would add state to say the same thing.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */
class ApprovalRequestNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {
	use FlowNodePaletteDefaults;

	/**
	 * Palette icon: app and image path.
	 */
	private const PALETTE_ICON = ['core', 'actions/confirm.svg'];

	/**
	 * The step type this node answers to.
	 *
	 * FROZEN on `openconnector.*` — the id is written into stored flow
	 * documents, so it survives the openconnector -> integriq app-id rename.
	 *
	 * @var string
	 */
	public const NODE_ID = FlowNodeIds::APPROVAL_REQUEST;

	/**
	 * The context key the engine delivers a signal payload under.
	 *
	 * Mirrors `FlowRunService::SIGNAL_CONTEXT_KEY`. Declared locally (like
	 * `FlowNodeSupport::ON_ERROR_POLICIES`) so reading it never pulls the run
	 * service into scope on an instance without the flow engine.
	 *
	 * @var string
	 */
	private const SIGNAL_CONTEXT_KEY = 'signal';

	/**
	 * The context key carrying the engine run's uuid.
	 *
	 * Mirrors `FlowRunContext::CONTEXT_RUN`. The uuid is what the approval
	 * resolution later addresses through `FlowRunSignalService::answerByUuid()`,
	 * so a run that cannot name itself cannot be approved and is refused.
	 *
	 * @var string
	 */
	private const RUN_CONTEXT_KEY = 'x-openregister-attribution-run';

	/**
	 * Minutes between heartbeats when the step does not choose.
	 *
	 * Matches `AwaitSignalNode`'s default: short enough that a lost signal is
	 * an inconvenience, long enough that a fortnight-long approval stays
	 * cheap.
	 *
	 * @var int
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 15;

	/**
	 * The floor a configured heartbeat is clamped to.
	 *
	 * The stock system cron runs every five minutes; asking for less buys the
	 * same behaviour while looking like it bought more.
	 *
	 * @var int
	 */
	private const MIN_HEARTBEAT_MINUTES = 5;

	/**
	 * The item key the decision payload is written under by default.
	 *
	 * @var string
	 */
	private const DEFAULT_SIGNAL_KEY = 'approval';

	/**
	 * Constructor.
	 *
	 * @param ApprovalServiceInterface $approvalService The HITL state machine — persistence, mirror task, notifications.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly ApprovalServiceInterface $approvalService,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Ask for approval');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Pause the flow until someone in the approver group approves or rejects. An expired request fails the run.'
		);

	}//end getDescription()

	/**
	 * The node's whole config vocabulary.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function configKeys(): array {
		return [
			'question',
			'approverGroup',
			'ttlSeconds',
			'failOnReject',
			'signalKey',
			'heartbeatMinutes',
			'onError',
		];
	}//end configKeys()

	/**
	 * The fields this node's configuration is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'question',
				'label' => $this->l10n->t('What is being asked'),
				'type' => 'text',
				'help' => $this->l10n->t('Shown to the approvers and written on the request, so a paused flow explains itself.'),
				'required' => true,
			],
			[
				'key' => 'approverGroup',
				'label' => $this->l10n->t('Approver group'),
				'type' => 'text',
				'help' => $this->l10n->t('Members of this group (and admins) may answer. Required: a request nobody owns is a request nobody answers.'),
				'required' => true,
			],
			[
				'key' => 'ttlSeconds',
				'label' => $this->l10n->t('Expires after (seconds)'),
				'type' => 'number',
				'help' => $this->l10n->t('An unanswered request expires and fails the run. Defaults to 24 hours.'),
			],
			[
				'key' => 'failOnReject',
				'label' => $this->l10n->t('Treat a rejection as a failure'),
				'type' => 'boolean',
				'help' => $this->l10n->t('Off by default: a "no" continues the flow with the decision on the items, so a later step can route on it.'),
			],
			[
				'key' => 'signalKey',
				'label' => $this->l10n->t('Field to store the decision in'),
				'type' => 'text',
				'help' => $this->l10n->t('The decision is written onto every item under this field. Defaults to "approval".'),
			],
			[
				'key' => 'heartbeatMinutes',
				'label' => $this->l10n->t('Re-check every (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t('Safety net for a lost answer. Lower is not faster: a decision wakes the run immediately either way.'),
			],
		];
	}//end configForm()

	/**
	 * Reject a configuration the author cannot have meant, at flow-save time.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);

		if (trim((string)($config['question'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being asked ("question"), or nobody can answer it.')
			);
		}

		if (trim((string)($config['approverGroup'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Name the approver group ("approverGroup"): an approval without an audience never resolves.')
			);
		}

		if (array_key_exists('ttlSeconds', $config) === true
			&& (is_numeric($config['ttlSeconds']) === false || ((int)$config['ttlSeconds']) < 1)
		) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "ttlSeconds" field must be a positive number of seconds when set.')
			);
		}

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Ask, suspend, and carry the decision onto the items when it arrives.
	 *
	 * @param FlowNodeCall $call The input items, the step's configuration and the run context.
	 *
	 * @return array The items, each carrying the decision under the signal key.
	 *
	 * @throws FlowSuspension While the request is pending.
	 * @throws FlowStop When the request was rejected under `failOnReject`, or expired (fail closed).
	 * @throws FlowNodeException When the run cannot be addressed for a later answer.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function execute(FlowNodeCall $call): array {
		$this->validateConfig(config: $call->config);

		$decision = $this->decisionFrom(context: $call->context);
		if ($decision !== null) {
			return $this->applyDecision(call: $call, decision: $decision);
		}

		return $this->awaitDecision(call: $call, resume: $this->resumeSlot(context: $call->context));

	}//end execute()

	/**
	 * This node's resume slot.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return FlowNodeResumeState The slot.
	 *
	 * @throws FlowNodeException When the engine dispatched the node without one.
	 */
	private function resumeSlot(array $context): FlowNodeResumeState {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			// Without a resume slot every heartbeat would open a fresh
			// request. That is a broken dispatch, not a pending approval.
			throw new FlowNodeException(
				message: $this->l10n->t('The approval step has no resume slot; the engine did not dispatch it as a resumable node.')
			);
		}

		return $resume;

	}//end resumeSlot()

	/**
	 * Without a delivered decision: answer from the open request's record,
	 * or open the request and suspend.
	 *
	 * @param FlowNodeCall $call The input items, the step's configuration and the run context.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return array The items carrying the decision, when the record resolved.
	 *
	 * @throws FlowSuspension While the request is pending.
	 * @throws FlowStop When the record ended without an approval the run may continue on.
	 * @throws FlowNodeException When the run cannot be addressed for a later answer.
	 */
	private function awaitDecision(FlowNodeCall $call, FlowNodeResumeState $resume): array {
		if ($resume->has('approvalRequestId') === true) {
			return $this->answerFromRecord(call: $call, resume: $resume);
		}

		$this->openRequest(call: $call, resume: $resume);

		throw $this->suspension(
			config: $call->config,
			reason: sprintf('waiting for approval: %s', trim((string)$call->config['question']))
		);

	}//end awaitDecision()

	/**
	 * Persist the pending approval_request and stamp the resume slot.
	 *
	 * The slot's `assignee` is the approver group, which is what
	 * OpenRegister's own signal guard (`FlowRunAssignee`) checks a signaller
	 * against — so the engine-side guard and Integriq's own approver-group
	 * authorization name the same audience.
	 *
	 * @param FlowNodeCall $call The step's authored configuration and run-level metadata.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the run has no uuid to answer at.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function openRequest(FlowNodeCall $call, FlowNodeResumeState $resume): void {
		$record = $this->approvalService->suspendForEngineRun(
			engineRunUuid: $this->runUuidOf(context: $call->context),
			signalNodeId: $resume->nodeId(),
			config: $call->config,
			requesterUid: trim((string)($call->context['triggeredBy'] ?? ''))
		);

		$resume->merge(values: $this->pendingSlot(record: $record, config: $call->config));

	}//end openRequest()

	/**
	 * The uuid of the run an approval must answer.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return string The run uuid.
	 *
	 * @throws FlowNodeException When the run has no uuid to answer at.
	 */
	private function runUuidOf(array $context): string {
		$runUuid = trim((string)($context[self::RUN_CONTEXT_KEY] ?? ''));
		if ($runUuid === '') {
			// A request created for an unaddressable run could be approved and
			// still resume nothing. Refuse loudly instead.
			throw new FlowNodeException(
				message: $this->l10n->t(
					'This run carries no uuid, so an approval could never answer it. The approval step is only usable in a persisted flow run.'
				)
			);
		}

		return $runUuid;

	}//end runUuidOf()

	/**
	 * The resume slot values of a just-opened request.
	 *
	 * @param ObjectEntity $record The pending approval_request.
	 * @param array $config The step's authored configuration.
	 *
	 * @return array<string,string> The slot values.
	 */
	private function pendingSlot(ObjectEntity $record, array $config): array {
		$data = $record->getObject();
		return [
			'approvalRequestId' => $record->getUuid(),
			'askedAt' => (new DateTime())->format('c'),
			'question' => trim((string)$config['question']),
			'assignee' => trim((string)$config['approverGroup']),
			'expiresAt' => (string)($data['expiresAt'] ?? ''),
		];

	}//end pendingSlot()

	/**
	 * The heartbeat's answer when no signal made it: ask the record itself.
	 *
	 * The approval_request is the system of record and `ApprovalsController`
	 * resolves it whether or not the signal delivery succeeded, so a resolved
	 * record with no delivered signal means the answer exists and only the
	 * wake-up was lost.
	 *
	 * @param FlowNodeCall $call The input items and the step's authored configuration.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return array The items carrying the decision, when the record resolved.
	 *
	 * @throws FlowSuspension While the record is still pending and unexpired.
	 * @throws FlowStop When the record expired, was dead-lettered, or was rejected under `failOnReject`.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function answerFromRecord(FlowNodeCall $call, FlowNodeResumeState $resume): array {
		$requestId = (string)$resume->get(key: 'approvalRequestId', default: '');
		$data = $this->recordData(requestId: $requestId);

		$status = (string)($data['status'] ?? 'pending');
		if ($status === 'approved' || $status === 'rejected') {
			return $this->applyDecision(call: $call, decision: $this->recordedDecision(data: $data, requestId: $requestId));
		}

		$this->guardUnresolved(data: $data, resume: $resume);

		throw $this->suspension(
			config: $call->config,
			reason: sprintf('still waiting for approval: %s', (string)$resume->get(key: 'question', default: 'approval'))
		);

	}//end answerFromRecord()

	/**
	 * The data of the open approval_request.
	 *
	 * @param string $requestId The approval_request uuid.
	 *
	 * @return array<string,mixed> The record's object data.
	 *
	 * @throws FlowStop When the record no longer exists.
	 */
	private function recordData(string $requestId): array {
		try {
			// System read: the flow polls the request it opened itself, with no user session.
			$record = $this->approvalService->find(id: $requestId, asSystem: true);
			return $record->getObject();
		} catch (Throwable $e) {
			// A vanished record can never resolve; waiting longer cannot fix it.
			throw $this->failClosed(reason: sprintf('Approval request %s no longer exists; failing closed.', $requestId));
		}

	}//end recordData()

	/**
	 * The decision an approved or rejected record carries.
	 *
	 * @param array<string,mixed> $data The approval_request's object data.
	 * @param string $requestId The approval_request uuid.
	 *
	 * @return array<string,string> The decision payload.
	 */
	private function recordedDecision(array $data, string $requestId): array {
		return [
			'decision' => (string)($data['status'] ?? 'pending'),
			'decidedBy' => (string)($data['approverUserId'] ?? ''),
			'comment' => (string)($data['comment'] ?? ''),
			'approvalRequestId' => $requestId,
		];

	}//end recordedDecision()

	/**
	 * Fail closed on a record that ended without a decision, or is past its deadline.
	 *
	 * @param array<string,mixed> $data The approval_request's object data.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return void
	 *
	 * @throws FlowStop When the record was dead-lettered, expired, or is in a state this node does not know.
	 */
	private function guardUnresolved(array $data, FlowNodeResumeState $resume): void {
		$requestId = (string)$resume->get(key: 'approvalRequestId', default: '');
		$status = (string)($data['status'] ?? 'pending');
		if ($status === 'dead_letter') {
			throw $this->failClosed(reason: sprintf('Approval request %s was dead-lettered.', $requestId));
		}

		if ($status !== 'pending' || $this->hasExpired(data: $data, resume: $resume) === true) {
			// `expired` from the sweep, a past `expiresAt` the sweep has not
			// reached yet, or any state this node does not know: fail closed.
			throw $this->failClosed(
				reason: sprintf('Approval request %s was not answered in time (status: %s); failing closed.', $requestId, $status)
			);
		}

	}//end guardUnresolved()

	/**
	 * The error stop of a request that can no longer be answered.
	 *
	 * @param string $reason Why the run stops.
	 *
	 * @return FlowStop The stop to throw.
	 */
	private function failClosed(string $reason): FlowStop {
		return new FlowStop(reason: $reason, isError: true);

	}//end failClosed()

	/**
	 * The suspension until the next heartbeat.
	 *
	 * @param array $config The step's authored configuration.
	 * @param string $reason What the run waits for.
	 *
	 * @return FlowSuspension The suspension to throw.
	 */
	private function suspension(array $config, string $reason): FlowSuspension {
		return new FlowSuspension(resumeAt: $this->heartbeatAt(config: $config), reason: $reason);

	}//end suspension()

	/**
	 * Whether the pending record's deadline has passed.
	 *
	 * @param array $data The approval_request's object data.
	 * @param FlowNodeResumeState $resume This node's resume slot (fallback deadline).
	 *
	 * @return boolean True when expired.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function hasExpired(array $data, FlowNodeResumeState $resume): bool {
		$expiresAt = trim((string)($data['expiresAt'] ?? $resume->get(key: 'expiresAt', default: '')));
		if ($expiresAt === '') {
			return false;
		}

		try {
			return new DateTime($expiresAt) < new DateTime();
		} catch (Throwable $e) {
			// An unreadable deadline must not read as "never expires".
			return true;
		}

	}//end hasExpired()

	/**
	 * Write the decision onto every item, honouring `failOnReject`.
	 *
	 * @param FlowNodeCall $call The input items and the step's authored configuration.
	 * @param array $decision The decision payload.
	 *
	 * @return array The items, each carrying the decision under the signal key.
	 *
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function applyDecision(FlowNodeCall $call, array $decision): array {
		$this->refuseRejection(config: $call->config, decision: $decision);

		return $this->withDecision(call: $call, decision: $decision);

	}//end applyDecision()

	/**
	 * Stop the run on a rejection when the step asked to fail on it.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $decision The decision payload.
	 *
	 * @return void
	 *
	 * @throws FlowStop When rejected under `failOnReject`.
	 */
	private function refuseRejection(array $config, array $decision): void {
		$verdict = strtolower(trim((string)($decision['decision'] ?? '')));

		if (($verdict === 'reject' || $verdict === 'rejected') && ($config['failOnReject'] ?? false) === true) {
			throw new FlowStop(
				reason: sprintf(
					'Rejected: %s',
					trim((string)($decision['comment'] ?? $config['question'] ?? 'no reason given'))
				),
				isError: true
			);
		}

	}//end refuseRejection()

	/**
	 * Every item, carrying the decision under the step's signal key.
	 *
	 * @param FlowNodeCall $call The input items and the step's authored configuration.
	 * @param array $decision The decision payload.
	 *
	 * @return array The items.
	 */
	private function withDecision(FlowNodeCall $call, array $decision): array {
		$key   = $this->signalKey(config: $call->config);
		$items = $call->items;

		// Into every item's record (`json`), like the engine's own
		// await-signal node: the steps that follow route per item and read
		// `json.<key>`; an envelope-level key is invisible to a Switch.
		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$json = (array)($item[FlowItems::JSON] ?? []);
			$json[$key] = $decision;
			$item[FlowItems::JSON] = $json;
			$items[$index] = $item;
		}

		return $items;

	}//end withDecision()

	/**
	 * The item key the decision lands under.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return string The configured signal key, or the default.
	 */
	private function signalKey(array $config): string {
		$key = trim((string)($config['signalKey'] ?? ''));
		if ($key === '') {
			return self::DEFAULT_SIGNAL_KEY;
		}

		return $key;

	}//end signalKey()

	/**
	 * The decision this node is waiting for, if a signal delivered it.
	 *
	 * Null covers three cases that must all mean "keep waiting": no signal, a
	 * signal that is not a value bag, and a signal carrying no `decision` —
	 * the last so a stray empty resume cannot approve anything.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return array<string, mixed>|null The decision payload, or null while unanswered.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function decisionFrom(array $context): ?array {
		$signal = ($context[self::SIGNAL_CONTEXT_KEY] ?? null);
		if (is_array($signal) === false) {
			return null;
		}

		if (trim((string)($signal['decision'] ?? '')) === '') {
			return null;
		}

		return $signal;

	}//end decisionFrom()

	/**
	 * When the next heartbeat should wake the run.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return DateTime The wake-up time.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return new DateTime(sprintf('+%d minutes', $minutes));

	}//end heartbeatAt()
}//end class
