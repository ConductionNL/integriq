/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the shared synchronization draft helpers
 * (src/views/Synchronization/syncDraft.js): the empty draft, the conditions
 * round-trip to the schema's array shape, and the bridge status probe.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

const {
	CURSOR_COMPARATOR_OPTIONS,
	emptyDraft,
	fetchBridgeStatus,
	NEXTCLOUD_FORM_OPTION,
	NEXTCLOUD_TABLE_OPTION,
	serializeConditions,
	SYNC_MODE_OPTIONS,
	TYPE_OPTIONS,
} = await import('../../src/views/Synchronization/syncDraft.js')

describe('emptyDraft', () => {
	it('is a fully keyed api → register/schema draft in full mode', () => {
		expect(emptyDraft()).toEqual({
			name: '',
			description: '',
			sourceType: 'api',
			sourceId: '',
			sourceConfig: {},
			sourceTargetMapping: '',
			sourceHashMapping: '',
			syncMode: 'full',
			targetType: 'register/schema',
			targetId: '',
			targetConfig: {},
			targetSourceMapping: '',
			actions: [],
			followUps: [],
			conditions: { and: [] },
		})
	})

	it('hands out fresh objects, so editing one draft never leaks into the next', () => {
		const first = emptyDraft()
		first.conditions.and.push({ '==': [1, 1] })
		first.sourceConfig.url = 'x'
		first.actions.push('a')

		const second = emptyDraft()
		expect(second.conditions).toEqual({ and: [] })
		expect(second.sourceConfig).toEqual({})
		expect(second.actions).toEqual([])
	})
})

describe('serializeConditions', () => {
	it('sends an empty AND or OR group as an empty array', () => {
		expect(serializeConditions({ and: [] })).toEqual([])
		expect(serializeConditions({ or: [] })).toEqual([])
		expect(serializeConditions({ and: 'not a list' })).toEqual([])
	})

	it('wraps a group with children in an array', () => {
		const group = { or: [{ '==': [{ var: 'status' }, 'open'] }] }
		expect(serializeConditions(group)).toEqual([group])
	})

	it('wraps any other non-empty node as it is', () => {
		const node = { '==': [{ var: 'a' }, 1] }
		expect(serializeConditions(node)).toEqual([node])
		const twoKeys = { and: [], or: [] }
		expect(serializeConditions(twoKeys)).toEqual([twoKeys])
	})

	it('sends nothing for a missing or non-object value', () => {
		expect(serializeConditions(null)).toEqual([])
		expect(serializeConditions(undefined)).toEqual([])
		expect(serializeConditions('and')).toEqual([])
	})
})

describe('option vocabularies', () => {
	it('offers the three base kinds, and the bridge kinds as separate options', () => {
		expect(TYPE_OPTIONS.map((o) => o.id)).toEqual(['api', 'register/schema', 'file'])
		expect(NEXTCLOUD_TABLE_OPTION).toEqual({ id: 'nextcloud-table', label: 'Nextcloud Table' })
		expect(NEXTCLOUD_FORM_OPTION).toEqual({ id: 'nextcloud-form', label: 'Nextcloud Form' })
	})

	it('matches the schema enums for sync mode and cursor comparator', () => {
		expect(SYNC_MODE_OPTIONS.map((o) => o.id)).toEqual(['full', 'incremental'])
		expect(CURSOR_COMPARATOR_OPTIONS.map((o) => o.id)).toEqual(['gt', 'gte'])
	})
})

describe('fetchBridgeStatus', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('asks the bridge status endpoint and reads `enabled`', async () => {
		get.mockResolvedValueOnce({ data: { enabled: true } })

		await expect(fetchBridgeStatus('tables')).resolves.toBe(true)
		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/synchronizations/tables-bridge/status')
	})

	it('reads a missing or falsy flag as disabled', async () => {
		get.mockResolvedValueOnce({ data: {} })
		get.mockResolvedValueOnce({ data: { enabled: 0 } })
		get.mockResolvedValueOnce({})

		await expect(fetchBridgeStatus('forms')).resolves.toBe(false)
		await expect(fetchBridgeStatus('forms')).resolves.toBe(false)
		await expect(fetchBridgeStatus('forms')).resolves.toBe(false)
		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/synchronizations/forms-bridge/status')
	})

	it('soft-fails to disabled when the endpoint errors', async () => {
		get.mockRejectedValueOnce(new Error('404'))

		await expect(fetchBridgeStatus('tables')).resolves.toBe(false)
	})
})
