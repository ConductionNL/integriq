/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * An mcp endpoint's `mcpToolLimits` (a map of tool name to `{ limit, window }`)
 * as the editable rows the endpoint editor shows, and back
 * (gateway-mcp-proxy, task 4).
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
 */

/**
 * The stored map as rows, in the map's order.
 *
 * @param {object|undefined} limits The endpoint's `mcpToolLimits`.
 * @return {Array<{tool: string, limit: number, window: number}>}
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/tasks.md#task-4
 */
export function limitRowsOf(limits) {
	if (!limits || typeof limits !== 'object' || Array.isArray(limits)) return []
	return Object.entries(limits).map(([tool, value]) => ({
		tool,
		limit: Number(value && value.limit) || 0,
		window: Number(value && value.window) || 0,
	}))
}

/**
 * The rows as the stored map: rows without a tool name or with a limit or
 * window that is not a positive whole number are left out; a later row of the
 * same tool wins.
 *
 * @param {Array<{tool: string, limit: number|string, window: number|string}>} rows The rows.
 * @return {object} The map.
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/tasks.md#task-4
 */
export function toolLimitsOf(rows) {
	const limits = {}
	for (const row of Array.isArray(rows) ? rows : []) {
		const tool = String(row && row.tool ? row.tool : '').trim()
		const limit = Math.floor(Number(row && row.limit))
		const window = Math.floor(Number(row && row.window))
		if (tool === '' || !(limit > 0) || !(window > 0)) continue
		limits[tool] = { limit, window }
	}
	return limits
}
