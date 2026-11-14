// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Tables column mapping and the Forms question list as an editor sees
 * them in the synchronization editor: a spinner while the bridge answers, its
 * error or an empty sentence, else one row per column or question. Typing a
 * column's value emits the whole config with that column mapped. Their logic
 * is covered over a plain context in tablesColumnMapping.spec.js and
 * formsFieldMapping.spec.js; this mounts the templates.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: null }))

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => http.get(...args) } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, key) => String(params[key])),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcLoadingIcon: defineComponent({ name: 'NcLoadingIcon', render: () => h('span', { class: 'loading' }) }),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label', 'placeholder'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'NcTextField' })
			},
		}),
	}
})

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match)
globalThis.t = translate

const TablesColumnMapping = (await import('@/views/Synchronization/TablesColumnMapping.vue')).default
const FormsFieldMapping = (await import('@/views/Synchronization/FormsFieldMapping.vue')).default

/**
 * Mount a component with props.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountWith(component, props) {
	return mount(component, { props, global: { mocks: { t: translate } } })
}

beforeEach(() => {
	http.get = vi.fn()
})

describe('the Tables column mapping', () => {
	const columns = [
		{ id: 1, title: 'Naam', type: 'text', mandatory: true },
		{ id: 2, title: 'Aantal', type: 'number', constraints: { numberDecimals: 2 } },
	]

	it('draws one row per column with its title, required mark, type and mapped value', async () => {
		http.get.mockResolvedValue({ data: { results: columns } })
		const wrapper = mountWith(TablesColumnMapping, { sourceId: 's1', tableId: 7, config: { columnMapping: [{ column: 'Naam', value: 'name' }] } })
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/integriq/api/synchronizations/tables-bridge/tables/7/columns', expect.anything())
		const rows = wrapper.findAll('.tables-column-mapping__row')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('.tables-column-mapping__col-title').text()).toContain('Naam')
		expect(rows[0].find('.tables-column-mapping__required').attributes('title')).toBe('Required column')
		expect(rows[1].find('.tables-column-mapping__required').exists()).toBe(false)
		expect(rows[1].find('.tables-column-mapping__col-type').text()).toBe('number (2 decimals)')

		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		expect(fields.map((field) => field.props('label'))).toEqual(['Value for Naam', 'Value for Aantal'])
		expect(fields.map((field) => field.props('modelValue'))).toEqual(['name', ''])
	})

	it('emits the config with the typed column mapped and the others kept', async () => {
		http.get.mockResolvedValue({ data: { results: columns } })
		const wrapper = mountWith(TablesColumnMapping, { sourceId: 's1', tableId: 7, config: { columnMapping: [{ column: 'Naam', value: 'name' }] } })
		await flushPromises()

		await wrapper.findAllComponents({ name: 'NcTextField' })[1].vm.$emit('update:modelValue', ' {{ amount }} ')

		expect(wrapper.emitted('update:config')).toEqual([[{ columnMapping: [{ column: 'Naam', value: 'name' }, { column: 'Aantal', value: '{{ amount }}' }] }]])
	})

	it('shows a spinner while the bridge answers, then its error', async () => {
		let fail
		http.get.mockReturnValue(new Promise((resolve, reject) => { fail = reject }))
		const wrapper = mountWith(TablesColumnMapping, { sourceId: 's1', tableId: 7 })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('.tables-column-mapping__row').exists()).toBe(false)

		fail(Object.assign(new Error('x'), { response: { data: { error: 'Table not found' } } }))
		await flushPromises()

		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('.tables-column-mapping__error').text()).toBe('Table not found')
	})

	it('says a table without columns has nothing to map', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountWith(TablesColumnMapping, { sourceId: 's1', tableId: 7 })
		await flushPromises()

		expect(wrapper.find('.tables-column-mapping__empty').text()).toBe('This table has no columns to map.')
	})
})

describe('the Forms question list', () => {
	it('lists each question with its id and type, and badges array answers and duplicate texts', async () => {
		http.get.mockResolvedValue({
			data: [
				{ id: 3, text: 'Naam', type: 'short' },
				{ id: 4, text: 'Kleuren', type: 'multiple' },
				{ id: 5, text: 'Naam', type: 'long' },
			],
		})
		const wrapper = mountWith(FormsFieldMapping, { sourceId: 's1', formId: 9 })
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/integriq/api/synchronizations/forms-bridge/forms/9/questions', expect.anything())
		const rows = wrapper.findAll('.forms-field-mapping__row')
		expect(rows.map((row) => row.find('.forms-field-mapping__question-id').text())).toEqual(['#3', '#4', '#5'])
		expect(rows.map((row) => row.find('.forms-field-mapping__question-type').text())).toEqual(['short', 'multiple', 'long'])
		expect(rows.map((row) => row.find('.forms-field-mapping__badge--array').exists())).toEqual([false, true, false])
		expect(rows.map((row) => row.find('.forms-field-mapping__badge--ambiguous').exists())).toEqual([true, false, true])
	})

	it('shows a spinner while the bridge answers, then its error', async () => {
		let fail
		http.get.mockReturnValue(new Promise((resolve, reject) => { fail = reject }))
		const wrapper = mountWith(FormsFieldMapping, { sourceId: 's1', formId: 9 })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.loading').exists()).toBe(true)

		fail(Object.assign(new Error('x'), { response: { data: { error: 'Form not found' } } }))
		await flushPromises()

		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('.forms-field-mapping__error').text()).toBe('Form not found')
	})

	it('says a form without questions has nothing to reference, and asks nothing without a form', async () => {
		http.get.mockResolvedValue({ data: [] })
		const wrapper = mountWith(FormsFieldMapping, { sourceId: 's1', formId: 9 })
		await flushPromises()
		expect(wrapper.find('.forms-field-mapping__empty').text()).toBe('This form has no questions to reference.')

		http.get.mockClear()
		const none = mountWith(FormsFieldMapping, { sourceId: 's1', formId: '' })
		await flushPromises()
		expect(http.get).not.toHaveBeenCalled()
		expect(none.find('.forms-field-mapping__empty').exists()).toBe(true)
	})
})
