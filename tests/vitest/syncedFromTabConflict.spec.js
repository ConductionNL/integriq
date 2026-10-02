// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Synced from" tab says when the connected system refused a local change
 * (zgw-connectors-for-dossiq design D4). The engine keeps the edit and marks
 * the object `syncStatus = conflict`; the provider passes that on each row as
 * `writeBackConflict`. Without the notice the edit looks accepted while the
 * store still holds the old value.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import SyncedFromTab from '@/integration/SyncedFromTab.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * Mount the tab over one answer from the sync-contract endpoint.
 *
 * @param {Array<object>} rows The rows the provider returns.
 * @return {Promise<object>} The mounted wrapper, settled.
 */
async function mountWith(rows) {
	axios.get.mockResolvedValueOnce({ data: { items: rows, results: rows, total: rows.length, nextCursor: null } })
	const wrapper = mount(SyncedFromTab, {
		props: { objectId: 'object-7', register: 'cases', schema: 'case', apiBase: '/api' },
		global: { stubs: { NcLoadingIcon: true, SyncIcon: true } },
	})
	await flushPromises()
	return wrapper
}

const row = { id: 'contract-1', title: 'Zaken API: read', subtitle: '', url: null, originId: 'https://zaken.example/zaken/1' }

describe('SyncedFromTab write-back conflict', () => {
	it('tells the user the connected system refused the last change', async () => {
		const wrapper = await mountWith([{ ...row, writeBackConflict: true }])

		const notice = wrapper.find('[data-testid="oc-synced-from-conflict"]')
		expect(notice.exists()).toBe(true)
		expect(notice.attributes('role')).toBe('status')
		expect(notice.text()).toContain('The connected system refused your last change.')
		expect(wrapper.findAll('.oc-synced-from__row')).toHaveLength(1)
	})

	it('shows no notice while every change was accepted', async () => {
		const wrapper = await mountWith([{ ...row, writeBackConflict: false }])

		expect(wrapper.find('[data-testid="oc-synced-from-conflict"]').exists()).toBe(false)
		expect(wrapper.findAll('.oc-synced-from__row')).toHaveLength(1)
	})
})
