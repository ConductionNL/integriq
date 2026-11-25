// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The keys panel of a developer's own application: it lists the API keys by
 * label and first characters, shows a generated key once, and revokes a key
 * through the portal key routes. A pinned client certificate is the
 * administrator's and is not listed.
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConsumerCredentialsPanel from '@/components/ConsumerCredentialsPanel.vue'
import CredentialKeysPanel from '@/components/CredentialKeysPanel.vue'
import { consumerCredentialsUrl, portalKeysUrl } from '@/components/credentialKeys.js'

const { get, post, del, showError, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	del: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => encodeURIComponent(vars[key])),
}))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue', 'keyup'],
			render() {
				return h('input', { 'data-label': this.label })
			},
		}),
		NcTextArea: defineComponent({
			name: 'NcTextArea',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('textarea', { 'data-label': this.label })
			},
		}),
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
	}
})

const KEYS_URL = '/index.php/apps/integriq/api/portal/applications/app-1/keys'

const LISTED = {
	data: {
		results: [
			{ id: 'key-old', label: 'old', type: 'apiKey', keyPrefix: 'oldkey', lastUsedAt: '2026-11-20T10:00:00+00:00', expired: false },
			{ id: 'cert-1', label: 'pinned', type: 'clientCertificate', fingerprint: 'ab', expired: false },
		],
	},
}

/**
 * Mount the panel for application app-1 and wait for its list.
 *
 * @return {Promise<object>} the wrapper
 */
async function mountPanel() {
	const wrapper = mount(CredentialKeysPanel, { props: { credentialsUrl: portalKeysUrl('app-1') } })
	await flushPromises()
	return wrapper
}

describe('CredentialKeysPanel on the developer portal', () => {
	beforeEach(() => {
		for (const spy of [get, post, del, showError, showSuccess]) spy.mockReset()
		get.mockResolvedValue(LISTED)
	})

	it('lists the API keys of the application by label and prefix, not the pinned certificate', async () => {
		const wrapper = await mountPanel()

		expect(get).toHaveBeenCalledWith(KEYS_URL)
		const items = wrapper.findAll('[data-testid="credential-key"]')
		expect(items).toHaveLength(1)
		expect(items[0].text()).toContain('old')
		expect(items[0].text()).toContain('oldkey…')
		expect(items[0].text()).toContain('last used 2026-11-20T10:00:00+00:00')
		expect(wrapper.text()).not.toContain('pinned')
	})

	it('shows a generated key once and lists it by its prefix', async () => {
		post.mockResolvedValue({
			data: {
				key: 'K1abcdefghK1abcdefgh',
				credential: { id: 'key-new', label: 'Production', type: 'apiKey', keyPrefix: 'K1abcd', lastUsedAt: null },
			},
		})
		const wrapper = await mountPanel()

		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', ' Production ')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="credential-generate-key"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(KEYS_URL, { label: 'Production' })
		expect(wrapper.find('[data-testid="credential-new-key"]').text()).toContain('K1abcdefghK1abcdefgh')
		const items = wrapper.findAll('[data-testid="credential-key"]')
		expect(items).toHaveLength(2)
		expect(items[1].text()).toContain('never used')
		expect(items[1].text()).not.toContain('K1abcdefghK1abcdefgh')
	})

	it('does not generate a key without a label', async () => {
		const wrapper = await mountPanel()
		expect(wrapper.find('[data-testid="credential-generate-key"]').attributes('disabled')).toBeDefined()
		await wrapper.vm.generate()
		expect(post).not.toHaveBeenCalled()
	})

	it('revokes a key through its own route and drops it from the list', async () => {
		del.mockResolvedValue({ data: { revoked: 'key-old' } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="credential-revoke"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(KEYS_URL + '/key-old')
		expect(wrapper.findAll('[data-testid="credential-key"]')).toHaveLength(0)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('reports a refusal as the server words it, and keeps the list', async () => {
		del.mockRejectedValue({ response: { data: { error: 'No such application' } } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="credential-revoke"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('No such application')
		expect(wrapper.findAll('[data-testid="credential-key"]')).toHaveLength(1)
	})

	it('says so when the keys cannot be read', async () => {
		get.mockRejectedValue(new Error('down'))
		const wrapper = await mountPanel()
		expect(wrapper.find('[role="alert"]').text()).toBe('The keys could not be loaded.')
	})
})

/**
 * The text field with a label.
 *
 * @param {object} wrapper the wrapper
 * @param {string} name NcTextField or NcTextArea
 * @param {string} label the label
 * @return {object} the field
 */
function field(wrapper, name, label) {
	return wrapper.findAllComponents({ name }).find((c) => c.props('label') === label)
}

const CONSUMER_URL = '/index.php/apps/integriq/api/consumers/c-1/credentials'

describe('CredentialKeysPanel for an administrator', () => {
	beforeEach(() => {
		for (const spy of [get, post, del, showError, showSuccess]) spy.mockReset()
		get.mockResolvedValue(LISTED)
	})

	/**
	 * Mount the administrator's panel for consumer c-1.
	 *
	 * @return {Promise<object>} the wrapper
	 */
	async function mountAdmin() {
		const wrapper = mount(CredentialKeysPanel, { props: { credentialsUrl: consumerCredentialsUrl('c-1'), administrator: true } })
		await flushPromises()
		return wrapper
	}

	it('lists the pinned certificate beside the keys, and offers no certificate on the portal', async () => {
		const wrapper = await mountAdmin()
		expect(get).toHaveBeenCalledWith(CONSUMER_URL)
		expect(wrapper.findAll('[data-testid="credential-key"]')).toHaveLength(1)
		const certificates = wrapper.findAll('[data-testid="credential-certificate"]')
		expect(certificates).toHaveLength(1)
		expect(certificates[0].text()).toContain('pinned')
		expect(certificates[0].text()).toContain('ab')

		const portal = await mountPanel()
		expect(portal.find('[data-testid="credential-certificate-pem"]').exists()).toBe(false)
		expect(field(portal, 'NcTextField', 'Valid until (optional)')).toBeUndefined()
	})

	it('generates a key with an expiry, shown once with a copy button', async () => {
		post.mockResolvedValue({ data: { key: 'K2secretK2secret', credential: { id: 'key-2', label: 'Partner 2027', type: 'apiKey', keyPrefix: 'K2secr', expiresAt: '2027-01-01T00:00:00+00:00' } } })
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(globalThis.navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await mountAdmin()

		field(wrapper, 'NcTextField', 'Key label').vm.$emit('update:modelValue', 'Partner 2027')
		field(wrapper, 'NcTextField', 'Valid until (optional)').vm.$emit('update:modelValue', ' 2027-01-01T00:00:00+00:00 ')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="credential-generate-key"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(CONSUMER_URL, { label: 'Partner 2027', expiresAt: '2027-01-01T00:00:00+00:00' })
		expect(wrapper.find('[data-testid="credential-new-key"]').text()).toContain('K2secretK2secret')
		await wrapper.find('[data-testid="credential-copy-key"]').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('K2secretK2secret')
		expect(wrapper.find('[data-testid="credential-copy-key"]').text()).toBe('Copied')
	})

	it('pins a certificate, or an issuing CA with a subject pattern', async () => {
		post.mockResolvedValue({ data: { credential: { id: 'cert-2', label: 'Zaaksysteem', type: 'clientCertificate', fingerprint: 'cd' } } })
		const wrapper = await mountAdmin()

		field(wrapper, 'NcTextField', 'Key label').vm.$emit('update:modelValue', 'Zaaksysteem')
		field(wrapper, 'NcTextArea', 'Certificate (PEM)').vm.$emit('update:modelValue', '-----BEGIN CERTIFICATE-----x')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="credential-pin-certificate"]').trigger('click')
		await flushPromises()
		expect(post).toHaveBeenLastCalledWith(CONSUMER_URL, { label: 'Zaaksysteem', type: 'clientCertificate', certificate: '-----BEGIN CERTIFICATE-----x' })
		expect(wrapper.findAll('[data-testid="credential-certificate"]')).toHaveLength(2)
		expect(wrapper.find('[data-testid="credential-new-key"]').exists()).toBe(false)

		field(wrapper, 'NcTextField', 'Key label').vm.$emit('update:modelValue', 'Gemeente')
		field(wrapper, 'NcTextArea', 'Certificate (PEM)').vm.$emit('update:modelValue', '-----BEGIN CERTIFICATE-----ca')
		field(wrapper, 'NcTextField', 'Subject pattern (with an issuing CA)').vm.$emit('update:modelValue', '*/serialNumber=1')
		await wrapper.vm.$nextTick()
		await wrapper.vm.pin()
		expect(post).toHaveBeenLastCalledWith(CONSUMER_URL, { label: 'Gemeente', type: 'clientCertificate', issuerCertificate: '-----BEGIN CERTIFICATE-----ca', subjectPattern: '*/serialNumber=1' })
	})

	it('reports a refused pin as the server words it', async () => {
		post.mockRejectedValue({ response: { data: { error: 'Give a PEM certificate, or an issuing CA certificate and a subject pattern.' } } })
		const wrapper = await mountAdmin()
		field(wrapper, 'NcTextField', 'Key label').vm.$emit('update:modelValue', 'x')
		field(wrapper, 'NcTextArea', 'Certificate (PEM)').vm.$emit('update:modelValue', 'garbage')
		await wrapper.vm.$nextTick()
		await wrapper.vm.pin()
		expect(showError).toHaveBeenCalledWith('Give a PEM certificate, or an issuing CA certificate and a subject pattern.')
		expect(wrapper.findAll('[data-testid="credential-certificate"]')).toHaveLength(1)
	})
})

describe('ConsumerCredentialsPanel', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue({ data: { results: [] } })
	})

	it('reads the credentials of the consumer its detail page shows, as an administrator', async () => {
		const wrapper = mount(ConsumerCredentialsPanel, {
			global: { provide: { cnSectionContext: { value: { objectId: 'c-1', object: { name: 'Partner' } } } } },
		})
		await flushPromises()

		const panel = wrapper.findComponent(CredentialKeysPanel)
		expect(panel.props('credentialsUrl')).toBe(CONSUMER_URL)
		expect(panel.props('administrator')).toBe(true)
		expect(get).toHaveBeenCalledWith(CONSUMER_URL)
	})

	it('keeps the panel test id when the detail page passes its own data-testid down', async () => {
		// CnBodySections mounts a body widget with `data-testid="cn-body-section-component-<id>"`;
		// that attribute lands on the wrapper and must not overwrite `credential-keys`.
		const wrapper = mount(ConsumerCredentialsPanel, {
			attrs: { 'data-testid': 'cn-body-section-component-con-credentials' },
			global: { provide: { cnSectionContext: { value: { objectId: 'c-1', object: {} } } } },
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="credential-keys"]').exists()).toBe(true)
	})

	it('renders nothing before the consumer is known', () => {
		const wrapper = mount(ConsumerCredentialsPanel, { global: { provide: { cnSectionContext: { value: { objectId: null, object: null } } } } })
		expect(wrapper.findComponent(CredentialKeysPanel).exists()).toBe(false)
	})
})
