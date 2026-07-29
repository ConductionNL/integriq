/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for buildAuthenticationConfiguration (src/modals/Rule/buildAuthenticationConfiguration.js),
 * the load-bearing OMIT that stops an authentication-rule save from destroying stored API keys
 * (ocon#147 last residual / openregister#463).
 *
 * The destruction risk is REAL: the inbound apiKey => userId map is write-only, so the editor
 * seeds `apiKeys` empty on open. If the save payload sends `keys: []` OpenRegister's PUT-semantic
 * null-fill wipes every stored key; only OMITTING `keys` lets openregister#463 carry the stored
 * keys forward. These tests prove the omit at the payload-construction level (the repo's vitest
 * harness is node-env and mounts no .vue, so the logic lives in this pure helper by design).
 */

import { describe, it, expect } from 'vitest'
import { buildAuthenticationConfiguration } from '../../src/modals/Rule/buildAuthenticationConfiguration.js'

describe('buildAuthenticationConfiguration', () => {
	it('OMITS keys when the operator entered no new key (empty seed row) so #463 preserves stored keys', () => {
		// This is exactly the state after a stripped read: apiKeys seeded with one blank row.
		const auth = buildAuthenticationConfiguration({
			type: 'api-key',
			users: [],
			groups: [],
			apiKeys: [{ apiKey: '', user: [] }],
		})

		expect(Object.prototype.hasOwnProperty.call(auth, 'keys')).toBe(false)
		expect(auth).toEqual({ type: 'api-key', users: [], groups: [] })
	})

	it('OMITS keys when apiKeys is entirely absent/undefined', () => {
		const auth = buildAuthenticationConfiguration({ type: 'api-key', users: [], groups: [] })
		expect(Object.prototype.hasOwnProperty.call(auth, 'keys')).toBe(false)
	})

	it('OMITS keys when rows are incomplete (apiKey without a selected user, or user without a key)', () => {
		const auth = buildAuthenticationConfiguration({
			type: 'api-key',
			users: [],
			groups: [],
			apiKeys: [
				{ apiKey: 'has-key-no-user', user: null },
				{ apiKey: '', user: { id: 'alice' } },
			],
		})
		expect(Object.prototype.hasOwnProperty.call(auth, 'keys')).toBe(false)
	})

	it('EMITS keys (as apiKey => userId maps) only for complete new rows', () => {
		const auth = buildAuthenticationConfiguration({
			type: 'api-key',
			users: [],
			groups: [],
			apiKeys: [
				{ apiKey: 'sk_live_1', user: { id: 'alice' } },
				{ apiKey: '', user: [] }, // trailing empty seed row, ignored
				{ apiKey: 'sk_live_2', user: { id: 'bob' } },
			],
		})

		expect(auth.keys).toEqual([
			{ sk_live_1: 'alice' },
			{ sk_live_2: 'bob' },
		])
	})

	it('carries type/users/groups through unchanged (they are NOT write-only)', () => {
		const auth = buildAuthenticationConfiguration({
			type: 'basic',
			users: ['alice', 'bob'],
			groups: ['admin'],
			apiKeys: [],
		})
		expect(auth).toEqual({ type: 'basic', users: ['alice', 'bob'], groups: ['admin'] })
	})
})
