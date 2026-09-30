<?php

/**
 * Tells OpenRegister which integriq classes carry curated agent tools.
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

use OCA\OpenRegister\Mcp\IMcpScannableServices;

/**
 * Registered as `IMcpScannableServices::integriq`; no IMcpToolProvider alias
 * exists, so the read surface stays schema-declared (REQ-MCP-101).
 */
class IntegriqScannableServices implements IMcpScannableServices {

	/**
	 * The classes OpenRegister reflects for #[McpTool] methods.
	 *
	 * @return list<class-string> The one class.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-105--exactly-six-curated-tools-must-exist-each-an-action-over-existing-configuration-or-a-payload-free-read-with-honest-scope-and-reach
	 */
	public function getScannableServiceClasses(): array {
		return [IntegriqAgentTools::class];
	}//end getScannableServiceClasses()
}//end class
