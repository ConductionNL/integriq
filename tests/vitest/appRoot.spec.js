// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App.vue hands its props to CnAppRoot, adds `admin` to the permissions for an
 * admin, and translates in the integriq domain.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnAppRoot: {
		name: 'CnAppRoot',
		props: ['aiCompanion', 'manifest', 'customComponents', 'registry', 'formatters', 'pageTypes', 'appId', 'translate', 'permissions'],
		template: '<div class="root" />',
	},
}))
vi.mock('@/modals/v2/ModalHost.vue', () => ({ default: { name: 'ModalHost', template: '<div class="host" />' } }))
vi.mock('@/components/settings/ConnectedAccountsSection.vue', () => ({ default: { name: 'ConnectedAccountsSection', template: '<div class="accounts" />' } }))

const App = (await import('@/App.vue')).default

afterEach(() => {
	delete window.OC
})

/**
 * The CnAppRoot props for an App mounted with the given props.
 *
 * @param {object} props The App props.
 * @return {object} The root component wrapper.
 */
function root(props = {}) {
	return mount(App, { props: { manifest: { pages: [] }, ...props } }).findComponent({ name: 'CnAppRoot' })
}

describe('App', () => {
	it('passes its props through and mounts the modal host beside the root', () => {
		const formatters = { f: () => '' }
		const wrapper = mount(App, { props: { manifest: { pages: [1] }, formatters } })
		const cn = wrapper.findComponent({ name: 'CnAppRoot' })

		expect(cn.props('manifest')).toEqual({ pages: [1] })
		expect(cn.props('formatters')).toEqual(formatters)
		expect(cn.props('customComponents')).toEqual({})
		expect(cn.props('registry')).toEqual({})
		expect(cn.props('pageTypes')).toEqual({})
		expect(cn.props('appId')).toBe('integriq')
		expect(cn.props('aiCompanion')).toBe(true)
		expect(wrapper.findComponent({ name: 'ModalHost' }).exists()).toBe(true)
	})

	it('has no permissions without a user, and adds admin only for an admin', () => {
		expect(root().props('permissions')).toEqual([])

		window.OC = { currentUser: { permissions: ['read'] }, isUserAdmin: () => false }
		expect(root().props('permissions')).toEqual(['read'])

		window.OC = { currentUser: { permissions: ['read'] }, isUserAdmin: () => true }
		expect(root().props('permissions')).toEqual(['read', 'admin'])

		window.OC = { isUserAdmin: () => true }
		expect(root().props('permissions')).toEqual(['admin'])
	})

	it('translates a manifest key in the integriq domain', () => {
		expect(root().props('translate')('Sources')).toBe('Sources')
	})
})
