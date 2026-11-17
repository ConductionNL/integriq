// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the admin settings panels render once loaded: the action matrix as a
 * table with a fixed admin column, the digital post account state, the
 * webhook connection list with each row's account state, and the
 * Berichtenbox connection. The library components are stood in by named
 * stubs, so the panels' own templates render and are asserted on.
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
const put = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url) => `/ocs/v2.php/${url}`,
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (m, key) => params[key]),
}))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve. Each stand-in keeps the real name, the props the
// panels bind, and renders its default slot.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) => defineComponent({
		name,
		props,
		inheritAttrs: false,
		render() { return h('div', { class: name }, this.$slots.default?.()) },
	})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'disabled', 'type']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcPasswordField: stub('NcPasswordField', ['label', 'modelValue']),
		NcSelect: stub('NcSelect', ['inputLabel', 'modelValue', 'options']),
		NcSelectUsers: stub('NcSelectUsers', ['inputLabel', 'modelValue', 'options', 'loading']),
		NcTextField: stub('NcTextField', ['label', 'modelValue', 'helperText', 'error']),
	}
})

const ActionAuthMatrix = (await import('@/views/admin/ActionAuthMatrix.vue')).default
const DigitalPostAccountSettings = (await import('@/views/admin/DigitalPostAccountSettings.vue')).default
const WebhookConnectionsSettings = (await import('@/views/admin/WebhookConnectionsSettings.vue')).default
const BerichtenboxSettings = (await import('@/views/admin/BerichtenboxSettings.vue')).default

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m))

/**
 * Mount a panel with the translation stand-in as a global method.
 *
 * @param {object} component the panel
 * @return {object} the wrapper
 */
function mountPanel(component) {
	return mount(component, { global: { mocks: { t } } })
}

beforeEach(() => {
	get.mockReset()
	put.mockReset()
})

describe('ActionAuthMatrix rendering', () => {
	it('draws one row per action and a fixed, ticked admin column', async () => {
		get.mockResolvedValue({ data: { actions: ['rule.edit', 'source.delete'], groups: ['editors'], matrix: { 'rule.edit': ['editors'] } } })
		const wrapper = mountPanel(ActionAuthMatrix)
		expect(wrapper.text()).toContain('Loading action matrix…')
		await flushPromises()
		expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Action', 'admin', 'editors'])
		expect(wrapper.findAll('tbody th .integriq-admin__matrix-name').map((name) => name.text())).toEqual(['rule.edit', 'source.delete'])
		expect(wrapper.findAll('tbody th .integriq-admin__matrix-label').map((label) => label.text())).toEqual(['rule.edit', 'source.delete'])
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(switches.map((s) => [s.props('modelValue'), s.props('disabled')])).toEqual([
			[true, true], [true, false],
			[true, true], [false, false],
		])
		expect(switches[1].vm.$attrs['aria-label']).toBe('Allow group editors to perform rule.edit')

		switches[3].vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.matrix['source.delete']).toEqual(['editors'])
		expect(wrapper.findComponent({ name: 'NcButton' }).text()).toBe('Save action matrix')
	})

	it('draws one section per area with each action\'s label above its name', async () => {
		get.mockResolvedValue({
			data: {
				actions: ['stuf-zkn.push', 'sms.send'],
				groups: ['integratie-beheer'],
				matrix: {},
				labels: { 'stuf-zkn.push': 'Push through the StUF-ZKN bridge', 'sms.send': 'Send a text message' },
				areas: { 'stuf-zkn.push': 'Adapters', 'sms.send': 'Notifications' },
			},
		})
		const wrapper = mountPanel(ActionAuthMatrix)
		await flushPromises()
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Adapters', 'Notifications'])
		const adapters = wrapper.find('[data-testid="admin-action-area-Adapters"]')
		expect(adapters.find('.integriq-admin__matrix-label').text()).toBe('Push through the StUF-ZKN bridge')
		expect(adapters.find('.integriq-admin__matrix-name').text()).toBe('stuf-zkn.push')
	})

	it('shows a load failure as an alert', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValue(new Error('500'))
		const wrapper = mountPanel(ActionAuthMatrix)
		await flushPromises()
		error.mockRestore()
		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load the action matrix.')
	})
})

describe('DigitalPostAccountSettings rendering', () => {
	it('shows the account the post is stored as', async () => {
		get.mockResolvedValue({ data: { account: { state: 'ok', userId: 'post-bot', displayName: 'Post bot' } } })
		const wrapper = mountPanel(DigitalPostAccountSettings)
		await flushPromises()
		const state = wrapper.find('[data-testid="admin-digital-post-account-state"]')
		expect(state.text()).toBe('Digital post is stored as Post bot')
		expect(state.classes()).not.toContain('integriq-admin__action-error')
		expect(wrapper.findComponent({ name: 'NcSelectUsers' }).props('modelValue')).toEqual({ id: 'post-bot', user: 'post-bot', displayName: 'Post bot' })
	})

	it('marks a refused state and shows the load error', async () => {
		get.mockRejectedValue(new Error('500'))
		const wrapper = mountPanel(DigitalPostAccountSettings)
		await flushPromises()
		const state = wrapper.find('[data-testid="admin-digital-post-account-state"]')
		expect(state.text()).toBe('No account set: digital post is refused')
		expect(state.classes()).toContain('integriq-admin__action-error')
		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load the digital post account.')
	})
})

describe('WebhookConnectionsSettings rendering', () => {
	it('draws a row per connection with its account state', async () => {
		get.mockResolvedValue({ data: { connections: [
			{ authorizationType: 'a', label: 'Partner A', scheme: 'openconnector', header: '', userId: 'svc', account: { state: 'ok', displayName: 'Service' } },
			{ authorizationType: 'b', label: 'Partner B', scheme: 'openconnector', header: '', userId: '', account: { state: 'none', displayName: '' } },
			{ authorizationType: 'c', label: 'Partner C', scheme: 'openconnector', header: '', userId: 'old', account: { state: 'disabled', displayName: 'Old' } },
		] } })
		const wrapper = mountPanel(WebhookConnectionsSettings)
		expect(wrapper.text()).toContain('Loading the webhook connections…')
		await flushPromises()
		const rows = wrapper.findAllComponents({ name: 'WebhookConnectionRow' })
		expect(rows).toHaveLength(3)
		expect(rows.map((row) => row.vm.accountState)).toEqual([
			{ refused: false, text: 'Deliveries are stored as Service' },
			{ refused: true, text: 'No account set: deliveries are refused with 503' },
			{ refused: true, text: 'Account Old is not usable: deliveries are refused with 503' },
		])
	})

	it('shows a load failure as an alert and no rows', async () => {
		get.mockRejectedValue(new Error('500'))
		const wrapper = mountPanel(WebhookConnectionsSettings)
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load the webhook connections.')
		expect(wrapper.findAllComponents({ name: 'WebhookConnectionRow' })).toHaveLength(0)
	})
})

describe('BerichtenboxSettings rendering', () => {
	it('draws a labelled field per connection setting, filled from the stored source', async () => {
		get.mockResolvedValue({ data: { live: true, source: { senderOin: '00000001234567890000', cpaId: 'cpa-1', certificate: { configured: false } } } })
		const wrapper = mountPanel(BerichtenboxSettings)
		await flushPromises()
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		const byLabel = Object.fromEntries(fields.map((field) => [field.props('label'), field.props('modelValue')]))
		expect(byLabel['Sender OIN']).toBe('00000001234567890000')
		expect(byLabel['CPA id']).toBe('cpa-1')
		expect(byLabel['Subscription check endpoint']).toBe('')
		expect(wrapper.text()).toContain('No certificate stored: every letter is refused.')
	})
})
