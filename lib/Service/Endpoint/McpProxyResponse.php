<?php

/**
 * Integriq McpProxyResponse.
 *
 * The answer of an MCP endpoint: a callback response that makes the upstream
 * call while the caller waits and relays its status, headers and body as they
 * arrive.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Runs the relay when Nextcloud sends the answer.
 *
 * Its own status is 502 until the upstream's headers replace it, so a call
 * that never reaches the tool server does not answer 200.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
class McpProxyResponse extends Response implements ICallbackResponse {

	/**
	 * Constructor.
	 *
	 * @param callable $relay Called with the caller's output; makes the call and relays it.
	 */
	public function __construct(
		private $relay,
	) {
		parent::__construct(status: Http::STATUS_BAD_GATEWAY);
	}//end __construct()

	/**
	 * Make the upstream call and relay it into the caller's output.
	 *
	 * @param IOutput $output The caller's output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function callback(IOutput $output): void {
		($this->relay)($output);
	}//end callback()
}//end class
