// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { describe, expect, it, vi } from 'vitest'
import {
	leafHeader,
	leafReportMixin,
	leafSummary,
} from '../../src/integration/leafReport.js'

/**
 * What the leaves report to a carding host (`onHeader`) and to a "linked"
 * card (`onSummary`).
 *
 * @spec openspec/changes/leaves-library-card/specs/leaves-library-card/spec.md#requirement-the-sync-leaf-reports-to-the-library-card-and-the-linked-card
 */
describe('leafHeader', () => {
	const actions = [{ label: 'Open', run: () => {} }]
	const base = {
		loading: false,
		error: '',
		count: 0,
		emptyLabel: 'Nothing yet',
		actions,
	}

	it('reports a zero count, the actions and the empty state', () => {
		expect(leafHeader(base)).toEqual({
			actions,
			note: '',
			count: 0,
			empty: { name: 'Nothing yet' },
		})
	})

	it('reports the count without an empty state when there are items', () => {
		expect(leafHeader({ ...base, count: 2, note: 'x' })).toEqual({
			actions,
			note: 'x',
			count: 2,
		})
	})

	it('reports no count and no empty state while loading or after a failed read', () => {
		expect(leafHeader({ ...base, loading: true }).count).toBeUndefined()
		expect(leafHeader({ ...base, error: 'failed' }).empty).toBeUndefined()
	})
})

describe('leafSummary', () => {
	it('reports the count, noun, rows and actions', () => {
		const rows = [{ label: 'One' }]
		expect(
			leafSummary({
				loading: false,
				error: '',
				count: 1,
				noun: 'things',
				rows,
			}),
		).toEqual({ count: 1, noun: 'things', rows, actions: [] })
	})

	it('drops rows at zero and reports nothing while loading or after a failed read', () => {
		expect(
			leafSummary({
				loading: false,
				error: '',
				count: 0,
				noun: 'things',
				rows: [{ label: 'x' }],
			}).rows,
		).toEqual([])
		expect(
			leafSummary({ loading: true, error: '', count: 0, noun: 'things' }),
		).toBeNull()
		expect(
			leafSummary({
				loading: false,
				error: 'failed',
				count: 0,
				noun: 'things',
			}),
		).toBeNull()
	})
})

describe('leafReportMixin', () => {
	/**
	 * Evaluate the mixin's computeds against a plain state.
	 *
	 * @param {object} state The component state.
	 * @return {object} The state with the computeds applied.
	 */
	function evaluate(state) {
		const vm = { ...state }
		for (const [name, fn] of Object.entries(leafReportMixin.computed)) {
			Object.defineProperty(vm, name, { get: () => fn.call(vm) })
		}
		return vm
	}

	it('reports to onHeader and onSummary only when the host passed them', () => {
		const onHeader = vi.fn()
		const onSummary = vi.fn()
		const vm = evaluate({
			loading: false,
			error: '',
			onHeader,
			onSummary,
			leafReport: { count: 0, emptyLabel: 'None', noun: 'things' },
		})

		leafReportMixin.watch.hostHeader.handler.call(vm, vm.hostHeader)
		leafReportMixin.watch.hostSummary.handler.call(vm, vm.hostSummary)
		expect(onHeader).toHaveBeenCalledWith({
			actions: [],
			note: '',
			count: 0,
			empty: { name: 'None' },
		})
		expect(onSummary).toHaveBeenCalledWith({
			count: 0,
			noun: 'things',
			rows: [],
			actions: [],
		})

		const bare = evaluate({
			loading: false,
			error: '',
			onHeader: null,
			onSummary: null,
			leafReport: { count: 0, emptyLabel: 'None', noun: 'things' },
		})
		expect(bare.carded).toBe(false)
		expect(bare.summarised).toBe(false)
	})
})

describe('sync leaf wiring', () => {
	it('asks for the library card and reports through the mixin', async () => {
		const { readFileSync } = await import('node:fs')
		const leaf = readFileSync(
			new URL('../../src/integration.js', import.meta.url),
			'utf8',
		)
		const widget = readFileSync(
			new URL('../../src/integration/SyncedFromTab.vue', import.meta.url),
			'utf8',
		)
		expect(leaf).toContain("chrome: 'card'")
		expect(widget).toContain('mixins: [leafReportMixin]')
		expect(widget).toContain('<div v-if="!summarised" class="oc-synced-from"')
	})
})
