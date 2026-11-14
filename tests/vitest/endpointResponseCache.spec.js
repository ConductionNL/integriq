// @vitest-environment jsdom

import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The endpoint form writes the `cache` block the endpoint runtime reads
 * (gateway-response-cache-and-problem-errors, REQ-RCPE-001), and its
 * Clear cache button asks the server to forget the endpoint's answers.
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EndpointFormFields from '@/modals/v2/EndpointFormFields.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

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
	return {
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			render() {
				return h('div')
			},
		}),
	}
})

/**
 * Mount the form with the given data.
 *
 * @param {object} formData The endpoint being edited.
 * @return {Promise<object>} The wrapper and the updateField spy.
 */
async function mountWith(formData) {
	axios.get.mockResolvedValue({ data: { results: [] } })
	const updateField = vi.fn()
	const wrapper = mount(EndpointFormFields, {
		props: { fields: [{ key: 'cache', label: 'Cache' }], formData, updateField },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return { wrapper, updateField }
}

describe('EndpointFormFields response cache', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('draws the cache in its own section, not as a schema field', async () => {
		const { wrapper } = await mountWith({})
		expect(wrapper.vm.visibleFields).toEqual([])
		expect(wrapper.text()).toContain('Response cache')
		expect(wrapper.text()).not.toContain('Lifetime in seconds')
	})

	it('turns the cache on and keeps the other settings', async () => {
		const { wrapper, updateField } = await mountWith({
			cache: { ttlSeconds: 30, varyHeaders: ['Accept-Language'] },
		})
		wrapper.vm.setCache('enabled', true)
		expect(updateField).toHaveBeenCalledWith('cache', {
			ttlSeconds: 30,
			varyHeaders: ['Accept-Language'],
			enabled: true,
		})
		expect(wrapper.vm.cacheVaryText).toBe('Accept-Language')
	})

	it('writes a whole lifetime within bounds and ignores a bad one', async () => {
		const { wrapper, updateField } = await mountWith({ cache: { enabled: true } })
		wrapper.vm.setCacheTtl('45.4')
		expect(updateField).toHaveBeenLastCalledWith('cache', {
			enabled: true,
			ttlSeconds: 45,
		})
		wrapper.vm.setCacheTtl('999999')
		expect(updateField).toHaveBeenLastCalledWith('cache', {
			enabled: true,
			ttlSeconds: 86400,
		})
		updateField.mockClear()
		wrapper.vm.setCacheTtl('0')
		wrapper.vm.setCacheTtl('abc')
		expect(updateField).not.toHaveBeenCalled()
	})

	it('splits the vary headers on commas', async () => {
		const { wrapper, updateField } = await mountWith({ cache: { enabled: true } })
		wrapper.vm.setCacheVary(' Accept-Language , ,X-Tenant ')
		expect(updateField).toHaveBeenCalledWith('cache', {
			enabled: true,
			varyHeaders: ['Accept-Language', 'X-Tenant'],
		})
	})

	it('clears the cache of a saved endpoint and says so', async () => {
		axios.post.mockResolvedValue({ data: { endpoint: 'ep-1', cleared: true } })
		const { wrapper } = await mountWith({ id: 'ep 1', cache: { enabled: true } })
		expect(wrapper.text()).toContain('Clear cache')

		await expect(wrapper.vm.clearCache()).resolves.toBe(true)
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/integriq/api/endpoints/ep%201/cache/clear',
		)
		expect(wrapper.vm.cacheNotice).toEqual({
			text: 'The cache of this endpoint is cleared.',
			failed: false,
		})
		expect(wrapper.vm.cacheClearing).toBe(false)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[role="status"]').text()).toBe(
			'The cache of this endpoint is cleared.',
		)
	})

	it('reports a failed clear', async () => {
		axios.post.mockRejectedValue(new Error('403'))
		const { wrapper } = await mountWith({ id: 'ep-1' })

		await expect(wrapper.vm.clearCache()).resolves.toBe(false)
		expect(wrapper.vm.cacheNotice).toEqual({
			text: 'The cache could not be cleared.',
			failed: true,
		})
	})

	it('offers no clear for an endpoint not saved yet', async () => {
		const { wrapper } = await mountWith({})
		expect(wrapper.text()).not.toContain('Clear cache')
		await expect(wrapper.vm.clearCache()).resolves.toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
