<?php

/**
 * The Hermiq approval of a staged agent tool batch.
 *
 * Phase 2 of a run, replay or discard: the staged batch and the approval a person gave it. Both absent in phase 1.
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
 * Which staged batch to run, and the approval it runs on.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */
final class BatchApproval {

	/**
	 * Constructor.
	 *
	 * @param string|null $proposalId Phase 2: the staged batch.
	 * @param string|null $approvalId Phase 2: the Hermiq approval.
	 */
	public function __construct(
		public readonly ?string $proposalId,
		public readonly ?string $approvalId,
	) {

	}//end __construct()

	/**
	 * Whether this is phase 1: no staged batch is named yet.
	 *
	 * @return bool True when the batch is to be staged.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function isFirstPhase(): bool {
		return ($this->proposalId === null || $this->proposalId === '');
	}//end isFirstPhase()
}//end class
