<?php

/**
 * Integriq McpUpstreamPath.
 *
 * The path an `mcp` endpoint sends to its tool server, joined to the source location.
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

/**
 * Joins an upstream path to a source location with exactly one slash.
 *
 * The call is sent to the source location followed by this path. An endpoint path is stored
 * without a leading slash (`mcp/example`), so a location without a trailing slash
 * (`https://tools.example`) would otherwise run into it (`https://tools.examplemcp/example`).
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
final class McpUpstreamPath {

	/**
	 * The path to send after the location.
	 *
	 * @param string $location The source location.
	 * @param string $path     The rendered upstream path.
	 *
	 * @return string The path, with a leading slash unless the location ends in one or the path is empty or a query.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function under(string $location, string $path): string {
		if ($path === '' || str_starts_with($path, '?') === true) {
			return $path;
		}

		if (str_ends_with($location, '/') === true) {
			return ltrim($path, '/');
		}

		return '/' . ltrim($path, '/');
	}//end under()
}//end class
