<?php

/**
 * The six curated agent tools: run, test and triage, never configure.
 *
 * Every tool executes configuration an administrator made, once, and leaves
 * the control plane as it was (design Decision 1 and 2). Each one runs the
 * ADR-023 action check as the user the agent acts for before anything else
 * (Decision 5). Run, replay and discard are two-phase: the first call stages
 * the batch and runs nothing; the second runs it only on a verdict from Hermiq
 * that a person other than the agent approved exactly that batch (Decision 4,
 * DECISIONS row 31). Every call, including a refused one, leaves one
 * `agent_action` record naming the agent and the user (Decision 8).
 *
 * @category Mcp
 * @package  OCA\Integriq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-105--exactly-six-curated-tools-must-exist-each-an-action-over-existing-configuration-or-a-payload-free-read-with-honest-scope-and-reach
 */

declare(strict_types=1);

namespace OCA\Integriq\Mcp;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\AgentActionStore;
use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use OCA\Integriq\Service\AgentTools\DeadLetterProjection;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\SourceTestService;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Scanned by OpenRegister through IntegriqScannableServices.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One delegate per existing service path is the point (REQ-MCP-106).
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Same reason: the constructor lists those delegates.
 */
class IntegriqAgentTools {

	/**
	 * Reach per tool, in Hermiq's ToolReachResolver vocabulary. OpenRegister's
	 * McpTool attribute has no reach field yet, so it is declared here and in
	 * each description.
	 */
	public const REACH = [
		'runSynchronization'  => 'external',
		'testSynchronization' => 'external',
		'testSource'          => 'external',
		'replayDeadLetters'   => 'external',
		'discardDeadLetters'  => 'instance',
		'listDeadLetters'     => 'instance',
	];

	/**
	 * The ADR-023 action each tool checks. listDeadLetters has none: it is a
	 * read under the user's own register rights, like the derived reads.
	 */
	public const ACTIONS = [
		'runSynchronization'  => 'synchronization.run',
		'testSynchronization' => 'synchronization.test',
		'testSource'          => 'source.test',
		'replayDeadLetters'   => 'sync-dead-letter.replay',
		'discardDeadLetters'  => 'sync-dead-letter.discard',
	];

	/**
	 * The most ids one batch may carry.
	 */
	public const BATCH_CAP = 100;

	/**
	 * How long a staged batch waits for its approval, in seconds.
	 */
	public const PROPOSAL_TTL = 86400;

	/**
	 * The agent name recorded when the caller names none.
	 */
	public const UNIDENTIFIED = 'unidentified';

	/**
	 * Build the tools.
	 *
	 * @param IUserSession              $userSession     The user the agent acts for.
	 * @param ActionAuthService         $actionAuth      The ADR-023 matrix.
	 * @param OrObjectService           $objectService   Reads synchronizations, sources, dead letters.
	 * @param SynchronizationService    $synchronization The run and test path.
	 * @param SourceTestService         $sourceTest      The source test path.
	 * @param SyncItemDeadLetterService $syncDeadLetters The audited sync replay and discard.
	 * @param EventService              $events          The audited event replay and discard.
	 * @param AgentActionStore          $store           Staged batches and invocation records.
	 * @param ApprovalVerdictVerifier   $verifier        Hermiq's verdict, checked.
	 * @param DeadLetterProjection      $projection      The payload-free dead-letter row.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly OrObjectService $objectService,
		private readonly SynchronizationService $synchronization,
		private readonly SourceTestService $sourceTest,
		private readonly SyncItemDeadLetterService $syncDeadLetters,
		private readonly EventService $events,
		private readonly AgentActionStore $store,
		private readonly ApprovalVerdictVerifier $verifier,
		private readonly DeadLetterProjection $projection,
	) {
	}//end __construct()

	/**
	 * Run a synchronization once, after a person approved it in Hermiq.
	 *
	 * @param string      $synchronizationId The synchronization to run.
	 * @param string|null $agentId           The acting agent, as Hermiq passes it.
	 * @param string|null $proposalId        Phase 2: the staged batch.
	 * @param string|null $approvalId        Phase 2: the Hermiq approval.
	 * @param bool|null   $forceDeletion     Never accepted; named only to refuse it.
	 *
	 * @return array<string,mixed> The staged batch, or the run's counts.
	 *
	 * @throws InvalidArgumentException When forceDeletion is passed or the id is bad.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	#[McpTool(
		name: 'runSynchronization',
		description: 'Run one synchronization once, with its safety guards. Needs approval: the first call stages the run and nothing runs; a person approves it in Hermiq; the second call with proposalId and approvalId runs it. Reach: external. forceDeletion is not available.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'update'
	)]
	public function runSynchronization(
		string $synchronizationId,
		?string $agentId=null,
		?string $proposalId=null,
		?string $approvalId=null,
		?bool $forceDeletion=null
	): array {
		if ($forceDeletion !== null) {
			throw new InvalidArgumentException('forceDeletion is not available to agents; the deletion guard override stays a human act in the app.');
		}

		return $this->twoPhase(
			tool: 'runSynchronization',
			store: 'synchronization',
			ids: [$synchronizationId],
			agentId: $agentId,
			proposalId: $proposalId,
			approvalId: $approvalId
		);
	}//end runSynchronization()

	/**
	 * Replay a batch of dead letters, after a person approved the batch in Hermiq.
	 *
	 * @param array<int,string> $ids        The dead letter ids, never their content.
	 * @param string            $store      `sync` or `event`.
	 * @param string|null       $agentId    The acting agent, as Hermiq passes it.
	 * @param string|null       $proposalId Phase 2: the staged batch.
	 * @param string|null       $approvalId Phase 2: the Hermiq approval.
	 *
	 * @return array<string,mixed> The staged batch, or one outcome per id.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	#[McpTool(
		name: 'replayDeadLetters',
		description: 'Replay a batch of dead letters by id through the audited replay path. Needs approval: the first call stages the batch and nothing runs; a person reviews the payloads in the Dead letters page and approves the batch in Hermiq; the second call with proposalId and approvalId replays it. Reach: external.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'update'
	)]
	public function replayDeadLetters(
		array $ids,
		string $store='sync',
		?string $agentId=null,
		?string $proposalId=null,
		?string $approvalId=null
	): array {
		return $this->twoPhase(
			tool: 'replayDeadLetters',
			store: $this->deadLetterStore(store: $store),
			ids: $ids,
			agentId: $agentId,
			proposalId: $proposalId,
			approvalId: $approvalId
		);
	}//end replayDeadLetters()

	/**
	 * Discard a batch of dead letters for good, after a person approved the batch in Hermiq.
	 *
	 * @param array<int,string> $ids        The dead letter ids.
	 * @param string            $store      `sync` or `event`.
	 * @param string|null       $agentId    The acting agent, as Hermiq passes it.
	 * @param string|null       $proposalId Phase 2: the staged batch.
	 * @param string|null       $approvalId Phase 2: the Hermiq approval.
	 *
	 * @return array<string,mixed> The staged batch, or one outcome per id.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	#[McpTool(
		name: 'discardDeadLetters',
		description: 'Discard a batch of dead letters by id; a discarded dead letter cannot be replayed. Needs approval: the first call stages the batch and nothing is discarded; a person approves it in Hermiq; the second call with proposalId and approvalId discards it. Reach: instance.',
		readOnlyHint: false,
		destructiveHint: true,
		idempotentHint: false,
		scope: 'delete'
	)]
	public function discardDeadLetters(
		array $ids,
		string $store='sync',
		?string $agentId=null,
		?string $proposalId=null,
		?string $approvalId=null
	): array {
		return $this->twoPhase(
			tool: 'discardDeadLetters',
			store: $this->deadLetterStore(store: $store),
			ids: $ids,
			agentId: $agentId,
			proposalId: $proposalId,
			approvalId: $approvalId
		);
	}//end discardDeadLetters()

	/**
	 * Test a synchronization without writing anything, and answer its counts.
	 *
	 * @param string      $synchronizationId The synchronization to test.
	 * @param string|null $agentId           The acting agent, as Hermiq passes it.
	 *
	 * @return array<string,mixed> The counts the test run found.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-106--every-curated-tool-must-run-the-existing-adr-023-action-check-and-delegate-to-the-existing-controller-backed-service-path
	 */
	#[McpTool(
		name: 'testSynchronization',
		description: 'Test one synchronization: it fetches from the source and reports what it would create, update or skip, and writes nothing. Reach: external.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read'
	)]
	public function testSynchronization(string $synchronizationId, ?string $agentId=null): array {
		$tool  = 'testSynchronization';
		$user  = $this->authorize(tool: $tool, agentId: $agentId, ids: [$synchronizationId]);
		$found = $this->findObject(schema: 'synchronization', id: $synchronizationId);
		try {
			$result = $this->synchronization->synchronize(synchronization: $found, isTest: true, force: false);
		} catch (Throwable $e) {
			$this->recordSingle(tool: $tool, user: $user, agentId: $agentId, ids: [$synchronizationId], outcome: 'failed', reason: DeadLetterProjection::truncate(value: $e->getMessage()));
			throw $e;
		}

		$this->recordSingle(tool: $tool, user: $user, agentId: $agentId, ids: [$synchronizationId], outcome: 'executed', reason: '');
		return ['synchronization' => $synchronizationId, 'objects' => ($result['result']['objects'] ?? [])];
	}//end testSynchronization()

	/**
	 * Call a source once and answer whether it responded, and how.
	 *
	 * @param string      $sourceId The source to test.
	 * @param string|null $agentId  The acting agent, as Hermiq passes it.
	 *
	 * @return array<string,mixed> The outcome and status, never the response body.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-106--every-curated-tool-must-run-the-existing-adr-023-action-check-and-delegate-to-the-existing-controller-backed-service-path
	 */
	#[McpTool(
		name: 'testSource',
		description: 'Call one source once and report whether it answered, with its status code. The response body is not returned. Reach: external.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read'
	)]
	public function testSource(string $sourceId, ?string $agentId=null): array {
		$tool   = 'testSource';
		$user   = $this->authorize(tool: $tool, agentId: $agentId, ids: [$sourceId]);
		$source = $this->objectService->find(id: $sourceId, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		if ($source === null) {
			throw new InvalidArgumentException('Not found: ' . $sourceId);
		}

		$outcome = $this->sourceTest->run(source: $source);
		$this->recordSingle(tool: $tool, user: $user, agentId: $agentId, ids: [$sourceId], outcome: 'executed', reason: (string)$outcome['outcome']);
		return [
			'source'        => $sourceId,
			'outcome'       => $outcome['outcome'],
			'statusCode'    => $outcome['statusCode'],
			'statusMessage' => $outcome['statusMessage'],
			'error'         => DeadLetterProjection::truncate(value: (string)$outcome['error']),
		];
	}//end testSource()

	/**
	 * List dead letters by their metadata only, never their payload.
	 *
	 * @param string|null $synchronization Only this synchronization's (sync store).
	 * @param string|null $status          Only this status; default the failed ones.
	 * @param string      $store           `sync` or `event`.
	 * @param int         $limit           At most this many rows, up to BATCH_CAP.
	 * @param string|null $agentId         The acting agent, as Hermiq passes it.
	 *
	 * @return array<string,mixed> The rows and their count.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
	 */
	#[McpTool(
		name: 'listDeadLetters',
		description: 'List dead letters by id, synchronization or subscription, phase, a shortened error, attempts, status and dates. Payloads are never shown; a person reviews them in the Dead letters page. Reach: instance.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read'
	)]
	public function listDeadLetters(
		?string $synchronization=null,
		?string $status=null,
		string $store='sync',
		int $limit=50,
		?string $agentId=null
	): array {
		$tool    = 'listDeadLetters';
		$store   = $this->deadLetterStore(store: $store);
		$user    = $this->authorize(tool: $tool, agentId: $agentId, ids: []);
		$schema  = 'sync_item_dead_letter';
		$filters = ['register' => 'integriq', 'schema' => $schema];
		if ($store === 'event') {
			$schema = 'event_message';
			$filters['schema'] = $schema;
		}

		$filters['status'] = ($status ?? 'failed');
		if ($synchronization !== null && $synchronization !== '' && $store === 'sync') {
			$filters['synchronization'] = $synchronization;
		}

		$found   = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => max(1, min($limit, self::BATCH_CAP))]);
		$entries = ($found['results'] ?? $found);
		$rows    = [];
		foreach ($entries as $entry) {
			$rows[] = $this->projection->project(store: $store, id: $entry->getUuid(), data: $entry->getObject());
		}

		$this->recordSingle(tool: $tool, user: $user, agentId: $agentId, ids: [], outcome: 'executed', reason: '');
		return ['results' => $rows, 'total' => count($rows)];
	}//end listDeadLetters()

	/**
	 * Stage a batch (phase 1) or run it on a verified approval (phase 2).
	 *
	 * @param string            $tool       The tool name.
	 * @param string            $store      `synchronization`, `sync` or `event`.
	 * @param array<int,mixed>  $ids        The target ids.
	 * @param string|null       $agentId    The acting agent.
	 * @param string|null       $proposalId The staged batch, in phase 2.
	 * @param string|null       $approvalId The Hermiq approval, in phase 2.
	 *
	 * @return array<string,mixed> The staged batch, or the outcomes.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	private function twoPhase(string $tool, string $store, array $ids, ?string $agentId, ?string $proposalId, ?string $approvalId): array {
		$ids  = $this->validIds(ids: $ids);
		$user = $this->authorize(tool: $tool, agentId: $agentId, ids: $ids);
		if ($proposalId === null || $proposalId === '') {
			return $this->stage(tool: $tool, store: $store, ids: $ids, user: $user, agentId: $agentId);
		}

		$proposal = $this->store->find(uuid: $proposalId);
		$base     = $this->record(tool: $tool, user: $user, agentId: $agentId, ids: $ids, outcome: 'refused');
		$base['proposal'] = $proposalId;
		$base['approval'] = (string)$approvalId;
		try {
			$this->checkProposal(proposal: $proposal, tool: $tool, store: $store, ids: $ids, user: $user, agentId: $agentId);
			$approvedBy = $this->verifier->verify(
				approvalId: (string)$approvalId,
				toolId: 'integriq.' . $tool,
				binding: (string)($proposal['binding'] ?? ''),
				actingAgent: $this->agent(agentId: $agentId)
			);
		} catch (AgentActionRefusedException $e) {
			$base['reason'] = $e->reason;
			$this->store->record(record: $base);
			throw $e;
		}

		// Close the batch before running it, so the same approval can never run it twice.
		$proposal['outcome']    = 'executed';
		$proposal['approval']   = (string)$approvalId;
		$proposal['approvedBy'] = $approvedBy;
		$proposal['executedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
		$this->store->update(uuid: $proposalId, record: $proposal);

		$results = [];
		foreach ($ids as $id) {
			$results[] = $this->execute(tool: $tool, store: $store, id: $id, actorUid: $user->getUID());
		}

		$proposal['results'] = $results;
		$this->store->update(uuid: $proposalId, record: $proposal);

		return ['status' => 'executed', 'proposal' => $proposalId, 'approvedBy' => $approvedBy, 'results' => $results];
	}//end twoPhase()

	/**
	 * Phase 1: record the batch and its binding; run nothing.
	 *
	 * @param string            $tool    The tool name.
	 * @param string            $store   The target store.
	 * @param array<int,string> $ids     The validated ids.
	 * @param IUser             $user    The granting user.
	 * @param string|null       $agentId The acting agent.
	 *
	 * @return array<string,mixed> The staged batch.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	private function stage(string $tool, string $store, array $ids, IUser $user, ?string $agentId): array {
		$schemas = ['synchronization' => 'synchronization', 'sync' => 'sync_item_dead_letter', 'event' => 'event_message'];
		foreach ($ids as $id) {
			$this->findObject(schema: $schemas[$store], id: $id);
		}

		$record          = $this->record(tool: $tool, user: $user, agentId: $agentId, ids: $ids, outcome: 'staged');
		$record['store'] = $store;
		$proposalId      = $this->store->record(record: $record);

		$record['binding'] = self::binding(proposalId: $proposalId, toolId: 'integriq.' . $tool, ids: $ids);
		$this->store->update(uuid: $proposalId, record: $record);

		return [
			'status'    => 'staged',
			'proposal'  => $proposalId,
			'tool'      => 'integriq.' . $tool,
			'targetIds' => $ids,
			'binding'   => $record['binding'],
			'message'   => 'Nothing ran. A person must approve this batch in Hermiq; then call again with proposalId and approvalId.',
		];
	}//end stage()

	/**
	 * The hash that ties an approval to one staged batch.
	 *
	 * @param string            $proposalId The staged batch.
	 * @param string            $toolId     The full tool id.
	 * @param array<int,string> $ids        The target ids.
	 *
	 * @return string The sha256 hex binding.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public static function binding(string $proposalId, string $toolId, array $ids): string {
		sort($ids);
		return hash('sha256', 'integriq:' . $proposalId . ':' . $toolId . ':' . implode(',', $ids));
	}//end binding()

	/**
	 * Refuse a phase 2 call that does not match its staged batch.
	 *
	 * @param array<string,mixed>|null $proposal The staged record.
	 * @param string                   $tool     The tool name.
	 * @param string                   $store    The target store.
	 * @param array<int,string>        $ids      The ids of this call.
	 * @param IUser                    $user     The granting user.
	 * @param string|null              $agentId  The acting agent.
	 *
	 * @return void
	 *
	 * @throws AgentActionRefusedException When it does not match.
	 */
	private function checkProposal(?array $proposal, string $tool, string $store, array $ids, IUser $user, ?string $agentId): void {
		if ($proposal === null) {
			throw new AgentActionRefusedException(reason: 'unknown-proposal');
		}

		if (($proposal['outcome'] ?? '') !== 'staged') {
			throw new AgentActionRefusedException(reason: 'proposal-not-staged');
		}

		$staged = (array)($proposal['targetIds'] ?? []);
		sort($staged);
		$asked = $ids;
		sort($asked);
		$same = (($proposal['tool'] ?? '') === 'integriq.' . $tool)
			&& (($proposal['store'] ?? '') === $store)
			&& ($staged === $asked)
			&& (($proposal['agent'] ?? '') === $this->agent(agentId: $agentId))
			&& (($proposal['grantingUser'] ?? '') === $user->getUID());
		if ($same === false) {
			throw new AgentActionRefusedException(reason: 'batch-mismatch');
		}

		$stagedAt = strtotime((string)($proposal['at'] ?? ''));
		if ($stagedAt === false || (time() - $stagedAt) > self::PROPOSAL_TTL) {
			throw new AgentActionRefusedException(reason: 'proposal-expired');
		}
	}//end checkProposal()

	/**
	 * Run one target through its existing service path.
	 *
	 * @param string $tool     The tool name.
	 * @param string $store    The target store.
	 * @param string $id       The target id.
	 * @param string $actorUid The granting user, recorded by the audited paths.
	 *
	 * @return array<string,mixed> The id and its outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-106--every-curated-tool-must-run-the-existing-adr-023-action-check-and-delegate-to-the-existing-controller-backed-service-path
	 */
	private function execute(string $tool, string $store, string $id, string $actorUid): array {
		try {
			if ($tool === 'runSynchronization') {
				$found  = $this->findObject(schema: 'synchronization', id: $id);
				$result = $this->synchronization->synchronize(synchronization: $found, isTest: false, force: false, forceDeletion: false);
				return ['id' => $id, 'outcome' => 'done', 'objects' => ($result['result']['objects'] ?? [])];
			}

			if ($tool === 'replayDeadLetters' && $store === 'event') {
				$this->events->replayMessage(id: $id, actorUid: $actorUid);
			} else if ($tool === 'replayDeadLetters') {
				$this->syncDeadLetters->replayMessage(id: $id, actorUid: $actorUid);
			} else if ($store === 'event') {
				$this->events->discardMessage(id: $id, actorUid: $actorUid);
			} else {
				$this->syncDeadLetters->discardMessage(id: $id, actorUid: $actorUid);
			}
		} catch (Throwable $e) {
			return ['id' => $id, 'outcome' => 'failed', 'message' => DeadLetterProjection::truncate(value: $e->getMessage())];
		}//end try

		return ['id' => $id, 'outcome' => 'done'];
	}//end execute()

	/**
	 * The ADR-023 check, recorded when it denies.
	 *
	 * @param string            $tool    The tool name.
	 * @param string|null       $agentId The acting agent.
	 * @param array<int,string> $ids     The target ids.
	 *
	 * @return IUser The granting user.
	 *
	 * @throws OCSForbiddenException When the matrix denies; unchanged.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-106--every-curated-tool-must-run-the-existing-adr-023-action-check-and-delegate-to-the-existing-controller-backed-service-path
	 */
	private function authorize(string $tool, ?string $agentId, array $ids): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not signed in');
		}

		if (isset(self::ACTIONS[$tool]) === false) {
			return $user;
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTIONS[$tool]);
		} catch (OCSForbiddenException $e) {
			$this->recordSingle(tool: $tool, user: $user, agentId: $agentId, ids: $ids, outcome: 'denied', reason: self::ACTIONS[$tool]);
			throw $e;
		}

		return $user;
	}//end authorize()

	/**
	 * Write one record for a single-phase call or a refusal.
	 *
	 * @param string            $tool    The tool name.
	 * @param IUser             $user    The granting user.
	 * @param string|null       $agentId The acting agent.
	 * @param array<int,string> $ids     The target ids.
	 * @param string            $outcome The outcome.
	 * @param string            $reason  Why, when there is a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
	 */
	private function recordSingle(string $tool, IUser $user, ?string $agentId, array $ids, string $outcome, string $reason): void {
		$record = $this->record(tool: $tool, user: $user, agentId: $agentId, ids: $ids, outcome: $outcome);
		if ($reason !== '') {
			$record['reason'] = $reason;
		}

		$this->store->record(record: $record);
	}//end recordSingle()

	/**
	 * The fields every record carries.
	 *
	 * @param string            $tool    The tool name.
	 * @param IUser             $user    The granting user.
	 * @param string|null       $agentId The acting agent.
	 * @param array<int,string> $ids     The target ids.
	 * @param string            $outcome The outcome.
	 *
	 * @return array<string,mixed> The agent_action object.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
	 */
	private function record(string $tool, IUser $user, ?string $agentId, array $ids, string $outcome): array {
		return [
			'tool'         => 'integriq.' . $tool,
			'agent'        => $this->agent(agentId: $agentId),
			'grantingUser' => $user->getUID(),
			'outcome'      => $outcome,
			'targetIds'    => array_values($ids),
			'at'           => (new DateTimeImmutable())->format(DATE_ATOM),
		];
	}//end record()

	/**
	 * The acting agent, or the marker for a caller that named none.
	 *
	 * @param string|null $agentId The argument.
	 *
	 * @return string The agent.
	 */
	private function agent(?string $agentId): string {
		if ($agentId === null || trim($agentId) === '') {
			return self::UNIDENTIFIED;
		}

		return trim($agentId);
	}//end agent()

	/**
	 * Ids only: short, plain, unique, and at most BATCH_CAP of them.
	 *
	 * @param array<int,mixed> $ids The ids as passed.
	 *
	 * @return array<int,string> The ids.
	 *
	 * @throws InvalidArgumentException When a value is not an id.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content
	 */
	private function validIds(array $ids): array {
		if (count($ids) === 0 || count($ids) > self::BATCH_CAP) {
			throw new InvalidArgumentException('Pass between 1 and ' . self::BATCH_CAP . ' ids.');
		}

		$valid = [];
		foreach ($ids as $id) {
			if (is_string($id) === false || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1) {
				throw new InvalidArgumentException('Only ids are accepted, never content.');
			}

			$valid[$id] = $id;
		}

		return array_values($valid);
	}//end validIds()

	/**
	 * The dead-letter store an argument names.
	 *
	 * @param string $store The argument.
	 *
	 * @return string `sync` or `event`.
	 *
	 * @throws InvalidArgumentException For anything else.
	 */
	private function deadLetterStore(string $store): string {
		if (in_array($store, ['sync', 'event'], true) === false) {
			throw new InvalidArgumentException('store is sync or event.');
		}

		return $store;
	}//end deadLetterStore()

	/**
	 * Read one integriq object, or refuse the id.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The object.
	 *
	 * @throws InvalidArgumentException When it does not exist.
	 */
	private function findObject(string $schema, string $id): \OCA\OpenRegister\Db\ObjectEntity {
		try {
			$found = $this->objectService->find(id: $id, register: 'integriq', schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$found = null;
		}

		if ($found === null) {
			throw new InvalidArgumentException('Not found: ' . $id);
		}

		return $found;
	}//end findObject()
}//end class
