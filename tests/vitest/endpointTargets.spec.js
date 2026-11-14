// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The targets editor of an endpoint with several sources
 * (gateway-upstream-routing): the row helpers, the editor, and how
 * EndpointFormFields hands it the form's `targets` and `sticky`.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EndpointFormFields from '@/modals/v2/EndpointFormFields.vue'
import EndpointTargetsField from '@/modals/v2/EndpointTargetsField.vue'
import {
	normaliseTargets,
	parseRule,
	ruleText,
	shares,
	updateTarget,
	weightOf,
} from '@/modals/v2/endpointTargets.js'

const sources = { data: { results: [{ '@self': { id: 'src-oud' }, name: 'Zaken oud' }, { id: 'src-nieuw', title: 'Zaken nieuw' }] } }
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => (String(url).includes('/objects/integriq/source') ? sources : { data: { results: [] } })),
		post: vi.fn().mockResolvedValue({ data: {} }),
	},
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) => defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'inputLabel', 'error', 'helperText', 'options'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name }, this.$slots.default?.())
		},
	})
	return new Proxy({}, {
		get: (_t, key) => (typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined),
		has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
	})
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({ name: 'CnFieldHelper', props: ['text', 'more', 'error'], render: () => h('p') }),
		createObjectStore: () => () => ({}),
	}
})

const global = { mocks: { t: translate, n: (app, one) => one } }

describe('target rows', () => {
	it('keeps sources, whole weights, and a group and rule only when set', () => {
		expect(normaliseTargets([
			{ source: 'a', weight: 95 },
			{ source: 'b', weight: '5.7', group: 'canary', when: {} },
			{ source: 'c', when: { '==': [{ var: 'body.zaaktype' }, 'x'] } },
			null,
			'x',
		])).toEqual([
			{ source: 'a', weight: 95 },
			{ source: 'b', weight: 5, group: 'canary' },
			{ source: 'c', weight: 1, when: { '==': [{ var: 'body.zaaktype' }, 'x'] } },
		])
		expect(normaliseTargets(null)).toEqual([])
	})

	it('reads weights as whole numbers of at least zero', () => {
		expect([weightOf(''), weightOf(null), weightOf('3'), weightOf(0), weightOf(-2), weightOf('x')]).toEqual([1, 1, 3, 0, 1, 1])
	})

	it('changes one row and removes an emptied group or rule', () => {
		const rows = [{ source: 'a', weight: 1, group: 'g', when: { x: 1 } }, { source: 'b', weight: 1 }]
		expect(updateTarget(rows, 0, { group: ' ', when: null })).toEqual([{ source: 'a', weight: 1 }, rows[1]])
		expect(updateTarget(rows, 1, { source: 'c', weight: '7', group: 'canary' })).toEqual([rows[0], { source: 'c', weight: 7, group: 'canary' }])
		expect(rows[1]).toEqual({ source: 'b', weight: 1 })
	})

	it('reads a typed rule as a JSON object or nothing', () => {
		expect(parseRule('')).toEqual({ ok: true, value: null })
		expect(parseRule('{}')).toEqual({ ok: true, value: null })
		expect(parseRule('{"==":[1,1]}')).toEqual({ ok: true, value: { '==': [1, 1] } })
		expect(parseRule('[1]')).toEqual({ ok: false })
		expect(parseRule('{nope')).toEqual({ ok: false })
		expect(ruleText({ a: 1 })).toBe('{"a":1}')
		expect(ruleText(undefined)).toBe('')
	})

	it('shows each target\'s share of its group as the router groups them', () => {
		expect(shares([
			{ weight: 95 },
			{ weight: 5 },
			{ weight: 1, when: { r: 1 }, group: 'zaken' },
			{ weight: 3, when: { r: 2 }, group: 'zaken' },
			{ weight: 2, when: { r: 3 } },
			{ weight: 0, when: { r: 4 } },
		])).toEqual(['95%', '5%', '25%', '75%', '100%', ''])
	})
})

describe('EndpointTargetsField', () => {
	it('lists the sources to pick from, and shows an unknown one by its id', async () => {
		const wrapper = mount(EndpointTargetsField, { props: { targets: [] }, global })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([{ id: 'src-oud', label: 'Zaken oud' }, { id: 'src-nieuw', label: 'Zaken nieuw' }])
		expect(wrapper.vm.sourceOption('src-nieuw')).toEqual({ id: 'src-nieuw', label: 'Zaken nieuw' })
		expect(wrapper.vm.sourceOption('weg')).toEqual({ id: 'weg', label: 'weg' })
		expect(wrapper.vm.sourceOption('')).toBeNull()
	})

	it('adds a second target with weight 5 for a canary', async () => {
		const wrapper = mount(EndpointTargetsField, { props: { targets: [{ source: 'src-oud', weight: 95 }] }, global })
		wrapper.vm.add()
		expect(wrapper.emitted('update:targets')[0][0]).toEqual([{ source: 'src-oud', weight: 95 }, { source: '', weight: 1 }])

		await wrapper.setProps({ targets: [{ source: 'src-oud', weight: 95 }, { source: '', weight: 1 }] })
		wrapper.vm.change(1, { source: 'src-nieuw' })
		await wrapper.setProps({ targets: wrapper.emitted('update:targets')[1][0] })
		wrapper.vm.change(1, { weight: '5' })
		const targets = wrapper.emitted('update:targets')[2][0]
		expect(targets).toEqual([{ source: 'src-oud', weight: 95 }, { source: 'src-nieuw', weight: 5 }])

		await wrapper.setProps({ targets })
		expect(wrapper.vm.share(0)).toBe('95% of its group')
		expect(wrapper.vm.share(1)).toBe('5% of its group')
	})

	it('stores a rule once it reads as JSON, and flags one that does not', () => {
		const wrapper = mount(EndpointTargetsField, { props: { targets: [{ source: 'a', weight: 1 }] }, global })
		wrapper.vm.changeRule(0, '{"==": [')
		expect(wrapper.vm.ruleErrors[0]).toBe(true)
		expect(wrapper.emitted('update:targets')).toBeUndefined()

		wrapper.vm.changeRule(0, '{"==": [{"var": "body.zaaktype"}, "omgevingsvergunning"]}')
		expect(wrapper.vm.ruleErrors[0]).toBe(false)
		expect(wrapper.emitted('update:targets')[0][0]).toEqual([
			{ source: 'a', weight: 1, when: { '==': [{ var: 'body.zaaktype' }, 'omgevingsvergunning'] } },
		])
	})

	it('removes a target', () => {
		const wrapper = mount(EndpointTargetsField, { props: { targets: [{ source: 'a' }, { source: 'b' }] }, global })
		wrapper.vm.remove(0)
		expect(wrapper.emitted('update:targets')[0][0]).toEqual([{ source: 'b', weight: 1 }])
	})

	it('keeps an empty source list when the sources cannot be read', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(EndpointTargetsField, { props: {}, global })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([])
		expect(wrapper.vm.sourcesLoading).toBe(false)
	})
})

describe('EndpointFormFields hands the editor its targets', () => {
	it('passes targets and sticky, writes them back, and renders neither as a plain field', async () => {
		const updateField = vi.fn()
		const fields = [
			{ key: 'name', label: 'Name' },
			{ key: 'targets', label: 'Targets' },
			{ key: 'sticky', label: 'Sticky', widget: 'checkbox' },
		]
		const wrapper = mount(EndpointFormFields, {
			props: { fields, formData: { targets: [{ source: 'a', weight: 2 }], sticky: true }, errors: {}, updateField },
			global,
		})
		await flushPromises()

		expect(wrapper.vm.visibleFields.map((field) => field.key)).toEqual(['name'])
		const editor = wrapper.findComponent(EndpointTargetsField)
		expect(editor.props()).toEqual({ targets: [{ source: 'a', weight: 2 }], sticky: true })

		editor.vm.$emit('update:targets', [])
		editor.vm.$emit('update:sticky', false)
		expect(updateField.mock.calls).toEqual([['targets', []], ['sticky', false]])
	})

	it('gives the editor no targets when the form holds none', async () => {
		const wrapper = mount(EndpointFormFields, {
			props: { fields: [], formData: { targets: 'nope' }, errors: {}, updateField: vi.fn() },
			global,
		})
		await flushPromises()
		expect(wrapper.findComponent(EndpointTargetsField).props()).toEqual({ targets: [], sticky: false })
	})
})
