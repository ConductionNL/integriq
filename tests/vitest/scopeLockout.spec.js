// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rule editor's save dialog names the consumers a new scope requirement
 * locks out (access-oauth-and-token-validation, REQ-TOKV-005).
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
import { describe, expect, it } from 'vitest'
import {
	addedScopes,
	addsScopes,
	callersOf,
	endpointsUsingRule,
	lockedOutConsumers,
	requiredScopesOf,
} from '../../src/views/Rule/scopeLockout.js'

/**
 * An authentication rule with these required scopes.
 *
 * @param {Array<string>|object} requiredScopes The stored value.
 * @return {object} The rule.
 */
function rule(requiredScopes) {
	return {
		id: 'r-1',
		uuid: 'r-1',
		slug: 'zaken-auth',
		type: 'authentication',
		configuration: { authentication: { type: 'jwt', requiredScopes } },
	}
}

describe('scopeLockout', () => {
	it('reads requiredScopes only from an authentication rule', () => {
		expect(requiredScopesOf(rule(['zaken:read']))).toEqual(['zaken:read'])
		expect(requiredScopesOf({ type: 'mapping', configuration: { authentication: { requiredScopes: ['x'] } } })).toBeUndefined()
		expect(requiredScopesOf(null)).toBeUndefined()
	})

	it('counts only the scopes the edit adds, per kind', () => {
		const added = addedScopes({ GET: ['zaken:read'] }, { GET: ['zaken:read'], POST: ['zaken:write'] })
		expect(added).toEqual({ read: [], write: ['zaken:write'] })
		expect(addsScopes(added)).toBe(true)
		expect(addsScopes(addedScopes({ POST: ['zaken:write'] }, []))).toBe(false)
		expect(addedScopes(undefined, ['a'])).toEqual({ read: ['a'], write: ['a'] })
	})

	it('finds the endpoints that name the rule by id, uuid or slug', () => {
		const endpoints = [
			{ uuid: 'e-1', rules: ['zaken-auth'] },
			{ uuid: 'e-2', rules: ['r-1', 'other'] },
			{ uuid: 'e-3', rules: ['other'] },
			{ uuid: 'e-4' },
		]
		expect(endpointsUsingRule(endpoints, rule([])).map((e) => e.uuid)).toEqual(['e-1', 'e-2'])
		expect(endpointsUsingRule(undefined, rule([]))).toEqual([])
	})

	it('reads the callers of those endpoints from the call log', () => {
		const rows = [
			{ endpoint: 'e-1', consumer: 'c-1' },
			{ endpoint: 'e-1' },
			{ endpoint: 'e-9', consumer: 'c-2' },
			{ endpoint: 'e-1', consumer: 'c-3' },
		]
		expect([...callersOf(rows, [{ uuid: 'e-1' }])]).toEqual(['c-1', 'c-3'])
	})

	it('lists the calling consumers that lack an added scope, with what they lack', () => {
		const consumers = [
			{ uuid: 'c-1', name: 'Zaaksysteem', scopes: ['zaken:read'] },
			{ uuid: 'c-2', name: 'Portaal', scopes: [] },
			{ uuid: 'c-3', name: 'Archief', scopes: ['zaken:read', 'zaken:write'] },
			{ uuid: 'c-4', name: 'Burgerzaken' },
		]
		const added = { read: [], write: ['zaken:write'] }
		expect(lockedOutConsumers({ added, consumers, callers: new Set(['c-1', 'c-3']) })).toEqual([
			{ id: 'c-1', name: 'Zaaksysteem', missing: ['zaken:write'] },
		])
	})

	it('lists every consumer that lacks the scope when no call is recorded', () => {
		const consumers = [
			{ uuid: 'c-2', name: 'Portaal', scopes: [] },
			{ uuid: 'c-1', name: 'Archief', scopes: ['zaken:write'] },
			{ uuid: 'c-4', name: 'Burgerzaken' },
		]
		const added = { read: ['zaken:read'], write: ['zaken:write'] }
		expect(lockedOutConsumers({ added, consumers, callers: new Set() })).toEqual([
			{ id: 'c-4', name: 'Burgerzaken', missing: ['zaken:read', 'zaken:write'] },
			{ id: 'c-1', name: 'Archief', missing: ['zaken:read'] },
			{ id: 'c-2', name: 'Portaal', missing: ['zaken:read', 'zaken:write'] },
		].sort((a, b) => a.name.localeCompare(b.name)))
	})

	it('locks nobody out when no scope is added', () => {
		expect(lockedOutConsumers({ added: { read: [], write: [] }, consumers: [{ uuid: 'c', scopes: [] }], callers: new Set() })).toEqual([])
	})
})
