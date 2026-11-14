// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the source and endpoint form fields: their markup for
 * every widget, and the value each child listener hands to updateField.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EndpointFormFields from '@/modals/v2/EndpointFormFields.vue'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'

// The targets editor (gateway-upstream-routing) has its own spec, endpointTargets.spec.js.
vi.mock('@/modals/v2/EndpointTargetsField.vue', () => ({ default: { name: 'EndpointTargetsField', render: () => null } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { results: [] } }),
		post: vi.fn().mockResolvedValue({ data: {} }),
	},
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'modelValue',
				'label',
				'inputLabel',
				'error',
				'disabled',
				'placeholder',
				'type',
				'helperText',
			],
			render() {
				return h(
					'div',
					{
						class: name,
						'data-label': this.label ?? this.inputLabel,
						'data-value':
							this.modelValue === null || this.modelValue === undefined
								? undefined
								: String(this.modelValue),
						'data-error': this.error ? 'yes' : undefined,
						'data-disabled': this.disabled ? 'yes' : undefined,
						'data-placeholder': this.placeholder,
						'data-type': this.type,
					},
					this.$slots.default?.(),
				)
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) =>
				typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined,
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			props: ['text', 'more', 'error'],
			render() {
				return h('p', {
					class: 'CnFieldHelper',
					'data-text': this.text,
					'data-more': this.more,
					'data-error': this.error,
				})
			},
		}),
		createObjectStore: () => () => ({}),
	}
})

const WIDGETS = [
	'text',
	'email',
	'url',
	'date',
	'datetime',
	'number',
	'textarea',
	'checkbox',
	'json',
	'other',
]

/**
 * One field descriptor per widget, half of them required, read-only or erroring.
 *
 * @param {string} prefix key prefix
 * @return {object[]} the fields
 */
function fields(prefix) {
	return WIDGETS.map((widget, i) => ({
		key: `${prefix}${widget}`,
		widget,
		label: `Label ${widget}`,
		description: i % 2 ? `About ${widget}` : undefined,
		descriptionLong: i % 3 ? undefined : `More on ${widget}`,
		required: i % 2 === 0,
		readOnly: i % 4 === 1,
	}))
}

const FORMS = [
	['SourceFormFields', SourceFormFields, 's_'],
	['EndpointFormFields', EndpointFormFields, 'e_'],
]

/**
 * The rendered markup without scoped-style ids and HTML comments.
 *
 * @param {object} wrapper the mounted form
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
}

/**
 * Mount a form with values and errors on some fields.
 *
 * @param {object} component the form component
 * @param {string} prefix key prefix
 * @param {Function} updateField the mutator
 * @return {object} the wrapper
 */
function mountForm(component, prefix, updateField) {
	const formData = {
		[`${prefix}text`]: 'hello',
		[`${prefix}number`]: 0,
		[`${prefix}email`]: null,
		[`${prefix}textarea`]: 'long',
		[`${prefix}checkbox`]: 1,
		[`${prefix}other`]: 42,
	}
	const errors = { [`${prefix}number`]: 'bad', [`${prefix}other`]: 'worse' }
	return mount(component, {
		props: { fields: fields(prefix), formData, errors, updateField },
		global: { mocks: { t: translate, n: (app, one) => one } },
	})
}

describe.each(FORMS)('%s', (_name, component, prefix) => {
	it('renders every widget as recorded', async () => {
		const wrapper = mountForm(component, prefix, vi.fn())
		await flushPromises()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('hands every listener value to updateField as recorded', async () => {
		const calls = []
		const wrapper = mountForm(component, prefix, (...args) => calls.push(args))
		await flushPromises()
		const steps = []
		for (const child of wrapper
			.findAllComponents('*')
			.filter((c) => c.vm !== wrapper.vm)) {
			const attrs = child.vm.$attrs || {}
			for (const key of Object.keys(attrs).sort()) {
				if (!/^onUpdate/.test(key) || typeof attrs[key] !== 'function')
					continue
				for (const value of ['typed', '', '7', true]) {
					calls.length = 0
					attrs[key](value)
					steps.push([
						child.vm.$options.name,
						child.props('label') ?? null,
						key,
						value,
						[...calls],
					])
				}
			}
		}
		for (const area of wrapper.findAll('textarea')) {
			calls.length = 0
			await area.setValue('{"a": 1}')
			steps.push(['textarea', area.attributes('id'), [...calls]])
		}
		expect(JSON.parse(JSON.stringify(steps))).toMatchSnapshot()
	})
})
