<?php

/**
 * The two phases of a gated agent tool: stage a batch, admit it on a verdict.
 *
 * @category Service
 * @package  OCA\Integriq\Service\AgentTools
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

use DateTimeImmutable;

/**
 * A staged batch is an `agent_action` record with outcome `staged` (design
 * D8). Admitting it checks that the call matches the batch, asks Hermiq for
 * its verdict (D7), and closes the batch BEFORE the caller runs it, so one
 * approval runs one batch once. A refusal is recorded and the batch stays
 * staged.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */
class AgentBatchGate {

	/**
	 * How long a staged batch waits for its approval, in seconds.
	 */
	public const PROPOSAL_TTL = 86400;

	/**
	 * The fields a phase 2 call must share with its staged batch.
	 */
	private const MATCHED = ['tool', 'store', 'agent', 'grantingUser'];

	/**
	 * Build the gate.
	 *
	 * @param AgentActionStore        $store    Staged batches and records.
	 * @param ApprovalVerdictVerifier $verifier Hermiq's verdict, checked.
	 */
	public function __construct(
		private readonly AgentActionStore $store,
		private readonly ApprovalVerdictVerifier $verifier,
	) {
	}//end __construct()

	/**
	 * Phase 1: record the batch and its binding. Nothing runs.
	 *
	 * @param array<string,mixed> $record The call's record, outcome staged.
	 *
	 * @return array{proposal:string,binding:string} The batch reference and binding.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function stage(array $record): array {
		$record['outcome'] = 'staged';
		$proposalId        = $this->store->record(record: $record);
		$record['binding'] = self::binding(proposalId: $proposalId, toolId: (string)$record['tool'], ids: (array)$record['targetIds']);
		$this->store->update(uuid: $proposalId, record: $record);

		return ['proposal' => $proposalId, 'binding' => $record['binding']];
	}//end stage()

	/**
	 * Phase 2: admit the batch on a verified approval and close it.
	 *
	 * @param string              $proposalId The staged batch.
	 * @param string              $approvalId The Hermiq approval.
	 * @param array<string,mixed> $record     This call's record.
	 *
	 * @return array{proposal:array<string,mixed>,approvedBy:string} The closed batch and its approver.
	 *
	 * @throws AgentActionRefusedException When it does not hold; recorded first.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function admit(string $proposalId, string $approvalId, array $record): array {
		$proposal = $this->store->find(uuid: $proposalId);
		try {
			$proposal   = $this->matching(proposal: $proposal, record: $record);
			$approvedBy = $this->verifier->verify(
				approvalId: $approvalId,
				toolId: (string)$record['tool'],
				binding: (string)($proposal['binding'] ?? ''),
				actingAgent: (string)$record['agent']
			);
		} catch (AgentActionRefusedException $e) {
			$record['outcome']  = 'refused';
			$record['reason']   = $e->reason;
			$record['proposal'] = $proposalId;
			$record['approval'] = $approvalId;
			$this->store->record(record: $record);
			throw $e;
		}

		$proposal['outcome']    = 'executed';
		$proposal['approval']   = $approvalId;
		$proposal['approvedBy'] = $approvedBy;
		$proposal['executedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
		$this->store->update(uuid: $proposalId, record: $proposal);

		return ['proposal' => $proposal, 'approvedBy' => $approvedBy];
	}//end admit()

	/**
	 * Keep the per-id outcomes on the closed batch.
	 *
	 * @param string                        $proposalId The batch.
	 * @param array<string,mixed>           $proposal   The closed batch.
	 * @param array<int,array<string,mixed>> $results    One outcome per id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function finish(string $proposalId, array $proposal, array $results): void {
		$proposal['results'] = $results;
		$this->store->update(uuid: $proposalId, record: $proposal);
	}//end finish()

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
	 * The staged batch, when this call is for exactly it and it is still open.
	 *
	 * @param array<string,mixed>|null $proposal The stored batch.
	 * @param array<string,mixed>      $record   This call's record.
	 *
	 * @return array<string,mixed> The batch.
	 *
	 * @throws AgentActionRefusedException When it is not.
	 */
	private function matching(?array $proposal, array $record): array {
		if ($proposal === null || ($proposal['outcome'] ?? '') !== 'staged') {
			throw new AgentActionRefusedException(reason: 'no-staged-batch');
		}

		foreach (self::MATCHED as $field) {
			if (($proposal[$field] ?? null) !== ($record[$field] ?? null)) {
				throw new AgentActionRefusedException(reason: 'batch-mismatch');
			}
		}

		$staged = (array)($proposal['targetIds'] ?? []);
		$asked  = (array)$record['targetIds'];
		sort($staged);
		sort($asked);
		if ($staged !== $asked) {
			throw new AgentActionRefusedException(reason: 'batch-mismatch');
		}

		$stagedAt = strtotime((string)($proposal['at'] ?? ''));
		if ($stagedAt === false || (time() - $stagedAt) > self::PROPOSAL_TTL) {
			throw new AgentActionRefusedException(reason: 'proposal-expired');
		}

		return $proposal;
	}//end matching()
}//end class
