// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mapping preview as an editor sees it under the mapping picker: folded
 * until opened, a hint without a mapping, and once opened the sample input
 * beside the mapping's output, its failures or a placeholder. The preview's
 * logic is covered over a plain context in syncMappingPreview.spec.js; this
 * mounts the template.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: null, post: null }))

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => http.get(...args), post: (...args) => http.post(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, key) => String(params[key])),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const NcButton = defineComponent({
		name: 'NcButton',
		props: ['ariaLabel'],
		emits: ['click'],
		render() {
			return h('button', { 'aria-label': this.ariaLabel, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	})
	const NcLoadingIcon = defineComponent({ name: 'NcLoadingIcon', render: () => h('span', { class: 'loading' }) })
	return { NcButton, NcLoadingIcon }
})
vi.mock('vue-material-design-icons/ChevronDown.vue', () => ({ default: { name: 'ChevronDown', render: () => null } }))
vi.mock('vue-material-design-icons/ChevronRight.vue', () => ({ default: { name: 'ChevronRight', render: () => null } }))

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match)
globalThis.t = translate

const Preview = (await import('@/views/Synchronization/SyncMappingPreview.vue')).default

/**
 * Mount the preview for a mapping.
 *
 * @param {string} mappingId The picked mapping.
 * @return {object} The wrapper.
 */
function mountPreview(mappingId) {
	return mount(Preview, { props: { mappingId }, global: { mocks: { t: translate } } })
}

/**
 * Open the panel and let its requests settle.
 *
 * @param {object} wrapper The preview.
 * @return {Promise<void>}
 */
async function open(wrapper) {
	await wrapper.find('button').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	http.get = vi.fn(async () => ({ data: { object: { id: 'm1', mapping: { name: 'name' } } } }))
	http.post = vi.fn(async () => ({ data: { resultObject: { name: 'Jan' } } }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('folded and opened', () => {
	it('starts folded with a Show button, and opens to a Hide button', async () => {
		const wrapper = mountPreview('m1')

		expect(wrapper.find('button').attributes('aria-label')).toBe('Show mapping preview')
		expect(wrapper.findComponent({ name: 'ChevronRight' }).exists()).toBe(true)
		expect(wrapper.find('.sync-mapping-preview__body').exists()).toBe(false)
		expect(wrapper.find('.sync-mapping-preview__header--collapsed').exists()).toBe(true)

		await open(wrapper)

		expect(wrapper.find('button').attributes('aria-label')).toBe('Hide mapping preview')
		expect(wrapper.findComponent({ name: 'ChevronDown' }).exists()).toBe(true)
		expect(wrapper.find('.sync-mapping-preview__header--collapsed').exists()).toBe(false)
	})

	it('asks for a mapping first when none is picked, and loads nothing', async () => {
		const wrapper = mountPreview('')

		await open(wrapper)

		expect(wrapper.find('.sync-mapping-preview__empty').text()).toBe('Pick a Source → Target mapping above to enable the preview.')
		expect(wrapper.find('textarea').exists()).toBe(false)
		expect(http.get).not.toHaveBeenCalled()
	})
})

describe('the two panes', () => {
	it('labels the sample input for its textarea and shows the output as JSON', async () => {
		const wrapper = mountPreview('m1')

		await open(wrapper)

		const area = wrapper.find('textarea')
		expect(wrapper.find(`label[for="${area.attributes('id')}"]`).text()).toBe('Sample input (JSON)')
		expect(JSON.parse(area.element.value)).toEqual({ example: 'value' })
		expect(JSON.parse(wrapper.find('pre').text())).toEqual({ name: 'Jan' })
		expect(http.post).toHaveBeenCalledWith('/apps/integriq/api/mappings/test', { inputObject: { example: 'value' }, mapping: { id: 'm1', mapping: { name: 'name' } } })
	})

	it('runs again 300 ms after typing and shows the new output', async () => {
		const wrapper = mountPreview('m1')
		await open(wrapper)
		vi.useFakeTimers()
		http.post = vi.fn(async () => ({ data: { resultObject: { name: 'Piet' } } }))

		await wrapper.find('textarea').setValue('{"name":"Piet"}')
		expect(http.post).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/integriq/api/mappings/test', expect.objectContaining({ inputObject: { name: 'Piet' } }))
		expect(JSON.parse(wrapper.find('pre').text())).toEqual({ name: 'Piet' })
	})

	it('shows an input error under invalid JSON and the placeholder where the output was', async () => {
		const wrapper = mountPreview('m1')
		await open(wrapper)
		vi.useFakeTimers()

		await wrapper.find('textarea').setValue('{ nope')
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()

		expect(wrapper.find('.sync-mapping-preview__pane .sync-mapping-preview__error').text()).toContain('Input is not valid JSON')
		expect(wrapper.find('pre').exists()).toBe(false)
		expect(wrapper.find('.sync-mapping-preview__placeholder').text()).toBe('Type in the input pane to see the transformed output here.')
	})

	it('shows why the mapping could not be loaded in the output pane', async () => {
		http.get = vi.fn(async () => ({ data: null }))
		const wrapper = mountPreview('m1')

		await open(wrapper)

		const errors = wrapper.findAll('.sync-mapping-preview__error')
		expect(errors.map((error) => error.text())).toEqual(['Mapping not found.'])
		expect(http.post).not.toHaveBeenCalled()
	})

	it('shows why the run failed in the output pane', async () => {
		http.post = vi.fn(async () => {
			throw Object.assign(new Error('boom'), { response: { data: { message: 'The mapping has a cycle' } } })
		})
		const wrapper = mountPreview('m1')

		await open(wrapper)

		expect(wrapper.findAll('.sync-mapping-preview__error').map((error) => error.text())).toEqual([expect.stringContaining('The mapping has a cycle')])
		expect(wrapper.find('pre').exists()).toBe(false)
	})

	it('shows the spinner while a run is in flight', async () => {
		let answer
		http.post = vi.fn(() => new Promise((resolve) => { answer = resolve }))
		const wrapper = mountPreview('m1')

		await open(wrapper)
		expect(wrapper.find('.loading').exists()).toBe(true)

		answer({ data: { resultObject: {} } })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
	})
})
