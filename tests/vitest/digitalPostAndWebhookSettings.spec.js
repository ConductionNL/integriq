// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two admin sections: the account digital post is stored as (its state line,
 * the account search, saving with the server's warnings and field errors),
 * and the list of webhook connections (loading, a failed load, and a saved
 * row replacing its own entry only).
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
		props: ['modelValue', 'inputLabel', 'options', 'disabled', 'variant', 'loading'],
		emits: ['update:modelValue', 'search'],
		setup(props, { attrs, slots }) {
			return () => h(tag, { class: name, 'data-testid': attrs['data-testid'], 'data-disabled': props.disabled, onClick: attrs.onClick }, slots.default?.())
		},
	})
	return { NcButton: stub('NcButton', 'button'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcNoteCard: stub('NcNoteCard'), NcPasswordField: stub('NcPasswordField'), NcSelect: stub('NcSelect'), NcSelectUsers: stub('NcSelectUsers'), NcTextField: stub('NcTextField') }
})

const { default: DigitalPostAccountSettings } = await import('@/views/admin/DigitalPostAccountSettings.vue')
const { default: WebhookConnectionsSettings } = await import('@/views/admin/WebhookConnectionsSettings.vue')

const ACCOUNT_URL = '/index.php/apps/integriq/api/admin/digital-post-account'

/**
 * Mount a section and let it load.
 *
 * @param {object} component The section.
 * @param {object} [options] Extra mount options.
 * @return {Promise<object>} The wrapper.
 */
async function mountSection(component, options = {}) {
	const wrapper = mount(component, { global: { mocks: { t: translate }, ...(options.global || {}) } })
	await flushPromises()
	return wrapper
}

const stateLine = (wrapper) => wrapper.find('[data-testid="admin-digital-post-account-state"]')
const users = (wrapper) => wrapper.findComponent({ name: 'NcSelectUsers' })

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	toasts.length = 0
})

describe('DigitalPostAccountSettings', () => {
	it('names a usable account and preselects it', async () => {
		get.mockResolvedValue({ data: { account: { state: 'ok', userId: 'post-bot', displayName: 'Post Bot' } } })

		const wrapper = await mountSection(DigitalPostAccountSettings)

		expect(get).toHaveBeenCalledWith(ACCOUNT_URL)
		expect(stateLine(wrapper).text()).toBe('Digital post is stored as Post Bot')
		expect(stateLine(wrapper).classes()).not.toContain('integriq-admin__action-error')
		expect(users(wrapper).props('modelValue')).toEqual({ id: 'post-bot', user: 'post-bot', displayName: 'Post Bot' })
	})

	it('says digital post is refused without an account or with an unusable one', async () => {
		get.mockResolvedValueOnce({ data: { account: { state: 'unset' } } })
		const none = await mountSection(DigitalPostAccountSettings)
		expect(stateLine(none).text()).toBe('No account set: digital post is refused')
		expect(stateLine(none).classes()).toContain('integriq-admin__action-error')
		expect(users(none).props('modelValue')).toBeNull()

		get.mockResolvedValueOnce({ data: { account: { state: 'disabled', userId: 'old-bot' } } })
		const disabled = await mountSection(DigitalPostAccountSettings)
		expect(stateLine(disabled).text()).toBe('Account old-bot is not usable: digital post is refused')
		expect(users(disabled).props('modelValue')).toEqual({ id: 'old-bot', user: 'old-bot', displayName: 'old-bot' })
	})

	it('says when the account cannot be loaded', async () => {
		get.mockRejectedValue(new Error('500'))

		const wrapper = await mountSection(DigitalPostAccountSettings)

		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load the digital post account.')
		expect(stateLine(wrapper).text()).toBe('No account set: digital post is refused')
	})

	it('searches accounts from two characters and offers what the server finds', async () => {
		get.mockResolvedValueOnce({ data: { account: null } })
		const wrapper = await mountSection(DigitalPostAccountSettings)

		await users(wrapper).vm.$emit('search', 'p')
		expect(get).toHaveBeenCalledTimes(1)

		get.mockResolvedValueOnce({ data: { ocs: { data: [{ id: 'post-bot', label: 'Post Bot' }, { id: 'pieter' }] } } })
		await users(wrapper).vm.$emit('search', 'po')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith('/ocs/v2.php/core/autocomplete/get', { params: { search: 'po', itemType: '', itemId: '', shareTypes: [0], limit: 10 } })
		expect(users(wrapper).props('options')).toEqual([
			{ id: 'post-bot', user: 'post-bot', displayName: 'Post Bot' },
			{ id: 'pieter', user: 'pieter', displayName: 'pieter' },
		])
		expect(users(wrapper).props('loading')).toBe(false)

		get.mockRejectedValueOnce(new Error('offline'))
		await users(wrapper).vm.$emit('search', 'pos')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Searching accounts failed.')
	})

	it('saves the chosen account and shows the server warnings', async () => {
		get.mockResolvedValueOnce({ data: { account: null } })
		put.mockResolvedValue({ data: { account: { state: 'ok', userId: 'post-bot', displayName: 'Post Bot' }, warnings: ['post-bot has no e-mail address'] } })
		const wrapper = await mountSection(DigitalPostAccountSettings)

		await users(wrapper).vm.$emit('update:modelValue', { id: 'post-bot' })
		await wrapper.find('[data-testid="admin-digital-post-account-save"]').trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(ACCOUNT_URL, { userId: 'post-bot' })
		expect(stateLine(wrapper).text()).toBe('Digital post is stored as Post Bot')
		expect(toasts).toEqual([['success', 'Digital post account saved.'], ['warning', 'post-bot has no e-mail address']])
	})

	it('clears the account when nothing is chosen, and shows a refusal and its field error', async () => {
		get.mockResolvedValueOnce({ data: { account: null } })
		put.mockRejectedValueOnce({ response: { data: { errors: ['The account is disabled.', 'Pick another.'], fieldErrors: { userId: 'Disabled account' } } } })
		const wrapper = await mountSection(DigitalPostAccountSettings)

		await wrapper.find('[data-testid="admin-digital-post-account-save"]').trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(ACCOUNT_URL, { userId: '' })
		expect(wrapper.find('[role="alert"]').text()).toBe('Disabled account')
		expect(toasts).toEqual([['error', 'The account is disabled. Pick another.']])

		put.mockRejectedValueOnce(new Error('offline'))
		await wrapper.find('[data-testid="admin-digital-post-account-save"]').trigger('click')
		await flushPromises()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to save the digital post account.'])
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})
})

describe('WebhookConnectionsSettings', () => {
	const RowStub = { name: 'WebhookConnectionRow', props: ['connection'], emits: ['saved'], template: '<div class="row-stub">{{ connection.authorizationType }}:{{ connection.userId }}</div>' }

	it('lists a row per connection and replaces only the saved one', async () => {
		get.mockResolvedValue({ data: { connections: [{ authorizationType: 'github', userId: 'a' }, { authorizationType: 'stripe', userId: 'b' }] } })
		const wrapper = await mountSection(WebhookConnectionsSettings, { global: { stubs: { WebhookConnectionRow: RowStub } } })

		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/admin/webhook-connections')
		expect(wrapper.findAll('.row-stub').map((r) => r.text())).toEqual(['github:a', 'stripe:b'])

		await wrapper.findAllComponents({ name: 'WebhookConnectionRow' })[1].vm.$emit('saved', { authorizationType: 'stripe', userId: 'c' })
		expect(wrapper.findAll('.row-stub').map((r) => r.text())).toEqual(['github:a', 'stripe:c'])
	})

	it('says when the connections cannot be loaded, and lists none without any', async () => {
		get.mockRejectedValueOnce(new Error('500'))
		const failed = await mountSection(WebhookConnectionsSettings, { global: { stubs: { WebhookConnectionRow: RowStub } } })
		expect(failed.find('[role="alert"]').text()).toBe('Failed to load the webhook connections.')
		expect(failed.findAll('.row-stub')).toHaveLength(0)

		get.mockResolvedValueOnce({ data: {} })
		const empty = await mountSection(WebhookConnectionsSettings, { global: { stubs: { WebhookConnectionRow: RowStub } } })
		expect(empty.find('[role="alert"]').exists()).toBe(false)
		expect(empty.findAll('.row-stub')).toHaveLength(0)
		expect(empty.text()).not.toContain('Loading the webhook connections')
	})
})
