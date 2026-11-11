<?php

/**
 * Integriq AgentAdmission.
 *
 * One request to run a staged agent batch: the proposal, the approval that
 * would admit it and the batch the agent asks to run.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

/**
 * Value object for one batch admission request (ADR-011).
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */
final class AgentAdmission {

	/**
	 * Constructor.
	 *
	 * @param string               $proposalId The staged proposal.
	 * @param string               $approvalId The approval presented.
	 * @param array<string, mixed> $record     The batch the agent asks to run.
	 */
	public function __construct(
		public readonly string $proposalId,
		public readonly string $approvalId,
		public readonly array $record,
	) {
	}//end __construct()
}//end class
