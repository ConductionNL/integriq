// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rule editor's read and write scope lists map onto the `requiredScopes`
 * EndpointService reads (access-oauth-and-token-validation, REQ-TOKV-005).
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
import { describe, expect, it } from 'vitest'
import { scopesFor, withScopes } from '../../src/views/Rule/actionForms/requiredScopes.js'

describe('requiredScopes', () => {
	it('reads a stored list as required for reading and writing', () => {
		expect(scopesFor(['zaken:read'], 'read')).toEqual(['zaken:read'])
		expect(scopesFor(['zaken:read'], 'write')).toEqual(['zaken:read'])
	})

	it('reads a method map per kind, the * entry counting for both', () => {
		const stored = { get: ['zaken:read'], POST: ['zaken:write'], '*': ['zaken:any'] }
		expect(scopesFor(stored, 'read')).toEqual(['zaken:any', 'zaken:read'])
		expect(scopesFor(stored, 'write')).toEqual(['zaken:any', 'zaken:write'])
		expect(scopesFor(undefined, 'read')).toEqual([])
	})

	it('writes a method map that keeps the other list', () => {
		expect(withScopes(undefined, 'write', ['zaken:write', ' ', 'zaken:write'])).toEqual({
			POST: ['zaken:write'], PUT: ['zaken:write'], PATCH: ['zaken:write'], DELETE: ['zaken:write'],
		})
		expect(withScopes({ GET: ['zaken:read'] }, 'write', ['zaken:write'])).toEqual({
			GET: ['zaken:read'], HEAD: ['zaken:read'], POST: ['zaken:write'], PUT: ['zaken:write'], PATCH: ['zaken:write'], DELETE: ['zaken:write'],
		})
	})

	it('clears to [] when neither list asks for a scope', () => {
		expect(withScopes({ GET: ['zaken:read'] }, 'read', [])).toEqual([])
	})
})
