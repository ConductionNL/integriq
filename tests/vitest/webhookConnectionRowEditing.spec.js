// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One webhook connection row: the account state line, the empty-handlers
 * warning, the defaults it starts from, the account search, and saving the
 * account, scheme, secret and header with the server's warnings and refusals.
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, put, toasts } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), toasts: [] }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
	showWarning: (text) => toasts.push(['warning', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') => defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'inputLabel', 'label', 'placeholder', 'options', 'disabled', 'variant', 'loading', 'reduce', 'clearable', 'type'],
		emits: ['update:modelValue', 'search'],
		setup(props, { attrs, slots }) {
			return () => h(tag, { class: name, 'data-testid': attrs['data-testid'], onClick: attrs.onClick }, slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton', 'button'),
		NcNoteCard: stub('NcNoteCard'),
		NcPasswordField: stub('NcPasswordField'),
		NcSelect: stub('NcSelect'),
		NcSelectUsers: stub('NcSelectUsers'),
		NcTextField: stub('NcTextField'),
	}
})

const { default: WebhookConnectionRow } = await import('@/views/admin/WebhookConnectionRow.vue')

/**
 * A connection as the server lists it.
 *
 * @param {object} overrides Fields to change.
 * @return {object} The connection.
 */
function connection(overrides = {}) {
	return {
		authorizationType: 'github',
		label: 'GitHub',
		userId: 'hook-bot',
		account: { state: 'ok', displayName: 'Hook Bot' },
		scheme: 'github',
		header: 'X-Hub-Signature-256',
		secretConfigured: true,
		...overrides,
	}
}

/**
 * Mount a row.
 *
 * @param {object} value The connection.
 * @return {object} The wrapper.
 */
function mountRow(value) {
	return mount(WebhookConnectionRow, { props: { connection: value }, global: { mocks: { t: translate } } })
}

const part = (wrapper, name) => wrapper.findComponent({ name })
const state = (wrapper) => wrapper.find('[data-testid="admin-webhook-state-github"]')

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	toasts.length = 0
})

describe('WebhookConnectionRow', () => {
	it('starts from the stored account, scheme and header and says deliveries are stored', () => {
		const wrapper = mountRow(connection())

		expect(wrapper.find('h4').text()).toBe('GitHub')
		expect(state(wrapper).text()).toBe('Deliveries are stored as Hook Bot')
		expect(state(wrapper).classes()).not.toContain('integriq-admin__action-error')
		expect(part(wrapper, 'NcSelectUsers').props('modelValue')).toEqual({ id: 'hook-bot', user: 'hook-bot', displayName: 'Hook Bot' })
		expect(part(wrapper, 'NcSelect').props('modelValue')).toBe('github')
		expect(part(wrapper, 'NcSelect').props('options').map((o) => o.value)).toEqual(['openconnector', 'stripe', 'github', 'teams'])
		expect(part(wrapper, 'NcTextField').props('modelValue')).toBe('X-Hub-Signature-256')
		expect(part(wrapper, 'NcPasswordField').props('placeholder')).toBe('Configured (leave blank to keep unchanged)')
		expect(wrapper.find('[data-testid="admin-webhook-handlers-empty-github"]').exists()).toBe(false)
	})

	it('falls back to the OpenConnector scheme and header, and no account', () => {
		const wrapper = mountRow(connection({ userId: '', account: { state: 'none' }, scheme: '', header: '', secretConfigured: false }))

		expect(state(wrapper).text()).toBe('No account set: deliveries are refused with 503')
		expect(state(wrapper).classes()).toContain('integriq-admin__action-error')
		expect(part(wrapper, 'NcSelectUsers').props('modelValue')).toBeNull()
		expect(part(wrapper, 'NcSelect').props('modelValue')).toBe('openconnector')
		expect(part(wrapper, 'NcTextField').props('modelValue')).toBe('X-OpenConnector-Signature')
		expect(part(wrapper, 'NcPasswordField').props('placeholder')).toBe('Not configured')
	})

	it('names an unusable account, uses the user id when no display name is known, and warns about empty handler groups', () => {
		const wrapper = mountRow(connection({ account: { state: 'disabled', displayName: 'Old Bot' }, handlerGroup: { id: 'integriq-github', empty: true } }))
		const nameless = mountRow(connection({ account: { state: 'ok' } }))

		expect(state(wrapper).text()).toBe('Account Old Bot is not usable: deliveries are refused with 503')
		expect(wrapper.find('[data-testid="admin-webhook-handlers-empty-github"]').text()).toBe('Nobody can read what this webhook stores yet. Add handlers to the group integriq-github under Accounts.')
		expect(part(nameless, 'NcSelectUsers').props('modelValue').displayName).toBe('hook-bot')
	})

	it('searches accounts from two characters', async () => {
		const wrapper = mountRow(connection())

		await part(wrapper, 'NcSelectUsers').vm.$emit('search', 'h')
		expect(get).not.toHaveBeenCalled()

		get.mockResolvedValueOnce({ data: { ocs: { data: [{ id: 'hook-2', label: 'Hook Two' }] } } })
		await part(wrapper, 'NcSelectUsers').vm.$emit('search', 'ho')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/ocs/v2.php/core/autocomplete/get', { params: { search: 'ho', itemType: '', itemId: '', shareTypes: [0], limit: 10 } })
		expect(part(wrapper, 'NcSelectUsers').props('options')).toEqual([{ id: 'hook-2', user: 'hook-2', displayName: 'Hook Two' }])
	})

	it('saves the edits, clears the secret, shows warnings and emits the saved connection', async () => {
		const saved = connection({ header: 'X-Sig' })
		put.mockResolvedValue({ data: { connection: saved, warnings: ['hook-bot cannot read the register'] } })
		const wrapper = mountRow(connection())

		await part(wrapper, 'NcSelect').vm.$emit('update:modelValue', 'teams')
		await part(wrapper, 'NcPasswordField').vm.$emit('update:modelValue', 'shh')
		await part(wrapper, 'NcTextField').vm.$emit('update:modelValue', 'X-Sig')
		await part(wrapper, 'NcSelectUsers').vm.$emit('update:modelValue', null)
		await wrapper.find('[data-testid="admin-webhook-save-github"]').trigger('click')
		await flushPromises()

		const [url, body] = put.mock.calls[0]
		expect(url.startsWith('/index.php/apps/integriq/api/admin/webhook-connections/')).toBe(true)
		expect(body).toEqual({ userId: '', scheme: 'teams', secret: 'shh', header: 'X-Sig' })
		expect(part(wrapper, 'NcPasswordField').props('modelValue')).toBe('')
		expect(toasts).toEqual([['success', 'GitHub connection saved.'], ['warning', 'hook-bot cannot read the register']])
		expect(wrapper.emitted('saved')).toEqual([[saved]])
	})

	it('shows a refusal, its field error, or a default failure', async () => {
		const wrapper = mountRow(connection())
		put.mockRejectedValueOnce({ response: { data: { errors: ['Secret too short.'], fieldErrors: { userId: 'Unknown account' } } } })

		await wrapper.find('[data-testid="admin-webhook-save-github"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Unknown account')
		expect(toasts).toEqual([['error', 'Secret too short.']])

		put.mockRejectedValueOnce(new Error('offline'))
		await wrapper.find('[data-testid="admin-webhook-save-github"]').trigger('click')
		await flushPromises()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to save the GitHub connection.'])
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
