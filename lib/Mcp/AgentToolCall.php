<?php

/**
 * One agent tool call as the audit trail records it.
 *
 * The tool, the granting user, the acting agent and the target ids every agent_action record carries.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\Integriq\Mcp;

use OCP\IUser;

/**
 * Who called which tool on which ids.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail
 */
final class AgentToolCall {

	/**
	 * The acting agent.
	 *
	 * @var string|null
	 */
	public ?string $agentId = null;

	/**
	 * The target ids.
	 *
	 * @var array<int,string>
	 */
	public array $ids = [];

	/**
	 * Constructor.
	 *
	 * @param string $tool The tool name.
	 * @param IUser  $user The granting user.
	 */
	public function __construct(
		public readonly string $tool,
		public readonly IUser $user,
	) {

	}//end __construct()
}//end class
