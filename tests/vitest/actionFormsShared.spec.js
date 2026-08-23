/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the bespoke rule action-form helpers
 * (src/views/Rule/actionForms/shared.js):
 *   • patchMethod — returns a closure that emits an immutable copy of
 *     `value` with one key patched (the value ↔ update:value contract).
 *   • fetchOpenRegisterCollection — OR list-envelope unwrap (both `{results}`
 *     and bare-array shapes) + NcSelect option mapping, fail-soft to [].
 *
 * @nextcloud/axios is mocked; @nextcloud/router is the stub from the config.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => get(...a) } }))

import {
	patchMethod,
	fetchOpenRegisterCollection,
	valueProp,
} from '../../src/views/Rule/actionForms/shared.js'

describe('patchMethod', () => {
	it('emits an updated copy of value with the patched key (immutable)', () => {
		const emit = vi.fn()
		const ctx = { value: { a: 1, b: 2 }, $emit: emit }
		const patch = patchMethod()
		patch.call(ctx, 'b', 99)
		expect(emit).toHaveBeenCalledWith('update:value', { a: 1, b: 99 })
		// original object is not mutated
		expect(ctx.value).toEqual({ a: 1, b: 2 })
	})

	it('starts from {} when value is missing or not an object', () => {
		const emit = vi.fn()
		const patch = patchMethod()
		patch.call({ value: null, $emit: emit }, 'x', 1)
		expect(emit).toHaveBeenCalledWith('update:value', { x: 1 })
	})

	it('valueProp declares an object prop with an empty-object default', () => {
		expect(valueProp.value.type).toBe(Object)
		expect(valueProp.value.default()).toEqual({})
	})
})

describe('fetchOpenRegisterCollection', () => {
	beforeEach(() => get.mockReset())

	it('unwraps the {results:[]} envelope and maps to NcSelect options', async () => {
		get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 1, name: 'Sync A' },
					{ uuid: 'u2', title: 'Sync B' },
				],
			},
		})
		const opts = await fetchOpenRegisterCollection('synchronization')
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/openconnector/synchronization',
			// `_limit`, NOT `limit`. This assertion used to read `limit: 500`
			// and was GREEN — it pinned the defect in #1215 rather than the
			// requirement: OpenRegister treats an unprefixed parameter as a
			// PROPERTY FILTER, so the request this test was locking in
			// returned `total: 0` under HTTP 200 and every picker fed by this
			// helper was empty. A unit test that asserts the call the code
			// happens to make cannot tell you the call is wrong.
			{ params: { _limit: 500 } },
		)
		expect(opts).toEqual([
			{ id: '1', label: 'Sync A', raw: { id: 1, name: 'Sync A' } },
			{ id: 'u2', label: 'Sync B', raw: { uuid: 'u2', title: 'Sync B' } },
		])
	})

	it('tolerates a bare-array response shape', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 5, name: 'X' }] })
		const opts = await fetchOpenRegisterCollection(
			// The OpenRegister REGISTER SLUG, which does not move with the app
			// id — OR matches registers by slug, so `integriq` here would
			// address a fresh EMPTY register while every existing object stayed
			// behind, orphaned and silently invisible.
			'mapping',
			'openconnector',
			10,
		)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/openconnector/mapping',
			// Same as above — the caller-supplied limit must reach the wire as
			// `_limit` or it is silently reinterpreted as a property filter.
			{ params: { _limit: 10 } },
		)
		expect(opts).toHaveLength(1)
		expect(opts[0]).toMatchObject({ id: '5', label: 'X' })
	})

	it('labels with name||title||id, and "(unnamed)" when only a uuid is present', async () => {
		get.mockResolvedValueOnce({
			data: { results: [{ uuid: 'only-uuid' }, { id: 7 }] },
		})
		const opts = await fetchOpenRegisterCollection('rule')
		// label sources name/title/id (NOT uuid) → uuid-only row is "(unnamed)";
		// but the option id still derives from uuid.
		expect(opts[0]).toEqual({
			id: 'only-uuid',
			label: '(unnamed)',
			raw: { uuid: 'only-uuid' },
		})
		// label is `name||title||id` un-stringified; only the option `id` is String()'d.
		expect(opts[1].label).toBe(7)
		expect(opts[1].id).toBe('7')
	})

	it('returns [] (fail-soft) when the request rejects', async () => {
		get.mockRejectedValueOnce(new Error('500'))
		const opts = await fetchOpenRegisterCollection('rule')
		expect(opts).toEqual([])
	})
})
