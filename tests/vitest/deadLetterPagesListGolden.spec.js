// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of how the two dead-letter pages list their rows and open
 * a row: the request each filter makes, the rows each answer leaves, the
 * error toast, the loading flag, and the detail modal state.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EventDeliveriesPage from '@/views/EventDelivery/EventDeliveriesPage.vue'
import SyncDeadLetterPage from '@/views/Synchronization/SyncDeadLetterPage.vue'

const { get, toasts } = vi.hoisted(() => ({ get: vi.fn(), toasts: [] }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) =>
				typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined,
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})
vi.mock('@/modals/EventDelivery/EventDeliveryDetailModal.vue', () => ({
	default: {
		name: 'EventDeliveryDetailModal',
		props: ['open', 'message'],
		render: () => null,
	},
}))
vi.mock('@/modals/Synchronization/SyncDeadLetterDetailModal.vue', () => ({
	default: {
		name: 'SyncDeadLetterDetailModal',
		props: ['open', 'entry'],
		render: () => null,
	},
}))

beforeEach(() => {
	get.mockReset()
	toasts.length = 0
})

describe.each([
	[
		'EventDeliveriesPage',
		EventDeliveriesPage,
		'subscriptionFilter',
		'EventDeliveryDetailModal',
	],
	[
		'SyncDeadLetterPage',
		SyncDeadLetterPage,
		'synchronizationFilter',
		'SyncDeadLetterDetailModal',
	],
])('%s', (_name, page, filterKey, modalName) => {
	it.each([
		['rows', () => Promise.resolve({ data: { results: [{ uuid: 'a' }] } })],
		['no results', () => Promise.resolve({ data: {} })],
		['a failure', () => Promise.reject(new Error('down'))],
	])('lists %s as recorded', async (_label, answer) => {
		get.mockImplementation(answer)
		const wrapper = mount(page, { global: { mocks: { t: (a, s) => s } } })
		const during = wrapper.vm.loading
		await flushPromises()
		wrapper.vm.selected = ['x']
		wrapper.vm[filterKey] = 'f1'
		wrapper.vm.statusFilter = 'discarded'
		const second = wrapper.vm.reload()
		const selectedDuring = [...wrapper.vm.selected]
		await second
		expect({
			during,
			selectedDuring,
			requests: get.mock.calls,
			rows: wrapper.vm.rows,
			loading: wrapper.vm.loading,
			toasts: [...toasts],
		}).toMatchSnapshot()
	})

	it('drops the rows when a later list fails', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ uuid: 'a' }] } })
		const wrapper = mount(page, { global: { mocks: { t: (a, s) => s } } })
		await flushPromises()
		get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.reload()
		expect([wrapper.vm.rows, toasts.length]).toEqual([[], 1])
	})

	it('opens and closes the detail of a row', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'a' }] } })
		const wrapper = mount(page, { global: { mocks: { t: (a, s) => s } } })
		await flushPromises()
		const steps = [JSON.parse(JSON.stringify(wrapper.vm.detail))]
		wrapper.vm.openDetail({ uuid: 'a' })
		await flushPromises()
		const modal = wrapper.findComponent({ name: modalName })
		steps.push(JSON.parse(JSON.stringify([wrapper.vm.detail, modal.props()])))
		modal.vm.$emit('close')
		await flushPromises()
		steps.push(JSON.parse(JSON.stringify(wrapper.vm.detail)))
		get.mockClear()
		modal.vm.$emit('changed')
		await flushPromises()
		steps.push(get.mock.calls.length)
		expect(steps).toMatchSnapshot()
	})
})
