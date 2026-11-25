<?php

/**
 * Integriq JsonRpcBody.
 *
 * Tells a JSON-RPC 2.0 message from other error bodies.
 *
 * @category Middleware
 * @package  OCA\Integriq\Middleware
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
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Middleware;

/**
 * A JSON-RPC 2.0 message, such as the per-tool limit refusal of an mcp gateway endpoint.
 * JSON-RPC fixes its own error format, so such a body is not rewritten into problem details.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
 */
final class JsonRpcBody {

	/**
	 * Whether an error body is a JSON-RPC 2.0 message.
	 *
	 * @param array<array-key,mixed> $data The error body.
	 *
	 * @return bool True for a JSON-RPC message.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
	 */
	public function is(array $data): bool {
		return ($data['jsonrpc'] ?? null) === '2.0';
	}//end is()
}//end class
