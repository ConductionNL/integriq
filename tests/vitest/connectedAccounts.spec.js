// @vitest-environment jsdom

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The user's own connected accounts (sources-per-user-oauth task 3): only the
 * caller's personal, live OAuth 2.0 token sets are listed, with the identity
 * the provider returned; Disconnect calls the broker's OAuth 2.0 disconnect
 * and drops the row. The source form writes a per-user reference and nothing
 * beside it.
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ConnectedAccountsSection from '@/components/settings/ConnectedAccountsSection.vue'
import {
	isPerUser,
	perUserProviderOptions,
	readPerUserProvider,
	writePerUserRef,
} from '@/modals/v2/sourceCredentialRef.js'
import {
	accountIdentity,
	connectedAccounts,
	disconnectAccount,
	disconnectUrl,
	loadConnectedAccounts,
} from '@/services/connectedAccounts.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcAppSettingsSection: defineComponent({ name: 'NcAppSettingsSection', props: ['id', 'name'], render() { return h('section', this.$slots.default?.()) } }),
		NcButton: defineComponent({ name: 'NcButton', props: ['disabled', 'variant'], emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.()) } }),
	}
})

const ROWS = [
	{ id: 'c-2', kind: 'oauth2-token-set', provider: 'microsoft-entra', account: { id: 'x', handle: 'k.smit@example.org', displayName: '' }, '@self': { created: '2026-10-02T09:00:00+00:00' } },
	{ id: 'c-1', kind: 'oauth2-token-set', provider: 'google', account: { displayName: 'Karin Smit' }, '@self': { created: '2026-09-01T09:00:00+00:00' } },
	{ id: 'c-3', kind: 'oauth2-token-set', provider: 'github', status: 'disabled', account: {} },
	{ id: 'c-4', kind: 'secret', provider: 'gitlab' },
	{ id: 'c-5', kind: 'oauth2-token-set', provider: 'google', scope: 'organisation', account: {} },
	{ kind: 'oauth2-token-set', provider: 'google' },
]

/**
 * A client that answers the listing and records each delete.
 *
 * @param {object} [fail] `{get, delete}` to make one of them throw.
 * @return {{http: object, deleted: Array<string>}} The client.
 */
function client(fail = {}) {
	const deleted = []
	return {
		deleted,
		http: {
			get: async () => {
				if (fail.get) throw new Error('down')
				return { data: { results: ROWS } }
			},
			delete: async (url) => {
				if (fail.delete) throw new Error('refused')
				deleted.push(url)
				return { data: {} }
			},
		},
	}
}

describe('connected accounts', () => {
	it('lists only the caller\'s personal, live OAuth 2.0 accounts, oldest first', () => {
		expect(connectedAccounts(ROWS)).toEqual([
			{ id: 'c-1', provider: 'google', identity: 'Karin Smit', connectedOn: '2026-09-01T09:00:00+00:00' },
			{ id: 'c-2', provider: 'microsoft-entra', identity: 'k.smit@example.org', connectedOn: '2026-10-02T09:00:00+00:00' },
		])
		expect(connectedAccounts(null)).toEqual([])
	})

	it('names an account by display name, then handle, then id', () => {
		expect(accountIdentity({ displayName: ' Karin ', handle: 'k' })).toBe('Karin')
		expect(accountIdentity({ handle: 'k', id: '7' })).toBe('k')
		expect(accountIdentity({ id: '7' })).toBe('7')
		expect(accountIdentity(null)).toBe('')
	})

	it('loads through urlOf and disconnects at the broker\'s OAuth 2.0 endpoint', async () => {
		const { http, deleted } = client()
		const accounts = await loadConnectedAccounts(http, (url) => `/index.php${url}`)
		expect(accounts.map((a) => a.id)).toEqual(['c-1', 'c-2'])

		await disconnectAccount(http, 'c 2', (url) => `/index.php${url}`)
		expect(deleted).toEqual(['/index.php/apps/openregister/api/credentials/oauth2/c%202'])
		expect(disconnectUrl('a/b')).toBe('/apps/openregister/api/credentials/oauth2/a%2Fb')
		await expect(disconnectAccount(http, '')).rejects.toThrow('needs its id')
	})

	it('the section lists the accounts and drops one once disconnected', async () => {
		const { http, deleted } = client()
		const wrapper = mount(ConnectedAccountsSection, { props: { http, urlOf: (url) => url } })
		await flushPromises()

		expect(wrapper.findAll('li')).toHaveLength(2)
		expect(wrapper.text()).toContain('Karin Smit')
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(deleted).toEqual(['/apps/openregister/api/credentials/oauth2/c-2'])
		expect(wrapper.findAll('li')).toHaveLength(1)
		expect(wrapper.text()).not.toContain('k.smit@example.org')
	})

	it('the section says so when loading or disconnecting fails, and keeps the row', async () => {
		const broken = mount(ConnectedAccountsSection, { props: { http: client({ get: true }).http, urlOf: (url) => url } })
		await flushPromises()
		expect(broken.find('[role="alert"]').text()).toContain('could not be loaded')

		const refusing = mount(ConnectedAccountsSection, { props: { http: client({ delete: true }).http, urlOf: (url) => url } })
		await flushPromises()
		await refusing.findAll('button')[0].trigger('click')
		await flushPromises()
		expect(refusing.find('[role="alert"]').text()).toContain('could not be disconnected')
	})
})

describe('the per-user source reference', () => {
	it('writes exactly { provider, perUser: true } and keeps the rest of the configuration', () => {
		const next = writePerUserRef({ baseUrl: 'https://graph.microsoft.com', authentication: { credentialRef: { credentialId: 'c-9' }, apikey: 'x' } }, 'microsoft-entra')
		expect(next).toEqual({ baseUrl: 'https://graph.microsoft.com', authentication: { credentialRef: { provider: 'microsoft-entra', perUser: true } } })
		const source = { configuration: next }
		expect(isPerUser(source)).toBe(true)
		expect(readPerUserProvider(source)).toBe('microsoft-entra')
		expect(isPerUser({ configuration: { authentication: { credentialRef: { credentialId: 'c-9' } } } })).toBe(false)
		expect(readPerUserProvider({})).toBeNull()
	})

	it('offers only the catalogue\'s OAuth 2.0 providers', () => {
		expect(perUserProviderOptions([
			{ identifier: 'microsoft-entra', title: 'Microsoft Entra ID', kind: 'oauth2-token-set' },
			{ identifier: 'gitlab', title: 'GitLab', kind: 'secret' },
			{ identifier: 'google', kind: 'oauth2-token-set' },
			{ identifier: '', kind: 'oauth2-token-set' },
		])).toEqual([{ id: 'microsoft-entra', label: 'Microsoft Entra ID' }, { id: 'google', label: 'google' }])
		expect(perUserProviderOptions(undefined)).toEqual([])
	})
})
