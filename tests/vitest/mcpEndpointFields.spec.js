// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fields of an mcp endpoint (gateway-mcp-proxy, task 4): the source is
 * picked from the sources, a tool limit row writes `mcpToolLimits`, the stream
 * duration and the argument switch write their properties, and the map and
 * the rows convert both ways.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async () => ({ data: { results: [{ id: 'src-1', name: 'Tool server' }, { id: 'src-2', name: 'Other' }] } }),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const control = (name) => defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'options', 'disabled', 'loading', 'clearable', 'type', 'inputLabel'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name, 'data-testid': this.$attrs['data-testid'], onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	})
	return { NcButton: control('NcButton'), NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'), NcSelect: control('NcSelect'), NcTextField: control('NcTextField') }
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { CnFieldHelper: defineComponent({ name: 'CnFieldHelper', props: ['text'], render() { return h('p', this.text) } }) }
})

const McpEndpointFields = (await import('../../src/modals/v2/McpEndpointFields.vue')).default
const { limitRowsOf, toolLimitsOf } = await import('../../src/modals/v2/mcpToolLimits.js')

const t = (app, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in params ? String(params[key]) : match))

/**
 * Mount the fields over the given endpoint data.
 *
 * @param {object} formData The endpoint.
 * @return {Promise<{wrapper: object, writes: Array<Array<*>>}>} The wrapper and the field writes.
 */
async function open(formData = {}) {
	const writes = []
	const wrapper = mount(McpEndpointFields, {
		props: { formData: { targetType: 'mcp', ...formData }, updateField: (key, value) => writes.push([key, value]) },
		global: { mocks: { t } },
	})
	await flushPromises()
	return { wrapper, writes }
}

describe('McpEndpointFields', () => {
	it('picks the tool server from the sources and writes it as the target', async () => {
		const { wrapper, writes } = await open({ targetId: 'src-2' })
		const select = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('inputLabel') === 'Tool server source')

		expect(select.props('options')).toEqual([{ id: 'src-1', label: 'Tool server' }, { id: 'src-2', label: 'Other' }])
		expect(select.props('modelValue')).toEqual({ id: 'src-2', label: 'Other' })

		select.vm.$emit('update:modelValue', { id: 'src-1', label: 'Tool server' })
		expect(writes).toContainEqual(['targetId', 'src-1'])
	})

	it('shows the stored tool limits and writes a changed one back as the map', async () => {
		const { wrapper, writes } = await open({ mcpToolLimits: { search: { limit: 10, window: 60 } } })
		expect(wrapper.findAll('[data-testid="mcp-tool-limit"]')).toHaveLength(1)

		const calls = wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === 'Calls')
		calls.vm.$emit('update:modelValue', '5')
		expect(writes.at(-1)).toEqual(['mcpToolLimits', { search: { limit: 5, window: 60 } }])
	})

	it('adds a limit row', async () => {
		const { wrapper, writes } = await open()
		expect(wrapper.text()).toContain('No tool has its own limit.')

		wrapper.findAllComponents({ name: 'NcButton' }).find((c) => c.attributes('data-testid') === 'mcp-add-limit').vm.$emit('click')
		await flushPromises()
		expect(wrapper.findAll('[data-testid="mcp-tool-limit"]')).toHaveLength(1)
		expect(writes.at(-1)).toEqual(['mcpToolLimits', {}])
	})

	it('writes the stream duration as a positive whole number and the argument switch as a boolean', async () => {
		const { wrapper, writes } = await open()
		const seconds = wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === 'Maximum stream duration (seconds)')
		expect(seconds.props('modelValue')).toBe('60')

		seconds.vm.$emit('update:modelValue', '15')
		seconds.vm.$emit('update:modelValue', '-3')
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)

		expect(writes).toEqual([['mcpMaxStreamSeconds', 15], ['mcpMaxStreamSeconds', 60], ['mcpStoreArguments', true]])
	})
})

describe('mcpToolLimits', () => {
	it('turns the map into rows and back, leaving out incomplete rows', () => {
		expect(limitRowsOf({ search: { limit: 10, window: 60 } })).toEqual([{ tool: 'search', limit: 10, window: 60 }])
		expect(limitRowsOf(null)).toEqual([])
		expect(toolLimitsOf([
			{ tool: ' search ', limit: '10', window: '60' },
			{ tool: '', limit: 5, window: 5 },
			{ tool: 'fetch', limit: 0, window: 60 },
		])).toEqual({ search: { limit: 10, window: 60 } })
	})
})
