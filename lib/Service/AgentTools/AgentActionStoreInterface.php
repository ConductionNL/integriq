<?php

/**
 * Contract of the AgentActionStore.
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
 * Public operations of {@see AgentActionStore} that other components call.
 */
interface AgentActionStoreInterface {
	/**
	 * Save a new record.
	 *
	 * @param array<string,mixed> $record The agent_action object.
	 *
	 * @return string The new record's uuid.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
	 */
	public function record(array $record): string;

	/**
	 * Read a record.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string,mixed>|null The object, or null when there is none.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function find(string $uuid): ?array;

	/**
	 * Overwrite a record.
	 *
	 * @param string              $uuid   The record uuid.
	 * @param array<string,mixed> $record The whole object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function update(string $uuid, array $record): void;
}
