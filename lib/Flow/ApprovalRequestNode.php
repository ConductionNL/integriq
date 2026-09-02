<?php

/**
 * Integriq Approval Request flow node.
 *
 * `openconnector.approval-request` — the `approval` step of the retired
 * app-local flow runner, re-homed on OpenRegister's engine. The node persists
 * a `pending` `approval_request` (the HITL system of record, mirrored onto the
 * shared task service by `ApprovalService`) and then suspends the run exactly
 * the way `openregister.await-signal` does: a `FlowSuspension` with a
 * heartbeat, answered either by a delivered signal
 * (`FlowRunSignalService::signalAs()` from `ApprovalsController`) or — when
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
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

	/**
	 * The step type this node answers to.
	 *
	 * FROZEN on `openconnector.*` — the id is written into stored flow
	 * documents, so it survives the openconnector -> integriq app-id rename.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.approval-request';

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
	 * resolution later addresses through `FlowRunSignalService::signalAs()`,
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
	 * @param ApprovalService $approvalService The HITL state machine — persistence, mirror task, notifications.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly ApprovalService $approvalService,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

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
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/confirm.svg');
	}//end getIcon()

	/**
	 * Asking for an approval grants no privilege by itself.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

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
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (signal payload, resume slot, run uuid).
	 *
	 * @return array The items, each carrying the decision under the signal key.
	 *
	 * @throws FlowSuspension While the request is pending.
	 * @throws FlowStop When the request was rejected under `failOnReject`, or expired (fail closed).
	 * @throws FlowNodeException When the run cannot be addressed for a later answer.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$decision = $this->decisionFrom(context: $context);
		if ($decision !== null) {
			return $this->applyDecision(items: $items, config: $config, decision: $decision);
		}

		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			// Without a resume slot every heartbeat would open a fresh
			// request. That is a broken dispatch, not a pending approval.
			throw new FlowNodeException(
				message: $this->l10n->t('The approval step has no resume slot; the engine did not dispatch it as a resumable node.')
			);
		}

		if ($resume->has('approvalRequestId') === true) {
			return $this->answerFromRecord(items: $items, config: $config, resume: $resume);
		}

		$this->openRequest(config: $config, context: $context, resume: $resume);

		throw new FlowSuspension(
			resumeAt: $this->heartbeatAt(config: $config),
			reason: sprintf(
				'waiting for approval: %s',
				trim((string)$config['question'])
			)
		);

	}//end execute()

	/**
	 * Persist the pending approval_request and stamp the resume slot.
	 *
	 * The slot's `assignee` is the approver group, which is what
	 * OpenRegister's own signal guard (`FlowRunAssignee`) checks a signaller
	 * against — so the engine-side guard and Integriq's own approver-group
	 * authorization name the same audience.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the run has no uuid to answer at.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function openRequest(array $config, array $context, FlowNodeResumeState $resume): void {
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

		$record = $this->approvalService->suspendForEngineRun(
			engineRunUuid: $runUuid,
			signalNodeId: $resume->nodeId(),
			config: $config,
			requesterUid: trim((string)($context['triggeredBy'] ?? ''))
		);

		$data = $record->getObject();
		$resume->merge(
			values: [
				'approvalRequestId' => $record->getUuid(),
				'askedAt' => (new DateTime())->format('c'),
				'question' => trim((string)$config['question']),
				'assignee' => trim((string)$config['approverGroup']),
				'expiresAt' => (string)($data['expiresAt'] ?? ''),
			]
		);

	}//end openRequest()

	/**
	 * The heartbeat's answer when no signal made it: ask the record itself.
	 *
	 * The approval_request is the system of record and `ApprovalsController`
	 * resolves it whether or not the signal delivery succeeded, so a resolved
	 * record with no delivered signal means the answer exists and only the
	 * wake-up was lost.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param FlowNodeResumeState $resume This node's resume slot.
	 *
	 * @return array The items carrying the decision, when the record resolved.
	 *
	 * @throws FlowSuspension While the record is still pending and unexpired.
	 * @throws FlowStop When the record expired, was dead-lettered, or was rejected under `failOnReject`.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function answerFromRecord(array $items, array $config, FlowNodeResumeState $resume): array {
		$requestId = (string)$resume->get(key: 'approvalRequestId', default: '');

		try {
			$record = $this->approvalService->find(id: $requestId);
			$data = $record->getObject();
		} catch (Throwable $e) {
			// A vanished record can never resolve; waiting longer cannot fix it.
			throw new FlowStop(
				reason: sprintf('Approval request %s no longer exists; failing closed.', $requestId),
				isError: true
			);
		}

		$status = (string)($data['status'] ?? 'pending');

		if ($status === 'approved') {
			return $this->applyDecision(
				items: $items,
				config: $config,
				decision: [
					'decision' => 'approved',
					'decidedBy' => (string)($data['approverUserId'] ?? ''),
					'comment' => (string)($data['comment'] ?? ''),
					'approvalRequestId' => $requestId,
				]
			);
		}

		if ($status === 'rejected') {
			return $this->applyDecision(
				items: $items,
				config: $config,
				decision: [
					'decision' => 'rejected',
					'decidedBy' => (string)($data['approverUserId'] ?? ''),
					'comment' => (string)($data['comment'] ?? ''),
					'approvalRequestId' => $requestId,
				]
			);
		}

		if ($status === 'dead_letter') {
			throw new FlowStop(
				reason: sprintf('Approval request %s was dead-lettered.', $requestId),
				isError: true
			);
		}

		if ($status !== 'pending' || $this->hasExpired(data: $data, resume: $resume) === true) {
			// `expired` from the sweep, a past `expiresAt` the sweep has not
			// reached yet, or any state this node does not know: fail closed.
			throw new FlowStop(
				reason: sprintf(
					'Approval request %s was not answered in time (status: %s); failing closed.',
					$requestId,
					$status
				),
				isError: true
			);
		}

		throw new FlowSuspension(
			resumeAt: $this->heartbeatAt(config: $config),
			reason: sprintf(
				'still waiting for approval: %s',
				(string)$resume->get(key: 'question', default: 'approval')
			)
		);

	}//end answerFromRecord()

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
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $decision The decision payload.
	 *
	 * @return array The items, each carrying the decision under the signal key.
	 *
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function applyDecision(array $items, array $config, array $decision): array {
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

		$key = trim((string)($config['signalKey'] ?? ''));
		if ($key === '') {
			$key = self::DEFAULT_SIGNAL_KEY;
		}

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

	}//end applyDecision()

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
