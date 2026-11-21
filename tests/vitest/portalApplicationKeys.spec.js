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
import PortalApplicationKeys from '@/views/DeveloperPortal/PortalApplicationKeys.vue'

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
	const wrapper = mount(PortalApplicationKeys, { props: { applicationId: 'app-1' } })
	await flushPromises()
	return wrapper
}

describe('PortalApplicationKeys', () => {
	beforeEach(() => {
		for (const spy of [get, post, del, showError, showSuccess]) spy.mockReset()
		get.mockResolvedValue(LISTED)
	})

	it('lists the API keys of the application by label and prefix, not the pinned certificate', async () => {
		const wrapper = await mountPanel()

		expect(get).toHaveBeenCalledWith(KEYS_URL)
		const items = wrapper.findAll('[data-testid="developer-portal-key"]')
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
		await wrapper.find('[data-testid="developer-portal-generate-key"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(KEYS_URL, { label: 'Production' })
		expect(wrapper.find('[data-testid="developer-portal-new-key"]').text()).toContain('K1abcdefghK1abcdefgh')
		const items = wrapper.findAll('[data-testid="developer-portal-key"]')
		expect(items).toHaveLength(2)
		expect(items[1].text()).toContain('never used')
		expect(items[1].text()).not.toContain('K1abcdefghK1abcdefgh')
	})

	it('does not generate a key without a label', async () => {
		const wrapper = await mountPanel()
		expect(wrapper.find('[data-testid="developer-portal-generate-key"]').attributes('disabled')).toBeDefined()
		await wrapper.vm.generate()
		expect(post).not.toHaveBeenCalled()
	})

	it('revokes a key through its own route and drops it from the list', async () => {
		del.mockResolvedValue({ data: { revoked: 'key-old' } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="developer-portal-revoke-key"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(KEYS_URL + '/key-old')
		expect(wrapper.findAll('[data-testid="developer-portal-key"]')).toHaveLength(0)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('reports a refusal as the server words it, and keeps the list', async () => {
		del.mockRejectedValue({ response: { data: { error: 'No such application' } } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="developer-portal-revoke-key"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('No such application')
		expect(wrapper.findAll('[data-testid="developer-portal-key"]')).toHaveLength(1)
	})

	it('says so when the keys cannot be read', async () => {
		get.mockRejectedValue(new Error('down'))
		const wrapper = await mountPanel()
		expect(wrapper.find('[role="alert"]').text()).toBe('The keys of this application could not be loaded.')
	})
})
