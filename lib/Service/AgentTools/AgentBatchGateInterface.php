<?php

/**
 * Contract of the AgentBatchGate.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\AgentTools
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

namespace OCA\Integriq\Service\AgentTools;

/**
 * Public operations of {@see AgentBatchGate} that other components call.
 */
interface AgentBatchGateInterface {
	/**
	 * Phase 1: record the batch and its binding. Nothing runs.
	 *
	 * @param array<string,mixed> $record The call's record, outcome staged.
	 *
	 * @return array{proposal:string,binding:string} The batch reference and binding.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function stage(array $record): array;

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
	public function admit(string $proposalId, string $approvalId, array $record): array;

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
	public function finish(string $proposalId, array $proposal, array $results): void;
}
