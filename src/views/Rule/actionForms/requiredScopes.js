// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The required scopes of an authentication rule, as the editor shows them
 * (access-oauth-and-token-validation, REQ-TOKV-005): one list for reading
 * (GET, HEAD) and one for writing (POST, PUT, PATCH, DELETE).
 *
 * EndpointService reads `requiredScopes` as either a list (every method) or a
 * map of HTTP method to list, with `*` for every method
 * (`ConsumerScopeCheck::requiredFor()`). The editor writes the map, one entry
 * per method, so both lists keep their meaning however the rule was stored.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */

/** The methods that read. */
export const READ_METHODS = ['GET', 'HEAD']

/** The methods that write. */
export const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE']

/**
 * Clean scope names: strings, trimmed, without empties or duplicates.
 *
 * @param {Array<unknown>|unknown} list The stored list.
 * @return {Array<string>} The scopes.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
function clean(list) {
	if (!Array.isArray(list)) return []
	return [...new Set(list.filter((s) => typeof s === 'string').map((s) => s.trim()).filter(Boolean))]
}

/**
 * The scopes a stored `requiredScopes` asks for reading or for writing.
 *
 * @param {Array<string>|object|undefined} required The stored value.
 * @param {'read'|'write'} kind Reading or writing.
 * @return {Array<string>} The scopes.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function scopesFor(required, kind) {
	if (Array.isArray(required)) return clean(required)
	if (!required || typeof required !== 'object') return []
	const methods = kind === 'read' ? READ_METHODS : WRITE_METHODS
	const upper = Object.fromEntries(Object.entries(required).map(([key, value]) => [key.toUpperCase(), value]))
	return clean([...(upper['*'] || []), ...methods.flatMap((method) => upper[method] || [])])
}

/**
 * The `requiredScopes` to store after one of the two lists changed.
 *
 * @param {Array<string>|object|undefined} required The stored value.
 * @param {'read'|'write'} kind The list that changed.
 * @param {Array<string>} scopes Its new scopes.
 * @return {object|Array} The method map, or [] when neither list asks for anything.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function withScopes(required, kind, scopes) {
	const read = kind === 'read' ? clean(scopes) : scopesFor(required, 'read')
	const write = kind === 'write' ? clean(scopes) : scopesFor(required, 'write')
	if (read.length === 0 && write.length === 0) return []
	const map = {}
	for (const method of READ_METHODS) if (read.length) map[method] = read
	for (const method of WRITE_METHODS) if (write.length) map[method] = write
	return map
}
