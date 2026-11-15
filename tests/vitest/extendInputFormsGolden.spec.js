// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the two extend-input action forms (extend input and
 * extend external input): their markup, and the config each edit, add,
 * remove and switch emits.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ExtendExternalInputForm from '@/views/Rule/actionForms/ExtendExternalInputForm.vue'
import ExtendInputForm from '@/views/Rule/actionForms/ExtendInputForm.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: [
				'modelValue',
				'label',
				'placeholder',
				'variant',
				'type',
				'ariaLabel',
			],
			emits: ['update:modelValue'],
			render() {
				return h(
					tag,
					{
						class: name,
						'data-label': this.label,
						'data-value': String(this.modelValue ?? ''),
						'data-placeholder': this.placeholder,
					},
					[this.$slots.icon?.(), this.$slots.default?.()],
				)
			},
		})
	return {
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcTextField: stub('NcTextField'),
	}
})

const FORMS = [
	{
		name: 'ExtendInputForm',
		component: ExtendInputForm,
		value: {
			keep: 1,
			properties: ['a.b', '', 'c'],
			extends: { 'a.b': ['x', 'y'], c: 'not-a-list' },
		},
		second: ' x.y , ,z ',
	},
	{
		name: 'ExtendExternalInputForm',
		component: ExtendExternalInputForm,
		value: {
			keep: 1,
			validate: 1,
			properties: [{ property: 'p', schema: 's' }, null, { property: 'q' }],
		},
		second: 'contact',
	},
]

/**
 * The rendered markup without the scoped-style ids, which follow the file.
 *
 * @param {object} wrapper the mounted form
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+=""/g, '')
}

/**
 * Mount a form on a config.
 *
 * @param {object} form the form case
 * @param {object} value the action config
 * @return {object} the wrapper
 */
function mountForm(form, value) {
	return mount(form.component, {
		props: { value },
		global: { mocks: { t: translate } },
	})
}

describe.each(FORMS)('$name', (form) => {
	it.each([
		['a config', () => form.value],
		['an empty config', () => ({})],
		[
			'properties that are not a list',
			() => ({ properties: 'x', extends: 'y' }),
		],
	])('renders %s as recorded', (_label, value) => {
		expect(markup(mountForm(form, value()))).toMatchSnapshot()
	})

	it('emits every edit as recorded', async () => {
		const steps = []
		const fields = mountForm(form, form.value).findAllComponents({
			name: 'NcTextField',
		})
		for (const [i] of fields.entries()) {
			for (const typed of ['typed', '', form.second]) {
				const wrapper = mountForm(form, form.value)
				wrapper
					.findAllComponents({ name: 'NcTextField' })
					[i].vm.$emit('update:modelValue', typed)
				steps.push([i, typed, wrapper.emitted('update:value')])
			}
		}
		const buttons = mountForm(form, form.value).findAll('button')
		for (const [i] of buttons.entries()) {
			const wrapper = mountForm(form, form.value)
			await wrapper.findAll('button')[i].trigger('click')
			steps.push(['button', i, wrapper.emitted('update:value')])
		}
		for (const checked of [true, false, 0]) {
			const wrapper = mountForm(form, form.value)
			const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
			if (toggle.exists()) {
				toggle.vm.$emit('update:modelValue', checked)
				steps.push(['switch', checked, wrapper.emitted('update:value')])
			}
		}
		const empty = mountForm(form, {})
		await empty.findAll('button').at(-1).trigger('click')
		steps.push(['add to empty', empty.emitted('update:value')])
		expect(JSON.parse(JSON.stringify(steps))).toMatchSnapshot()
	})
})
