// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule editor authors an authentication rule that accepts any one of
 * several methods: the `anyOf` shape EndpointService::authenticateAnyOf()
 * reads, with the scope lists kept on the rule.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import {
	isAnyOf,
	methodsOf,
	withAddedMethod,
	withAnyOf,
	withMethod,
	withoutMethod,
} from '../../src/views/Rule/actionForms/authMethods.js'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'type', 'placeholder', 'options', 'ariaLabelCombobox', 'clearable', 'variant'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name, 'data-label': this.label ?? this.ariaLabelCombobox }, [this.$slots.default?.()])
		},
	})
	return {
		NcButton: field('NcButton'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
	}
})

const AuthenticationForm = (await import('../../src/views/Rule/actionForms/AuthenticationForm.vue')).default

/**
 * Mount the form with a stored configuration.
 *
 * @param {object} value The rule's authentication configuration.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(value) {
	const wrapper = mount(AuthenticationForm, { props: { value }, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

/**
 * The last configuration the outer form emitted.
 *
 * @param {object} wrapper The form.
 * @return {object|undefined} The configuration.
 */
function lastEmitted(wrapper) {
	return wrapper.emitted('update:value')?.at(-1)?.[0]
}

describe('authMethods', () => {
	it('tells the anyOf shape from one method', () => {
		expect(isAnyOf({ anyOf: [] })).toBe(true)
		expect(isAnyOf({ type: 'jwt' })).toBe(false)
		expect(isAnyOf(undefined)).toBe(false)
	})

	it('lists the methods of either shape, without the rule-level scopes', () => {
		expect(methodsOf({ type: 'apikey', keys: ['k'], requiredScopes: ['a'] })).toEqual([{ type: 'apikey', keys: ['k'] }])
		expect(methodsOf({ anyOf: [{ type: 'jwt' }, null] })).toEqual([{ type: 'jwt' }, {}])
	})

	it('turns one method into the first of an anyOf list and keeps the scopes on the rule', () => {
		expect(withAnyOf({ type: 'apikey', keys: ['k'], requiredScopes: ['zaken:read'] }, true)).toEqual({
			requiredScopes: ['zaken:read'],
			anyOf: [{ type: 'apikey', keys: ['k'] }],
		})
	})

	it('starts an anyOf list from an API key when no type was picked yet', () => {
		expect(withAnyOf({}, true)).toEqual({ anyOf: [{ type: 'apikey' }] })
	})

	it('turns an anyOf list back into its first method with the rule scopes', () => {
		expect(withAnyOf({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }], requiredScopes: ['s'] }, false)).toEqual({
			type: 'jwt',
			requiredScopes: ['s'],
		})
	})

	it('leaves a configuration already in the asked shape as it is', () => {
		expect(withAnyOf({ type: 'jwt' }, false)).toEqual({ type: 'jwt' })
		expect(withAnyOf({ anyOf: [{ type: 'jwt' }] }, true)).toEqual({ anyOf: [{ type: 'jwt' }] })
	})

	it('replaces one method and drops an anyOf nested in it', () => {
		const value = { anyOf: [{ type: 'apikey' }, { type: 'jwt' }], requiredScopes: ['s'] }
		expect(withMethod(value, 1, { type: 'basic', users: ['alice'], anyOf: [{ type: 'jwt' }] })).toEqual({
			anyOf: [{ type: 'apikey' }, { type: 'basic', users: ['alice'] }],
			requiredScopes: ['s'],
		})
	})

	it('adds a method as an API key and removes one, keeping the last', () => {
		const value = { anyOf: [{ type: 'jwt' }] }
		expect(withAddedMethod(value)).toEqual({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }] })
		expect(withoutMethod({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }] }, 0)).toEqual({ anyOf: [{ type: 'apikey' }] })
		expect(withoutMethod(value, 0)).toEqual({ anyOf: [{ type: 'jwt' }] })
	})
})

describe('AuthenticationForm with anyOf', () => {
	it('switches a single method into an anyOf list', async () => {
		const wrapper = await mountForm({ type: 'apikey', keys: ['k'] })

		expect(wrapper.findAll('[data-testid="rule-auth-method"]')).toHaveLength(0)
		await wrapper.findComponent('[data-testid="rule-auth-any-of"]').vm.$emit('update:modelValue', true)

		expect(lastEmitted(wrapper)).toEqual({ anyOf: [{ type: 'apikey', keys: ['k'] }] })
	})

	it('shows one nested form per method, without its own switch or scope lists', async () => {
		const wrapper = await mountForm({ anyOf: [{ type: 'apikey' }, { type: 'jwt' }], requiredScopes: ['zaken:read'] })

		expect(wrapper.findAll('[data-testid="rule-auth-method"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid="rule-auth-any-of"]')).toHaveLength(1)
		expect(wrapper.findAll('[data-testid="rule-required-scopes-read"]')).toHaveLength(1)
		const nested = wrapper.findAllComponents({ name: 'AuthenticationForm' }).filter((c) => c.props('nested'))
		expect(nested.map((c) => c.props('value'))).toEqual([{ type: 'apikey' }, { type: 'jwt' }])
	})

	it('stores an edit inside one method in its place in the list', async () => {
		const wrapper = await mountForm({ anyOf: [{ type: 'apikey' }, { type: 'jwt' }] })

		const nested = wrapper.findAllComponents({ name: 'AuthenticationForm' }).filter((c) => c.props('nested'))
		await nested[1].vm.$emit('update:value', { type: 'jwt-zgw' })

		expect(lastEmitted(wrapper)).toEqual({ anyOf: [{ type: 'apikey' }, { type: 'jwt-zgw' }] })
	})

	it('adds and removes methods, and offers no remove on the last one', async () => {
		const wrapper = await mountForm({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }] })

		await wrapper.findComponent('[data-testid="rule-auth-method-add"]').vm.$emit('click')
		expect(lastEmitted(wrapper)).toEqual({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }, { type: 'apikey' }] })

		await wrapper.findAllComponents('[data-testid="rule-auth-method-remove"]')[0].vm.$emit('click')
		expect(lastEmitted(wrapper)).toEqual({ anyOf: [{ type: 'apikey' }] })

		const single = await mountForm({ anyOf: [{ type: 'jwt' }] })
		expect(single.findAll('[data-testid="rule-auth-method-remove"]')).toHaveLength(0)
	})

	it('keeps the rule scopes when a scope list is edited on an anyOf rule', async () => {
		const wrapper = await mountForm({ anyOf: [{ type: 'jwt' }] })

		await wrapper.findComponent('[data-testid="rule-required-scopes-write"]').vm.$emit('update:modelValue', 'zaken:write')

		expect(lastEmitted(wrapper)).toEqual({
			anyOf: [{ type: 'jwt' }],
			requiredScopes: { POST: ['zaken:write'], PUT: ['zaken:write'], PATCH: ['zaken:write'], DELETE: ['zaken:write'] },
		})
	})

	it('switches back to the first method', async () => {
		const wrapper = await mountForm({ anyOf: [{ type: 'jwt' }, { type: 'apikey' }] })

		await wrapper.findComponent('[data-testid="rule-auth-any-of"]').vm.$emit('update:modelValue', false)

		expect(lastEmitted(wrapper)).toEqual({ type: 'jwt' })
	})
})
