// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ApprovalsIndex against an axios fake: the status-filtered load from the
 * approvals endpoint, the table or empty state it renders, the failure toast
 * and empty list on a failed load, and navigation into a request's detail page.
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApprovalsIndex from '@/views/Approvals/ApprovalsIndex.vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant'],
			emits: ['click'],
			render() {
				return h('button', { class: 'NcButton', onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return h('div', { class: 'NcEmptyContent' }, [this.name, ' ', this.description])
			},
		}),
		NcLoadingIcon: defineComponent({
			name: 'NcLoadingIcon',
			props: ['size'],
			render() {
				return h('div', { class: 'NcLoadingIcon' })
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'NcSelect' })
			},
		}),
	}
})

const URL = '/index.php/apps/integriq/api/approvals'

/**
 * Mount the page with a router spy.
 *
 * @return {{wrapper: object, push: Function}} The wrapper and the push spy.
 */
async function mountPage() {
	const push = vi.fn()
	const wrapper = mount(ApprovalsIndex, {
		global: { mocks: { $router: { push } }, stubs: { CheckboxMarkedCircleOutline: true } },
	})
	await flushPromises()
	return { wrapper, push }
}

describe('ApprovalsIndex', () => {
	beforeEach(() => {
		http.get.mockReset()
		toast.showError.mockReset()
	})

	it('loads the pending requests on mount and renders one row each', async () => {
		http.get.mockResolvedValue({
			data: {
				results: [
					{ id: 'a1', status: 'pending', approverGroup: 'finance', requester: 'alice', createdAt: '2026-01-01', expiresAt: '2026-01-08' },
					{ id: 'a2', status: 'pending', approverGroup: 'ops' },
				],
			},
		})
		const { wrapper } = await mountPage()

		expect(http.get).toHaveBeenCalledTimes(1)
		expect(http.get).toHaveBeenCalledWith(URL, { params: { status: 'pending' } })
		const rows = wrapper.findAll('[data-testid="approvals-table"] tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].findAll('td').map((td) => td.text()).slice(0, 5)).toEqual(['pending', 'finance', 'alice', '2026-01-01', '2026-01-08'])
		expect(rows[1].findAll('td').map((td) => td.text()).slice(0, 5)).toEqual(['pending', 'ops', '—', '—', '—'])
		expect(rows[0].find('.approvals__badge').classes()).toContain('approvals__badge--pending')
		expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the empty state when the endpoint returns no results', async () => {
		http.get.mockResolvedValue({ data: {} })
		const { wrapper } = await mountPage()

		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.find('[data-testid="empty-state"]').text()).toContain('No approval requests')
		expect(wrapper.find('[data-testid="approvals-table"]').exists()).toBe(false)
	})

	it('shows the loading icon while a load is in flight', async () => {
		let resolve
		http.get.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(ApprovalsIndex, { global: { mocks: { $router: { push: vi.fn() } } } })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.find('.NcLoadingIcon').exists()).toBe(true)
		resolve({ data: { results: [] } })
		await flushPromises()
		expect(wrapper.find('.NcLoadingIcon').exists()).toBe(false)
	})

	it('toasts and clears the rows when the load fails', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [{ id: 'a1', status: 'pending', approverGroup: 'g' }] } })
		const { wrapper } = await mountPage()
		expect(wrapper.vm.rows).toHaveLength(1)

		http.get.mockRejectedValueOnce(new Error('boom'))
		await wrapper.vm.reload()

		expect(toast.showError).toHaveBeenCalledWith('Failed to load approval requests')
		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reloads with the chosen status, and without a status param when the filter is cleared', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const { wrapper } = await mountPage()

		wrapper.vm.statusFilter = 'dead_letter'
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'dead_letter')
		await flushPromises()
		expect(http.get).toHaveBeenLastCalledWith(URL, { params: { status: 'dead_letter' } })

		wrapper.vm.statusFilter = null
		await wrapper.vm.reload()
		expect(http.get).toHaveBeenLastCalledWith(URL, { params: {} })
	})

	it('offers the five statuses as filter options', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const { wrapper } = await mountPage()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(['pending', 'approved', 'rejected', 'expired', 'dead_letter'])
	})

	it('navigates to the detail page of the opened row', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 'req-9', status: 'approved', approverGroup: 'g' }] } })
		const { wrapper, push } = await mountPage()

		await wrapper.find('tbody .NcButton').trigger('click')
		expect(push).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith('/approvals/req-9')
	})
})
