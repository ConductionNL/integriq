<?php

/**
 * One two-phase agent tool batch.
 *
 * The batch a run, replay or discard tool stages or runs: the tool, the
 * store, the ids, the acting agent and, once checked, its record. Never
 * leaves IntegriqAgentTools.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */

declare(strict_types=1);

namespace OCA\Integriq\Mcp;

/**
 * A two-phase batch: what an agent asked to run, and the record it is staged and run under.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */
final class AgentToolBatch {

	/**
	 * The target ids.
	 *
	 * @var array<int,mixed>
	 */
	public array $ids = [];

	/**
	 * The acting agent, as Hermiq passes it.
	 *
	 * @var string|null
	 */
	public ?string $agentId = null;

	/**
	 * The batch's agent_action record, once checked.
	 *
	 * @var array<string,mixed>
	 */
	public array $record = [];

	/**
	 * Constructor.
	 *
	 * @param string $tool  The tool name.
	 * @param string $store The target store.
	 */
	public function __construct(
		public readonly string $tool,
		public readonly string $store,
	) {

	}//end __construct()
}//end class
