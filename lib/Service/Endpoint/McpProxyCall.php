<?php

/**
 * Integriq McpProxyCall.
 *
 * One inbound call of an `mcp` endpoint, as McpProxyHandler needs it.
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
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;

/**
 * The endpoint, the request, the source and the rendered upstream path of one MCP call.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
final class McpProxyCall {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $endpoint     The `mcp` endpoint.
	 * @param IRequest     $request      The inbound request.
	 * @param ObjectEntity $source       The tool server's source.
	 * @param string       $upstreamPath The rendered path on the source.
	 * @param string       $rawBody      The request body, untouched.
	 * @param string       $consumerKey  The resolved consumer, else the caller's address.
	 */
	public function __construct(
		public readonly ObjectEntity $endpoint,
		public readonly IRequest $request,
		public readonly ObjectEntity $source,
		public readonly string $upstreamPath,
		public readonly string $rawBody,
		public readonly string $consumerKey,
	) {
	}//end __construct()
}//end class
