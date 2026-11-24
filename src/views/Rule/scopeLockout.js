// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who a new scope requirement locks out (access-oauth-and-token-validation,
 * REQ-TOKV-005, design risk "scopes added to an endpoint lock out consumers").
 *
 * When an administrator adds a scope to an authentication rule, the rule
 * editor's save dialog lists the consumers that call an endpoint using the
 * rule and do not hold the added scope. "Call" is read from the inbound call
 * log: its rows carry the endpoint's uuid and the consumer's uuid. An endpoint
 * without recorded calls gives no callers, and the dialog then lists every
 * consumer that lacks the scope, so a requirement never goes in unannounced.
 *
 * Only scopes the edit ADDS count: a requirement that was already there
 * locked its consumers out before this save, and a removed one frees them.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */

import { scopesFor } from './actionForms/requiredScopes.js'

/**
 * The `requiredScopes` an authentication rule stores, or undefined for any
 * other rule.
 *
 * @param {object|null|undefined} rule The rule object.
 * @return {Array<string>|object|undefined} The stored value.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function requiredScopesOf(rule) {
	if (!rule || rule.type !== 'authentication') return undefined
	return rule.configuration?.authentication?.requiredScopes
}

/**
 * The scopes the edit adds, per kind: in `after` and not in `before`.
 *
 * @param {Array<string>|object|undefined} before The stored `requiredScopes` before the edit.
 * @param {Array<string>|object|undefined} after The `requiredScopes` about to be saved.
 * @return {{read: Array<string>, write: Array<string>}} The added scopes.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function addedScopes(before, after) {
	const added = {}
	for (const kind of ['read', 'write']) {
		const had = new Set(scopesFor(before, kind))
		added[kind] = scopesFor(after, kind).filter((scope) => !had.has(scope))
	}
	return added
}

/**
 * Whether the edit adds any scope.
 *
 * @param {{read: Array<string>, write: Array<string>}} added The added scopes.
 * @return {boolean} True when at least one scope is added.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function addsScopes(added) {
	return added.read.length > 0 || added.write.length > 0
}

/**
 * The identifiers an object answers to: id, uuid and slug.
 *
 * @param {object} object An OpenRegister object.
 * @return {Array<string>} Its identifiers, as strings.
 */
function identifiersOf(object) {
	return [object?.id, object?.uuid, object?.slug, object?.['@self']?.id]
		.filter((value) => value !== undefined && value !== null && value !== '')
		.map(String)
}

/**
 * The endpoints whose `rules` list names this rule, by id, uuid or slug.
 *
 * @param {Array<object>} endpoints The endpoint objects.
 * @param {object} rule The rule object.
 * @return {Array<object>} The endpoints that use the rule.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function endpointsUsingRule(endpoints, rule) {
	const names = new Set(identifiersOf(rule))
	if (names.size === 0 || !Array.isArray(endpoints)) return []
	return endpoints.filter((endpoint) => Array.isArray(endpoint?.rules)
		&& endpoint.rules.some((entry) => names.has(String(entry))))
}

/**
 * The consumer uuids the call log records for these endpoints.
 *
 * @param {Array<object>} rows The inbound call_log rows.
 * @param {Array<object>} endpoints The endpoints that use the rule.
 * @return {Set<string>} The calling consumers' identifiers.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function callersOf(rows, endpoints) {
	const wanted = new Set(endpoints.flatMap(identifiersOf))
	const callers = new Set()
	for (const row of Array.isArray(rows) ? rows : []) {
		if (row?.consumer && wanted.has(String(row.endpoint))) callers.add(String(row.consumer))
	}
	return callers
}

/**
 * The consumers the added scopes lock out, each with the scopes it lacks.
 *
 * With callers known, only those consumers count; with none known (no call
 * recorded), every consumer does.
 *
 * @param {object} input The input.
 * @param {{read: Array<string>, write: Array<string>}} input.added The added scopes.
 * @param {Array<object>} input.consumers The consumer objects.
 * @param {Set<string>} input.callers The calling consumers' identifiers.
 * @return {Array<{id: string, name: string, missing: Array<string>}>} The consumers locked out, by name.
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export function lockedOutConsumers({ added, consumers, callers }) {
	const scopes = [...new Set([...added.read, ...added.write])]
	if (scopes.length === 0 || !Array.isArray(consumers)) return []
	const onlyCallers = callers instanceof Set && callers.size > 0
	return consumers
		.map((consumer) => lockoutOf(consumer, scopes, onlyCallers ? callers : null))
		.filter(Boolean)
		.sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * One consumer's lockout: the scopes it lacks, or null when it lacks none,
 * has no identifier, or is not among the callers.
 *
 * @param {object} consumer The consumer object.
 * @param {Array<string>} scopes The added scopes.
 * @param {Set<string>|null} callers The calling consumers' identifiers, or null for every consumer.
 * @return {{id: string, name: string, missing: Array<string>}|null} The lockout.
 */
function lockoutOf(consumer, scopes, callers) {
	const ids = identifiersOf(consumer)
	if (ids.length === 0) return null
	if (callers && !ids.some((id) => callers.has(id))) return null
	const held = new Set(Array.isArray(consumer.scopes) ? consumer.scopes : [])
	const missing = scopes.filter((scope) => !held.has(scope))
	if (missing.length === 0) return null
	return { id: ids[0], name: String(consumer.name || ids[0]), missing }
}
