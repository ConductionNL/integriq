// @vitest-environment jsdom

import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the mounted "Synced from" leaf reports to a carding host (`onHeader`)
 * and to a "linked" card (`onSummary`), and what it draws for each. The
 * leafReport tests cover the mixin over a hand-built report; these cover the
 * component's own mapping of its rows.
 *
 * @spec openspec/changes/leaves-library-card/specs/leaves-library-card/spec.md#requirement-the-sync-leaf-reports-to-the-library-card-and-the-linked-card
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SyncedFromTab from '@/integration/SyncedFromTab.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const row = {
	id: 'contract-1',
	title: 'Zaken API: read',
	subtitle: '',
	url: '/apps/integriq/synchronizations/sync-1',
	originId: 'https://zaken.example/zaken/1',
}

/**
 * Mount the leaf over one answer from the sync-contract endpoint.
 *
 * @param {Array<object>} rows The rows the provider returns.
 * @param {object} hostProps The host callbacks (`onHeader` / `onSummary`).
 * @return {Promise<object>} The mounted wrapper, settled.
 */
async function mountWith(rows, hostProps) {
	axios.get.mockResolvedValueOnce({
		data: { items: rows, results: rows, total: rows.length, nextCursor: null },
	})
	const wrapper = mount(SyncedFromTab, {
		props: {
			objectId: 'object-7',
			register: 'cases',
			schema: 'case',
			apiBase: '/api',
			...hostProps,
		},
		global: { stubs: { NcLoadingIcon: true, SyncIcon: true } },
	})
	await flushPromises()
	return wrapper
}

describe('SyncedFromTab in a library card (onHeader)', () => {
	it('reports a zero count and the empty state, and draws no empty line of its own', async () => {
		const onHeader = vi.fn()
		const wrapper = await mountWith([], { onHeader })

		expect(onHeader).toHaveBeenLastCalledWith({
			actions: [],
			note: '',
			count: 0,
			empty: { name: 'This object was not created by a synchronization.' },
		})
		expect(wrapper.find('[data-testid="oc-synced-from"]').exists()).toBe(true)
		expect(wrapper.find('.oc-synced-from__empty').exists()).toBe(false)
	})

	it('reports the count without an empty state and still draws the rows', async () => {
		const onHeader = vi.fn()
		const wrapper = await mountWith([row], { onHeader })

		expect(onHeader).toHaveBeenLastCalledWith({
			actions: [],
			note: '',
			count: 1,
		})
		expect(wrapper.findAll('.oc-synced-from__row')).toHaveLength(1)
	})
})

describe('SyncedFromTab in a linked card (onSummary)', () => {
	it('reports an empty summary and draws nothing', async () => {
		const onSummary = vi.fn()
		const wrapper = await mountWith([], { onSummary })

		expect(onSummary).toHaveBeenLastCalledWith({
			count: 0,
			noun: 'synchronizations',
			rows: [],
			actions: [],
		})
		expect(wrapper.find('[data-testid="oc-synced-from"]').exists()).toBe(false)
		expect(wrapper.text()).toBe('')
	})

	it('reports a row per synchronization with its label, link and icon', async () => {
		const onSummary = vi.fn()
		await mountWith([row, { ...row, id: 'contract-2', title: '', url: null }], {
			onSummary,
		})

		expect(onSummary).toHaveBeenLastCalledWith({
			count: 2,
			noun: 'synchronizations',
			rows: [
				{
					label: 'Zaken API: read',
					href: '/apps/integriq/synchronizations/sync-1',
					icon: 'SyncOutline',
				},
				{ label: 'Synchronization', href: '', icon: 'SyncOutline' },
			],
			actions: [],
		})
	})

	it('replaces the earlier summary when the re-read for another record fails', async () => {
		const onSummary = vi.fn()
		const wrapper = await mountWith([row], { onSummary })
		expect(onSummary).toHaveBeenLastCalledWith(
			expect.objectContaining({ count: 1 }),
		)

		axios.get.mockRejectedValueOnce(new Error('503'))
		await wrapper.setProps({ objectId: 'object-8' })
		await flushPromises()

		expect(onSummary).toHaveBeenLastCalledWith({
			count: 0,
			noun: '',
			rows: [],
			actions: [],
			unavailable: true,
		})
	})
})
