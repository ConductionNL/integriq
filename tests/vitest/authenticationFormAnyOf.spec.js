// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The authentication rule form's mTLS type and its "any one of several
 * methods" list (access-consumer-credentials, tasks 4 and 5): the shape it
 * stores is what EndpointService reads.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AuthenticationForm from '../../src/views/Rule/actionForms/AuthenticationForm.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'options', 'ariaLabelCombobox', 'clearable', 'placeholder'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name, 'data-label': this.label ?? this.ariaLabelCombobox })
		},
	})
	return {
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
		NcButton: defineComponent({
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
	}
})

/**
 * Mount the form on a stored authentication configuration.
 *
 * @param {object} value The configuration
 * @param {boolean} nested Whether it is a method inside an anyOf
 * @return {object} The wrapper
 */
function form(value, nested = false) {
	return mount(AuthenticationForm, { props: { value, nested }, global: { mocks: { t: translate } } })
}

/**
 * The last configuration a wrapper emitted.
 *
 * @param {object} wrapper The form
 * @return {object} The configuration
 */
function last(wrapper) {
	const events = wrapper.emitted('update:value')
	return events[events.length - 1][0]
}

/**
 * The type ids the form's select offers.
 *
 * @param {object} wrapper The form
 * @return {Array<string>} The ids
 */
function typeIds(wrapper) {
	return wrapper.findComponent({ name: 'NcSelect' }).props('options').map((option) => option.id)
}

describe('AuthenticationForm: mTLS and anyOf', () => {
	it('offers anyOf at the top level and mTLS everywhere, but no anyOf inside an anyOf', () => {
		expect(typeIds(form({ type: 'jwt' }))).toEqual(expect.arrayContaining(['mtls', 'anyOf']))
		expect(typeIds(form({ type: 'jwt' }, true))).toContain('mtls')
		expect(typeIds(form({ type: 'jwt' }, true))).not.toContain('anyOf')
	})

	it('turns the configured method into the first method of a new anyOf list', () => {
		const wrapper = form({ type: 'jwt', header: 'X-Token' })
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'anyOf' })

		expect(last(wrapper)).toEqual({ type: 'anyOf', anyOf: [{ type: 'jwt', header: 'X-Token' }] })
	})

	it('drops the anyOf list when another type is picked, so the old methods stop applying', () => {
		const wrapper = form({ type: 'anyOf', anyOf: [{ type: 'apikey' }, { type: 'jwt' }] })
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'jwt' })

		expect(last(wrapper)).toEqual({ type: 'jwt' })
	})

	it('adds, edits and removes methods of the list', async () => {
		const wrapper = form({ type: 'anyOf', anyOf: [{ type: 'apikey' }, { type: 'jwt' }] })
		expect(wrapper.findAll('[data-testid^="auth-anyof-method-"]')).toHaveLength(2)

		await wrapper.find('[data-testid="auth-anyof-add"]').trigger('click')
		expect(last(wrapper).anyOf).toEqual([{ type: 'apikey' }, { type: 'jwt' }, { type: 'apikey' }])

		await wrapper.find('[data-testid="auth-anyof-remove-0"]').trigger('click')
		expect(last(wrapper).anyOf).toEqual([{ type: 'jwt' }])

		wrapper.findAllComponents(AuthenticationForm)[1].vm.$emit('update:value', { type: 'mtls', consumers: ['c1'] })
		expect(last(wrapper).anyOf).toEqual([{ type: 'apikey' }, { type: 'mtls', consumers: ['c1'] }])
	})

	it('stores the consumers an mTLS rule allows, and shows no header field for it', () => {
		const wrapper = form({ type: 'mtls' })
		const labels = wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('label'))
		expect(labels).not.toContain('Header (default: Authorization)')

		wrapper.find('[data-testid="auth-mtls-consumers"]').getCurrentComponent().emit('update:modelValue', ' c1, ,c2 ')
		expect(last(wrapper)).toEqual({ type: 'mtls', consumers: ['c1', 'c2'] })
	})

	it('still shows the header field for an API key', () => {
		const labels = form({ type: 'apikey' }).findAllComponents({ name: 'NcTextField' }).map((f) => f.props('label'))
		expect(labels).toContain('Header (default: Authorization)')
	})
})
