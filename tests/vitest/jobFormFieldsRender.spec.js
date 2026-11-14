// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * JobFormFields, the only job editor in the app, as an editor sees it: one
 * control per field widget, grouped switches in one grid, and the
 * synchronization picker that appears for a synchronization job and writes
 * `arguments.synchronizationId`. Every control writes through `updateField`.
 * The DOM-free helpers are covered in jobDraft.spec.js; this mounts the
 * template.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: null }))

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => http.get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			props: ['text', 'more', 'error'],
			render() {
				return h('p', { class: 'helper' }, this.error || this.text)
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const control = (name, props) => defineComponent({
		name,
		props: ['modelValue', ...props],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name }, this.$slots.default?.())
		},
	})
	return {
		NcTextField: control('NcTextField', ['label', 'helperText', 'error', 'type', 'disabled']),
		NcSelect: control('NcSelect', ['options', 'inputId', 'ariaLabelCombobox', 'clearable', 'disabled', 'loading', 'placeholder']),
		NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch', ['type', 'disabled']),
		NcDateTimePickerNative: control('NcDateTimePickerNative', ['id', 'type', 'label', 'hideLabel', 'disabled']),
	}
})

const JobFormFields = (await import('@/modals/v2/JobFormFields.vue')).default

const SYNC = 'OCA\\Integriq\\Service\\Job\\SynchronizationAction'

/**
 * Mount the fields.
 *
 * @param {Array} fields The field descriptors.
 * @param {object} formData The job.
 * @param {object} [errors] Field errors.
 * @return {{wrapper: object, updateField: Function}}
 */
function mountFields(fields, formData = {}, errors = {}) {
	const updateField = vi.fn()
	const wrapper = mount(JobFormFields, { props: { fields, formData, errors, updateField }, global: { mocks: { t: (app, text) => text } } })
	return { wrapper, updateField }
}

beforeEach(() => {
	http.get = vi.fn(async () => ({ data: { results: [{ id: 's1', name: 'Zaken ophalen' }, { uuid: 's2', title: 'Personen' }] } }))
})

describe('one control per widget', () => {
	it('draws text, url and number fields with their value, required mark, type and error', async () => {
		const { wrapper, updateField } = mountFields(
			[
				{ key: 'name', label: 'Name', widget: 'text', required: true, description: 'What the job is called' },
				{ key: 'site', label: 'Site', widget: 'url' },
				{ key: 'interval', label: 'Interval', widget: 'number' },
				{ key: 'other', label: 'Other', widget: 'unknown' },
			],
			{ name: 'Nightly', interval: 3600 },
			{ site: 'Not a URL' },
		)
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })

		expect(fields.map((field) => field.props('label'))).toEqual(['Name *', 'Site', 'Interval', 'Other'])
		expect(fields.map((field) => field.props('modelValue'))).toEqual(['Nightly', '', '3600', ''])
		expect(fields.map((field) => field.props('type'))).toEqual(['text', 'url', 'number', undefined])
		expect(fields[0].props('helperText')).toBe('What the job is called')
		expect(fields[1].props('helperText')).toBe('Not a URL')
		expect(fields[1].props('error')).toBe(true)

		await fields[0].vm.$emit('update:modelValue', 'Hourly')
		await fields[2].vm.$emit('update:modelValue', '60')
		await fields[3].vm.$emit('update:modelValue', 'x')
		expect(updateField.mock.calls).toEqual([['name', 'Hourly'], ['interval', 60], ['other', 'x']])
	})

	it('offers enum options with readable job class labels and writes the picked id', async () => {
		const { wrapper, updateField } = mountFields(
			[{ key: 'jobClass', label: 'Action', widget: 'select', required: true, enum: ['OCA\\Integriq\\Service\\Job\\FlowAction', 'Custom\\Job'] }],
			{ jobClass: 'OCA\\Integriq\\Service\\Job\\FlowAction' },
		)
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options')).toEqual([{ id: 'OCA\\Integriq\\Service\\Job\\FlowAction', label: 'Run a flow' }, { id: 'Custom\\Job', label: 'Custom\\Job' }])
		expect(select.props('modelValue')).toEqual({ id: 'OCA\\Integriq\\Service\\Job\\FlowAction', label: 'Run a flow' })
		expect(select.props('clearable')).toBe(false)
		expect(wrapper.find('label').text()).toBe('Action *')

		await select.vm.$emit('update:modelValue', { id: 'Custom\\Job' })
		await select.vm.$emit('update:modelValue', null)
		expect(updateField.mock.calls).toEqual([['jobClass', 'Custom\\Job'], ['jobClass', null]])
	})

	it('writes a textarea and a switch as typed', async () => {
		const { wrapper, updateField } = mountFields(
			[
				{ key: 'description', label: 'Description', widget: 'textarea' },
				{ key: 'isEnabled', label: 'Enabled', widget: 'checkbox' },
			],
			{ description: 'Runs at night', isEnabled: 1 },
		)

		expect(wrapper.find('textarea').element.value).toBe('Runs at night')
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(true)
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).text()).toBe('Enabled')

		await wrapper.find('textarea').setValue('Runs hourly')
		await wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)
		expect(updateField.mock.calls).toEqual([['description', 'Runs hourly'], ['isEnabled', false]])
	})

	it('reads and writes a date-time through the native picker, and clears it on an invalid date', async () => {
		const { wrapper, updateField } = mountFields([{ key: 'scheduleAfter', label: 'Start after', widget: 'datetime' }], { scheduleAfter: '2026-11-13T08:30:00+01:00' })
		const picker = wrapper.findComponent({ name: 'NcDateTimePickerNative' })

		expect(picker.props('type')).toBe('datetime-local')
		expect(picker.props('modelValue')).toEqual(new Date(2026, 10, 13, 8, 30))

		await picker.vm.$emit('update:modelValue', new Date(2026, 10, 14, 9, 0))
		await picker.vm.$emit('update:modelValue', new Date('nope'))
		expect(updateField.mock.calls[0][0]).toBe('scheduleAfter')
		expect(updateField.mock.calls[0][1]).toMatch(/^2026-11-14T09:00:00[+-]\d{2}:\d{2}$/)
		expect(updateField.mock.calls[1]).toEqual(['scheduleAfter', null])
	})

	it('parses a JSON field, keeps invalid text and shows its error', async () => {
		const { wrapper, updateField } = mountFields([{ key: 'arguments', label: 'Arguments', widget: 'json' }], { arguments: { a: 1 } })
		const area = wrapper.find('textarea')

		expect(JSON.parse(area.element.value)).toEqual({ a: 1 })

		await area.setValue('{"b":2}')
		expect(updateField).toHaveBeenLastCalledWith('arguments', { b: 2 })

		await area.setValue('{ broken')
		expect(area.element.value).toBe('{ broken')
		expect(wrapper.find('.helper').text()).not.toBe('')
	})

	it('puts consecutive fields of one group in one grid', () => {
		const { wrapper } = mountFields([
			{ key: 'isEnabled', label: 'Enabled', widget: 'checkbox', group: 'switches' },
			{ key: 'singleRun', label: 'Run once', widget: 'checkbox', group: 'switches' },
			{ key: 'name', label: 'Name', widget: 'text' },
		])
		const runs = wrapper.findAll('.cn-job-form-fields__run')

		expect(runs).toHaveLength(2)
		expect(runs[0].classes()).toContain('cn-job-form-fields__run--grid')
		expect(runs[0].findAll('.cn-job-form-fields__field')).toHaveLength(2)
		expect(runs[1].classes()).not.toContain('cn-job-form-fields__run--grid')
	})
})

describe('the synchronization picker', () => {
	const classField = { key: 'jobClass', label: 'Action', widget: 'select', enum: [SYNC] }

	it('appears after the job class for a synchronization job, lists the synchronizations and writes the nested id', async () => {
		const { wrapper, updateField } = mountFields([classField, { key: 'name', label: 'Name', widget: 'text' }], { jobClass: SYNC, arguments: { keep: true } })
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/integriq/synchronization', { params: { _limit: 500 } })
		const pickers = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(pickers).toHaveLength(2)
		expect(pickers[1].props('inputId')).toBe('cn-job-form-synchronization')
		expect(pickers[1].props('options')).toEqual([{ id: 's1', label: 'Zaken ophalen' }, { id: 's2', label: 'Personen' }])

		await pickers[1].vm.$emit('update:modelValue', { id: 's2' })
		expect(updateField).toHaveBeenCalledWith('arguments', { keep: true, synchronizationId: 's2' })
	})

	it('still shows a stored synchronization the list does not hold', async () => {
		http.get = vi.fn(async () => { throw new Error('down') })
		const { wrapper } = mountFields([classField], { jobClass: SYNC, arguments: { synchronizationId: 'gone' } })
		await flushPromises()

		const picker = wrapper.findAllComponents({ name: 'NcSelect' })[1]
		expect(picker.props('options')).toEqual([])
		expect(picker.props('modelValue')).toEqual({ id: 'gone', label: 'gone' })
		expect(picker.props('loading')).toBe(false)
	})

	it('is absent for another job class and fetches nothing', async () => {
		const { wrapper } = mountFields([classField], { jobClass: 'OCA\\Integriq\\Service\\Job\\FlowAction' })
		await flushPromises()

		expect(wrapper.findAllComponents({ name: 'NcSelect' })).toHaveLength(1)
		expect(http.get).not.toHaveBeenCalled()
	})
})
