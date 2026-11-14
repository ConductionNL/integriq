/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app entry, src/main.js: it merges the bundled manifest with the
 * manifest.d fragments, derives the router from the merged pages (plus the
 * legacy redirects and the admin-settings hand-off), and mounts App with the
 * registries as props.
 *
 * main.js reads the fragments through webpack's require.context, which does
 * not exist under vitest. Vitest hands every module its own CommonJS
 * `require`, so a global cannot stand in for it; each test instead defines a
 * temporary `context` on Function.prototype (removed after the test) that
 * serves a fixed set of fragments. The library, vue, the
 * router and the heavy component registry are replaced at their module
 * boundary; the route table and the bootstrap order are main.js's own logic.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
	apps: [],
	routers: [],
	histories: [],
	buildManifestCalls: [],
	manifest: null,
	registerTranslations: null,
	loadTranslations: null,
	registerIcons: null,
	registerFlowNodeEditor: null,
	setRouter: null,
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnPageRenderer: { name: 'CnPageRenderer', render: () => null },
	defaultPageTypes: { index: 'IndexType', detail: 'DetailType' },
	buildManifest: (bundled, fragments, menu) => {
		state.buildManifestCalls.push({ bundled, fragments, menu })
		return state.manifest
	},
	registerFlowNodeEditor: (...args) => state.registerFlowNodeEditor(...args),
	registerIcons: (...args) => state.registerIcons(...args),
	registerTranslations: (...args) => state.registerTranslations(...args),
}))
vi.mock('@conduction/nextcloud-vue/css/index.css', () => ({}))
vi.mock('gridstack/dist/gridstack.min.css', () => ({}))
vi.mock('@/assets/app.css', () => ({}))
vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: (...args) => state.loadTranslations(...args),
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		createApp: (options) => {
			const app = {
				options,
				config: { globalProperties: {} },
				plugins: [],
				use(plugin) {
					this.plugins.push(plugin)
					return this
				},
				mount: vi.fn(),
			}
			state.apps.push(app)
			return app
		},
	}
})
vi.mock('vue-router', () => ({
	createWebHistory: (base) => {
		const history = { base }
		state.histories.push(history)
		return history
	},
	createRouter: (options) => {
		const router = { options }
		state.routers.push(router)
		return router
	},
}))
vi.mock('@/App.vue', () => ({ default: { name: 'App' } }))
vi.mock('@/registry.js', () => ({
	default: { CatalogItemCard: { name: 'CatalogItemCard' } },
	registry: { RuleDetailPage: { kind: 'page', component: { name: 'RuleDetailPage' } } },
}))
vi.mock('@/pinia.js', () => ({ default: { name: 'pinia' } }))
vi.mock('@/icons.js', () => ({ default: { Cog: 'cog-svg' } }))
vi.mock('@/formatters.js', () => ({ default: { connectionStatus: (s) => s } }))
vi.mock('@/handlers/routerRef.js', () => ({
	setRouter: (...args) => state.setRouter(...args),
}))
vi.mock('@/publicpath.js', () => ({}))

const FRAGMENTS = {
	'./b-second.json': { id: 'second' },
	'./a-first.json': { id: 'first' },
}

let fakeWindow
let requireContext

/**
 * Import a fresh copy of main.js against the current fakes.
 *
 * @param {string} pathname The window path the entry boots under.
 * @return {Promise<void>}
 */
async function boot(pathname = '/index.php/apps/integriq/sources') {
	fakeWindow = { location: { pathname, href: 'unchanged' } }
	vi.stubGlobal('window', fakeWindow)
	vi.resetModules()
	await import('@/main.js')
}

beforeEach(() => {
	state.apps.length = 0
	state.routers.length = 0
	state.histories.length = 0
	state.buildManifestCalls.length = 0
	state.manifest = {
		pages: [
			{ id: 'Sources', route: '/sources' },
			{ id: 'SourceDetail', route: '/sources/:id' },
		],
	}
	state.registerTranslations = vi.fn()
	state.loadTranslations = vi.fn()
	state.registerIcons = vi.fn()
	state.registerFlowNodeEditor = vi.fn()
	state.setRouter = vi.fn()

	const context = (key) => FRAGMENTS[key]
	context.keys = () => Object.keys(FRAGMENTS)
	requireContext = vi.fn(() => context)
	Object.defineProperty(Function.prototype, 'context', {
		configurable: true,
		writable: true,
		value: requireContext,
	})
})

afterEach(() => {
	delete Function.prototype.context
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('main.js manifest', () => {
	it('merges the bundled manifest with the fragments in file-name order and the menu layout', async () => {
		await boot()

		expect(requireContext).toHaveBeenCalledWith('./manifest.d/', false, /\.json$/)
		expect(state.buildManifestCalls).toHaveLength(1)
		const { bundled, fragments, menu } = state.buildManifestCalls[0]
		expect(fragments).toEqual([{ id: 'first' }, { id: 'second' }])
		expect(Array.isArray(bundled.pages)).toBe(true)
		expect(menu).toBeTypeOf('object')
		expect(menu).not.toBe(bundled)
	})
})

describe('main.js routes', () => {
	it('maps every page to a named route and passes params as props only when the route has one', async () => {
		await boot()

		const routes = state.routers[0].options.routes
		expect(routes[0]).toMatchObject({ name: 'Sources', path: '/sources', props: false })
		expect(routes[1]).toMatchObject({ name: 'SourceDetail', path: '/sources/:id', props: true })
		expect(routes[0].component).toBe(routes[1].component)
		expect(routes[0].component.name).toBe('CnPageRenderer')
	})

	it('keeps the legacy paths as redirects and ends with a catch-all to the root', async () => {
		await boot()

		const routes = state.routers[0].options.routes
		const redirects = Object.fromEntries(
			routes.filter((r) => r.redirect !== undefined).map((r) => [r.path, r.redirect]),
		)
		expect(redirects).toEqual({
			'/cloud-events': '/cloud-events/events',
			'/catalog': '/store',
			'/cloud-events/deliveries': { path: '/dead-letters', query: { queue: 'events' } },
			'/synchronizations/dead-letters': { path: '/dead-letters', query: { queue: 'sync' } },
			'/environments': '/sources',
			'/:pathMatch(.*)*': '/',
		})
		expect(routes[routes.length - 1].path).toBe('/:pathMatch(.*)*')
	})

	it('hands /settings off to the admin settings page and cancels the in-app navigation', async () => {
		await boot()

		const settings = state.routers[0].options.routes.find((r) => r.path === '/settings')
		expect(settings.component.name).toBe('CnPageRenderer')
		expect(settings.beforeEnter()).toBe(false)
		expect(fakeWindow.location.href).toBe('/index.php/settings/admin/integriq')
	})
})

describe('main.js router base', () => {
	it('uses the app prefix of the current path, keeping index.php', async () => {
		await boot('/index.php/apps/integriq/sources/12')

		expect(state.histories[0].base).toBe('/index.php/apps/integriq')
		expect(state.routers[0].options.history).toBe(state.histories[0])
	})

	it('matches the bare app path without a trailing segment', async () => {
		await boot('/apps/integriq')

		expect(state.histories[0].base).toBe('/apps/integriq')
	})

	it('falls back to the generated app URL when the path is not under the app', async () => {
		await boot('/apps/integriqx/other')

		expect(state.histories[0].base).toBe('/index.php/apps/integriq')
	})

	it('publishes the router for the action handlers', async () => {
		await boot()

		expect(state.setRouter).toHaveBeenCalledTimes(1)
		expect(state.setRouter).toHaveBeenCalledWith(state.routers[0])
	})
})

describe('main.js bootstrap', () => {
	it('registers the icons and the synchronization flow node editor', async () => {
		await boot()

		expect(state.registerIcons).toHaveBeenCalledWith({ Cog: 'cog-svg' })
		expect(state.registerFlowNodeEditor).toHaveBeenCalledTimes(1)
		const [nodeType, editor] = state.registerFlowNodeEditor.mock.calls[0]
		expect(nodeType).toBe('openconnector.synchronization-run')
		expect(editor).toBeTypeOf('object')
	})

	it('mounts App on #content with the manifest and copies of the registries', async () => {
		await boot()

		expect(state.apps).toHaveLength(1)
		const app = state.apps[0]
		const vnode = app.options.render()
		expect(vnode.type).toEqual({ name: 'App' })
		expect(vnode.props.manifest).toBe(state.manifest)
		expect(vnode.props.customComponents).toEqual({ CatalogItemCard: { name: 'CatalogItemCard' } })
		expect(vnode.props.registry).toEqual({
			RuleDetailPage: { kind: 'page', component: { name: 'RuleDetailPage' } },
		})
		expect(vnode.props.formatters).toHaveProperty('connectionStatus')
		expect(vnode.props.pageTypes).toEqual({ index: 'IndexType', detail: 'DetailType' })
		expect(app.plugins).toEqual([{ name: 'pinia' }, state.routers[0]])
		expect(app.mount).toHaveBeenCalledWith('#content')
	})

	it('exposes t and n to every template', async () => {
		await boot()

		const { t, n } = state.apps[0].config.globalProperties
		expect(t('integriq', 'Sources')).toBe('t:Sources')
		expect(n('integriq', 'source', 'sources', 2)).toBe('sources')
	})

	it('loads the integriq translations once', async () => {
		await boot()

		expect(state.loadTranslations).toHaveBeenCalledTimes(1)
		expect(state.loadTranslations.mock.calls[0][0]).toBe('integriq')
		expect(state.loadTranslations.mock.calls[0][1]).toBeTypeOf('function')
	})

	it('still mounts in English when the library translations fail to register', async () => {
		const failure = new Error('no bundle')
		state.registerTranslations = vi.fn(() => {
			throw failure
		})
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		await boot()

		expect(warn).toHaveBeenCalledWith(
			'[integriq] registerTranslations failed; falling back to English',
			failure,
		)
		expect(state.apps[0].mount).toHaveBeenCalledWith('#content')
	})

	it('still mounts when loading the app translations throws', async () => {
		state.loadTranslations = vi.fn(() => {
			throw new Error('no l10n endpoint')
		})

		await boot()

		expect(state.apps[0].mount).toHaveBeenCalledWith('#content')
	})

	it('absorbs a rejected translation load instead of leaving it unhandled', async () => {
		// A plain function, not vi.fn: vi.fn subscribes to a returned promise
		// to record its result, which would absorb the rejection on main.js's
		// behalf and hide a missing handler.
		let rejected
		let then
		state.loadTranslations = () => {
			rejected = Promise.reject(new Error('404'))
			then = vi.spyOn(rejected, 'then')
			return rejected
		}

		await boot()

		expect(then).toHaveBeenCalledTimes(1)
		const [onFulfilled, onRejected] = then.mock.calls[0]
		expect(onFulfilled).toBeTypeOf('function')
		expect(onRejected).toBeTypeOf('function')
		expect(onRejected(new Error('404'))).toBeUndefined()
		await expect(rejected.then(onFulfilled, onRejected)).resolves.toBeUndefined()
		expect(state.apps[0].mount).toHaveBeenCalledWith('#content')
	})
})
