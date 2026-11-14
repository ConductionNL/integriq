// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SyncMappingPicker sets the three mapping references of a synchronisation:
 * the required source-to-target mapping, the optional reverse mapping and the
 * optional hash mapping. It loads the mappings once, shows each stored slug
 * (even one not in the list), previews the primary mapping, and writes back
 * the picked slug or an empty string when a choice is cleared. These tests
 * mount the real component with the HTTP client, the select widget and the
 * preview stubbed.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SyncMappingPicker from '@/views/Synchronization/SyncMappingPicker.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
// The preview's import graph pulls in more of @nextcloud/vue than the picker
// uses; any other Nc* name resolves to a plain stub so the graph loads.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: name })
			},
		})
	const named = {
		NcSelect: stub('NcSelect', ['inputId', 'ariaLabelCombobox', 'modelValue', 'options', 'loading', 'clearable', 'placeholder']),
	}
	return new Proxy(named, {
		get: (target, key) =>
			key in target
				? target[key]
				: typeof key === 'string' && /^Nc/.test(key)
					? stub(key)
					: undefined,
		has: (target, key) =>
			key in target || (typeof key === 'string' && /^Nc/.test(key)),
	})
})

const mocks = { t: (app, text) => text }

/**
 * Mount the picker.
 *
 * @param {object} props The component props.
 * @return {import('@vue/test-utils').VueWrapper} The wrapper.
 */
function mountPicker(props = {}) {
	return mount(SyncMappingPicker, {
		props,
		global: {
			mocks,
			stubs: { SyncMappingPreview: { name: 'SyncMappingPreview', props: ['mappingId'], template: '<div />' } },
		},
	})
}

/**
 * The three selects in template order: primary, reverse, hash.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The wrapper.
 * @return {Array} The select wrappers.
 */
function selects(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' })
}

describe('SyncMappingPicker', () => {
	beforeEach(() => {
		get.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the mappings once with a prefixed limit and offers them in all three selects', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ slug: 'm-a', name: 'Mapping A' },
					{ id: 9, title: 'Legacy' },
					{ uuid: 'u-2', slug: 'u-slug' },
				],
			},
		})

		const wrapper = mountPicker()
		await wrapper.vm.$nextTick()
		expect(selects(wrapper)[0].props('loading')).toBe(true)
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/mapping',
			{ params: { _limit: 500 } },
		)
		const expected = [
			{ id: 'm-a', label: 'Mapping A' },
			{ id: '9', label: 'Legacy' },
			{ id: 'u-slug', label: 'u-slug' },
		]
		for (const select of selects(wrapper)) {
			expect(select.props('options')).toEqual(expected)
			expect(select.props('loading')).toBe(false)
		}
	})

	it('accepts a bare array response', async () => {
		get.mockResolvedValue({ data: [{ slug: 'm-b', name: 'B' }] })

		const wrapper = mountPicker()
		await flushPromises()

		expect(selects(wrapper)[0].props('options')).toEqual([{ id: 'm-b', label: 'B' }])
	})

	it('shows each stored slug, by its option label when known and by itself when not', async () => {
		get.mockResolvedValue({ data: { results: [{ slug: 'm-a', name: 'Mapping A' }] } })

		const wrapper = mountPicker({ value: 'm-a', targetSourceValue: 'missing', hashValue: '' })
		await flushPromises()

		const [primary, reverse, hash] = selects(wrapper)
		expect(primary.props('modelValue')).toEqual({ id: 'm-a', label: 'Mapping A' })
		expect(reverse.props('modelValue')).toEqual({ id: 'missing', label: 'missing' })
		expect(hash.props('modelValue')).toBeNull()
	})

	it('only the optional selects are clearable, and the primary mapping is previewed', async () => {
		get.mockResolvedValue({ data: { results: [] } })

		const wrapper = mountPicker({ value: 'm-a' })
		await flushPromises()

		const [primary, reverse, hash] = selects(wrapper)
		expect(primary.props('clearable')).toBeUndefined()
		expect(reverse.props('clearable')).toBe(true)
		expect(hash.props('clearable')).toBe(true)
		expect(wrapper.findComponent({ name: 'SyncMappingPreview' }).props('mappingId')).toBe('m-a')
	})

	it('emits the picked slug per select, and an empty string when cleared', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountPicker()
		await flushPromises()

		const [primary, reverse, hash] = selects(wrapper)
		primary.vm.$emit('update:modelValue', { id: 'm-a', label: 'A' })
		reverse.vm.$emit('update:modelValue', { id: 'm-r', label: 'R' })
		reverse.vm.$emit('update:modelValue', null)
		hash.vm.$emit('update:modelValue', { id: 'm-h', label: 'H' })

		expect(wrapper.emitted('update:value')).toEqual([['m-a']])
		expect(wrapper.emitted('update:targetSourceValue')).toEqual([['m-r'], ['']])
		expect(wrapper.emitted('update:hashValue')).toEqual([['m-h']])
	})

	it('falls back to no options when the fetch fails, and stops loading', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		get.mockRejectedValue(new Error('offline'))

		const wrapper = mountPicker({ value: 'm-a' })
		await flushPromises()

		const [primary] = selects(wrapper)
		expect(primary.props('options')).toEqual([])
		expect(primary.props('loading')).toBe(false)
		expect(primary.props('modelValue')).toEqual({ id: 'm-a', label: 'm-a' })
		expect(warn).toHaveBeenCalledWith('[SyncMappingPicker] mapping fetch failed', expect.any(Error))
	})

	it('gives the three selects distinct ids, and each instance its own', () => {
		get.mockResolvedValue({ data: { results: [] } })
		const first = selects(mountPicker()).map((select) => select.props('inputId'))
		const second = selects(mountPicker()).map((select) => select.props('inputId'))

		expect(first[0]).toMatch(/^sync-mapping-\d+-primary$/)
		expect(first[1]).toMatch(/^sync-mapping-\d+-reverse$/)
		expect(first[2]).toMatch(/^sync-mapping-\d+-hash$/)
		expect(second[0]).not.toBe(first[0])
	})
})
