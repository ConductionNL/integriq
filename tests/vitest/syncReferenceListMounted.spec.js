// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SyncReferenceList is the multi-select the synchronisation editor uses for
 * its rule and follow-up references. It loads the options of one register
 * schema, shows each stored slug (even one the list does not hold), keeps the
 * current synchronisation out of its own follow-ups, and writes back a plain
 * list of slug strings. These tests mount the real component with only the
 * HTTP client and the select widget stubbed.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SyncReferenceList from '@/views/Synchronization/SyncReferenceList.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: ['inputId', 'inputLabel', 'modelValue', 'options', 'loading', 'placeholder'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'NcSelect' })
			},
		}),
	}
})

/**
 * Mount the list against one schema.
 *
 * @param {object} props The component props.
 * @return {import('@vue/test-utils').VueWrapper} The wrapper.
 */
function mountList(props = {}) {
	return mount(SyncReferenceList, { props: { schema: 'rule', ...props } })
}

describe('SyncReferenceList', () => {
	beforeEach(() => {
		get.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the schema with a prefixed limit and maps rows to slug options', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ slug: 'r-a', name: 'Rule A' },
					{ id: 7, title: 'Legacy by id' },
					{ uuid: 'u-1', slug: '', name: '' },
				],
			},
		})

		const wrapper = mountList()
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('loading')).toBe(true)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/rule',
			{ params: { _limit: 500 } },
		)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual([
			{ id: 'r-a', label: 'Rule A' },
			{ id: '7', label: 'Legacy by id' },
			{ id: 'u-1', label: undefined },
		])
		expect(select.props('loading')).toBe(false)
	})

	it('uses the labelKey column first and accepts a bare array response', async () => {
		get.mockResolvedValue({
			data: [{ slug: 's-1', name: 'Name', summary: 'Summary label' }],
		})

		const wrapper = mountList({ schema: 'synchronization', labelKey: 'summary' })
		await flushPromises()

		expect(get.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/integriq/synchronization')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 's-1', label: 'Summary label' },
		])
	})

	it('leaves the excluded synchronisation out of the options', async () => {
		get.mockResolvedValue({
			data: { results: [{ slug: 'self', name: 'Me' }, { slug: 'other', name: 'Other' }] },
		})

		const wrapper = mountList({ schema: 'synchronization', excludeId: 'self' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 'other', label: 'Other' },
		])
	})

	it('shows stored slugs with their option label, or the slug itself when unknown', async () => {
		get.mockResolvedValue({ data: { results: [{ slug: 'r-a', name: 'Rule A' }] } })

		const wrapper = mountList({ value: ['r-a', 'gone'] })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual([
			{ id: 'r-a', label: 'Rule A' },
			{ id: 'gone', label: 'gone' },
		])
		expect(wrapper.find('.sync-ref-list__empty').exists()).toBe(false)
	})

	it('shows the empty label when nothing is selected or the value is not a list', async () => {
		get.mockResolvedValue({ data: { results: [] } })

		const wrapper = mountList({ value: 'not-a-list', emptyLabel: 'No rules yet' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual([])
		expect(wrapper.find('.sync-ref-list__empty').text()).toBe('No rules yet')
	})

	it('emits the picked options as slug strings, dropping entries without an id', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountList()
		await flushPromises()

		const select = wrapper.findComponent({ name: 'NcSelect' })
		select.vm.$emit('update:modelValue', [{ id: 'a' }, { id: 3 }, { label: 'no id' }, null])
		select.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('input')).toEqual([[['a', '3']], [[]]])
	})

	it('falls back to no options when the fetch fails, and stops loading', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		get.mockRejectedValue(new Error('offline'))

		const wrapper = mountList()
		await flushPromises()

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual([])
		expect(select.props('loading')).toBe(false)
		expect(warn).toHaveBeenCalledWith('[SyncReferenceList] rule fetch failed', expect.any(Error))
	})

	it('refetches when the schema changes', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountList()
		await flushPromises()

		await wrapper.setProps({ schema: 'synchronization' })
		await flushPromises()

		expect(get.mock.calls.map((call) => call[0])).toEqual([
			'/index.php/apps/openregister/api/objects/integriq/rule',
			'/index.php/apps/openregister/api/objects/integriq/synchronization',
		])
	})

	it('gives each instance its own input id', () => {
		get.mockResolvedValue({ data: { results: [] } })
		const first = mountList().findComponent({ name: 'NcSelect' }).props('inputId')
		const second = mountList().findComponent({ name: 'NcSelect' }).props('inputId')

		expect(first).toMatch(/^sync-ref-list-\d+$/)
		expect(second).toMatch(/^sync-ref-list-\d+$/)
		expect(first).not.toBe(second)
	})
})
