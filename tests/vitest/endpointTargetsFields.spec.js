// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The upstream fields of an api endpoint (gateway-upstream-routing, task 4):
 * without targets the one source is picked; adding a target starts from that
 * source; a second target with weight 5 writes both targets and shows each
 * one's share; the sticky switch shows once there are two targets; a stored
 * rule is kept; and the rows convert both ways.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async () => ({ data: { results: [{ id: 'src-1', name: 'Primary' }, { uuid: 'src-2', name: 'Canary' }] } }),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const control = (name) => defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'options', 'disabled', 'loading', 'clearable', 'type', 'inputLabel', 'min'],
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

const EndpointTargetsFields = (await import('../../src/modals/v2/EndpointTargetsFields.vue')).default
const { sharesOf, targetRowsOf, targetsOf, weightOf } = await import('../../src/modals/v2/endpointTargets.js')

const t = (app, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in params ? String(params[key]) : match))

/**
 * Mount the fields over the given endpoint data.
 *
 * @param {object} formData The endpoint.
 * @return {Promise<{wrapper: object, writes: Array<Array<*>>}>} The wrapper and the field writes.
 */
async function open(formData = {}) {
	const writes = []
	const wrapper = mount(EndpointTargetsFields, {
		props: { formData: { targetType: 'api', ...formData }, updateField: (key, value) => writes.push([key, value]) },
		global: { mocks: { t } },
	})
	await flushPromises()
	return { wrapper, writes }
}

/**
 * The button with the given test id.
 *
 * @param {object} wrapper The mounted fields.
 * @param {string} testid The data-testid.
 * @return {object} The button.
 */
function button(wrapper, testid) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((c) => c.attributes('data-testid') === testid)
}

describe('EndpointTargetsFields', () => {
	it('picks the one source from the sources when the endpoint has no targets', async () => {
		const { wrapper, writes } = await open({ targetId: 'src-1' })
		const select = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('inputLabel') === 'Source')

		expect(select.props('options')).toEqual([{ id: 'src-1', label: 'Primary' }, { id: 'src-2', label: 'Canary' }])
		expect(select.props('modelValue')).toEqual({ id: 'src-1', label: 'Primary' })
		expect(wrapper.text()).toContain('No targets: every call goes to the source above.')

		select.vm.$emit('update:modelValue', { id: 'src-2', label: 'Canary' })
		expect(writes).toContainEqual(['targetId', 'src-2'])
	})

	it('starts the first target from the endpoint\'s own source and adds a canary of weight 5', async () => {
		const { wrapper, writes } = await open({ targetId: 'src-1' })

		button(wrapper, 'endpoint-add-target').vm.$emit('click')
		await flushPromises()
		expect(writes.at(-1)).toEqual(['targets', [{ source: 'src-1', weight: 1 }]])
		expect(wrapper.findAllComponents({ name: 'NcSelect' }).some((c) => c.props('inputLabel') === 'Source')).toBe(false)

		button(wrapper, 'endpoint-add-target').vm.$emit('click')
		await flushPromises()
		const rows = wrapper.findAll('[data-testid="endpoint-target"]')
		expect(rows).toHaveLength(2)

		const sources = wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.props('inputLabel') === 'Target source')
		sources[1].vm.$emit('update:modelValue', { id: 'src-2', label: 'Canary' })
		const weights = wrapper.findAllComponents({ name: 'NcTextField' }).filter((c) => c.props('label') === 'Weight')
		weights[0].vm.$emit('update:modelValue', '95')
		weights[1].vm.$emit('update:modelValue', '5')
		await flushPromises()

		expect(writes.at(-1)).toEqual(['targets', [{ source: 'src-1', weight: 95 }, { source: 'src-2', weight: 5 }]])
		expect(wrapper.findAll('[data-testid="endpoint-target-share"]').map((share) => share.text())).toEqual(['95% of its group', '5% of its group'])
	})

	it('shows the sticky switch only with two targets and writes it as a boolean', async () => {
		const one = await open({ targets: [{ source: 'src-1', weight: 1 }] })
		expect(one.wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).exists()).toBe(false)

		const { wrapper, writes } = await open({ targets: [{ source: 'src-1' }, { source: 'src-2' }], sticky: true })
		const sticky = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(sticky.props('modelValue')).toBe(true)

		sticky.vm.$emit('update:modelValue', false)
		expect(writes).toEqual([['sticky', false]])
	})

	it('keeps a stored rule and says the row has one, and removes a target', async () => {
		const rule = { '==': [{ var: 'body.zaaktype' }, 'bouw'] }
		const { wrapper, writes } = await open({ targets: [{ source: 'src-1', weight: 1 }, { source: 'src-2', weight: 1, when: rule, group: 'bouw' }] })
		expect(wrapper.text()).toContain('Used when its rule matches')

		wrapper.findAllComponents({ name: 'NcTextField' }).filter((c) => c.props('label') === 'Weight')[1].vm.$emit('update:modelValue', '3')
		expect(writes.at(-1)).toEqual(['targets', [{ source: 'src-1', weight: 1 }, { source: 'src-2', weight: 3, group: 'bouw', when: rule }]])

		const remove = wrapper.findAllComponents({ name: 'NcButton' }).find((c) => c.vm.$attrs['aria-label'] === 'Remove target 1')
		remove.vm.$emit('click')
		await flushPromises()
		expect(writes.at(-1)).toEqual(['targets', [{ source: 'src-2', weight: 3, group: 'bouw', when: rule }]])
	})
})

describe('endpointTargets', () => {
	it('turns the stored targets into rows, defaulting the weight and dropping what is not a target', () => {
		expect(targetRowsOf([{ source: 'a' }, null, { source: 'b', weight: 5, group: 'g', when: {} }])).toEqual([
			{ source: 'a', weight: 1, group: '', when: null },
			{ source: 'b', weight: 5, group: 'g', when: null },
		])
		expect(targetRowsOf(undefined)).toEqual([])
	})

	it('writes the rows back without empty sources or groups and with whole weights of at least 0', () => {
		expect(targetsOf([
			{ source: ' a ', weight: '2.7', group: ' ' },
			{ source: '', weight: 5 },
			{ source: 'b', weight: '-4', group: 'g', when: { var: 'x' } },
			{ source: 'c', weight: '' },
		])).toEqual([
			{ source: 'a', weight: 2 },
			{ source: 'b', weight: 0, group: 'g', when: { var: 'x' } },
			{ source: 'c', weight: 1 },
		])
		expect(targetsOf(null)).toEqual([])
		expect(weightOf('abc')).toBe(1)
	})

	it('shares calls within the groups the runtime forms, evenly when a group weighs nothing', () => {
		const rule = { var: 'x' }
		expect(sharesOf([
			{ source: 'a', weight: 3 },
			{ source: 'b', weight: 1, group: 'ignored without a rule' },
			{ source: 'c', weight: 7, when: rule },
			{ source: 'd', weight: 0, when: rule, group: 'g' },
			{ source: 'e', weight: 0, when: rule, group: 'g' },
		])).toEqual([75, 25, 100, 50, 50])
		expect(sharesOf(undefined)).toEqual([])
	})
})
