<?php

/**
 * How integriq asks Hermiq for a verdict on an approval.
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

/**
 * The transport half of the Hermiq verification contract (design D7). The
 * answer is not trusted for what it says: ApprovalVerdictVerifier checks its
 * signature and every echoed field, so a transport may be swapped freely.
 */
interface HermiqVerdictClient {

	/**
	 * Send one verify request and return Hermiq's decoded answer.
	 *
	 * @param array<string,string> $request approvalId, toolId, binding, actingAgent, nonce.
	 *
	 * @return array<string,mixed> The decoded JSON answer: verdict and signature.
	 *
	 * @throws AgentActionRefusedException When Hermiq cannot be reached.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function requestVerdict(array $request): array;
}//end interface
