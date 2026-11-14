// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The small top-level modules in src/: the connection formatters, the
 * "Synced from" integration registration, the public path, the pinia
 * instance and the admin settings entry.
 *
 * main.js is covered separately in mainEntry.spec.js, which stands in for
 * the webpack-only require.context it reads the manifest fragments through.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { registered, apps } = vi.hoisted(() => ({ registered: [], apps: [] }))

vi.mock('@conduction/nextcloud-vue', () => ({
	registerIntegration: (definition) => registered.push(definition),
}))
vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		createApp: (component, props) => {
			const app = {
				component,
				props,
				config: { globalProperties: {} },
				mount: vi.fn(),
				unmount: vi.fn(),
			}
			apps.push(app)
			return app
		},
	}
})
vi.mock('@/integration/SyncedFromTab.vue', () => ({ default: { name: 'SyncedFromTab' } }))
vi.mock('@/views/admin/AdminSettings.vue', () => ({ default: { name: 'AdminSettings' } }))

beforeEach(() => {
	apps.length = 0
})

describe('formatters', () => {
	it('labels each known connection status and passes others through', async () => {
		const { connectionStatus, connectionSettingsLabel, default: formatters } = await import('@/formatters.js')
		expect([
			'configured', 'limited', 'unconfigured', 'simulated', 'disabled', 'unavailable', 'error',
		].map(connectionStatus)).toEqual([
			'Configured', 'Limited', 'Not configured', 'Simulated', 'Switched off', 'Not available', 'Error',
		])
		expect(connectionStatus('custom')).toBe('custom')
		expect(connectionStatus(undefined)).toBe('')
		expect(connectionStatus(null)).toBe('')
		expect(connectionSettingsLabel('/settings')).toBe('Open settings')
		expect(connectionSettingsLabel('')).toBe('')
		expect(formatters).toEqual({ connectionStatus, connectionSettingsLabel })
	})
})

describe('integration', () => {
	it('registers the "Synced from" tab once', async () => {
		await import('@/integration.js')
		expect(registered).toHaveLength(1)
		expect(registered[0]).toMatchObject({
			id: 'sync-contract',
			label: 'Synced from',
			icon: 'SyncOutline',
			group: 'workflow',
			order: 50,
			requiredApp: 'integriq',
			renderMode: 'mount',
			chrome: 'card',
		})
	})

	it('mounts one app per element with a copy of the props, and unmounts it', () => {
		const { mount, unmount } = registered[0]
		const el = document.createElement('div')
		const props = { objectId: 'o1' }

		mount(el, props)
		mount(el, { objectId: 'other' })
		expect(apps).toHaveLength(1)
		expect(apps[0].component).toEqual({ name: 'SyncedFromTab' })
		expect(apps[0].props).toEqual({ objectId: 'o1' })
		expect(apps[0].props).not.toBe(props)
		expect(apps[0].mount).toHaveBeenCalledWith(el)

		unmount(el)
		expect(apps[0].unmount).toHaveBeenCalledTimes(1)
		unmount(el)
		expect(apps[0].unmount).toHaveBeenCalledTimes(1)

		mount(el)
		expect(apps).toHaveLength(2)
		expect(apps[1].props).toEqual({})
	})

	it('ignores a missing element and an element it never mounted', () => {
		const { mount, unmount } = registered[0]
		mount(null, {})
		mount(undefined, {})
		unmount(document.createElement('div'))
		expect(apps).toHaveLength(0)
	})
})

describe('publicpath', () => {
	afterEach(() => {
		delete globalThis.OC
		delete globalThis.__webpack_public_path__
		vi.resetModules()
	})

	it('points the public path at the app web root', async () => {
		globalThis.OC = { appswebroots: { integriq: '/custom_apps/integriq' } }
		globalThis.__webpack_public_path__ = 'unset'
		await import('@/publicpath.js')
		expect(globalThis.__webpack_public_path__).toBe('/custom_apps/integriq/js/')
	})

	it('leaves the public path alone without the app web root', async () => {
		globalThis.OC = { appswebroots: {} }
		globalThis.__webpack_public_path__ = 'unset'
		await import('@/publicpath.js')
		expect(globalThis.__webpack_public_path__).toBe('unset')
	})
})

describe('pinia', () => {
	it('exports one pinia instance', async () => {
		const { default: pinia } = await import('@/pinia.js')
		const again = (await import('@/pinia.js')).default
		expect(typeof pinia.install).toBe('function')
		expect(again).toBe(pinia)
	})
})

describe('settings', () => {
	it('mounts the admin settings with t and n on every component', async () => {
		const { default: app } = await import('@/settings.js')
		expect(app.component).toEqual({ name: 'AdminSettings' })
		expect(app.mount).toHaveBeenCalledWith('#settings')
		expect(app.config.globalProperties.t('integriq', 'Hello {who}', { who: 'you' })).toBe('Hello you')
		expect(typeof app.config.globalProperties.n).toBe('function')
	})
})
