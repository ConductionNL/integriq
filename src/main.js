// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	registerFlowNodeEditor,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, defineAsyncComponent, h } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { setRouter } from './handlers/routerRef.js'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import customComponents, { registry } from './registry.js'

// Must run before any other import so webpack's publicPath is set before the
// first lazy chunk loads (fixes chunk 404s on non-/apps install paths).
import './publicpath.js'
// MDI icons referenced by manifest `headerActions[]` / `actions[]` /
// `menu[]` entries. CnActionsBar + CnAppNav render them via CnIcon,
// which reads from the per-app registry below. Anything NOT registered
// here falls back to HelpCircleOutline (the `?` placeholder) at render
// time. Keep in PascalCase, matching the file-name in
// vue-material-design-icons/.
//
// Menu icons restore the pre-chain-E set (the chain-E manifest cutover
// swapped them all to `icon-*` Nextcloud CSS classes which lost the
// semantic specificity and didn't size-match the rest of the chrome).
// These now live in src/icons.js, generated from this app's own manifests and
// register files so the registry can never drift behind the icons the
// manifests name (ADR-077).
// Library CSS — must be an explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// GridStack CSS. `gridstack` is a *required* peerDependency of
// @conduction/nextcloud-vue (CnDashboardGrid / CnWidgetGrid import `GridStack`
// from it) and the library deliberately bundles neither the JS nor the CSS, so
// that a consumer cannot end up with the layout engine and its stylesheet on
// two different majors. Both therefore have to come from *this* app's single
// installed copy: the JS via the peer resolution, the CSS via this import.
// Skipping the stylesheet is the silent-failure case — grid items get their
// height from the JS but their width from the CSS, so every dashboard widget
// lays out 0px wide with no console error at all.
import 'gridstack/dist/gridstack.min.css'
// Global (unscoped) app styles
import './assets/app.css'

// Vue 3 (ADR-066): global t/n install via app.config.globalProperties after
// createApp (below), not Vue.mixin. pinia + router install via app.use. There
// is no PiniaVuePlugin in Vue 3.

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)

// A synchronization-run step opens the REAL Synchronization dialog instead of
// the generic key-per-field editor: a step whose configuration IS a
// synchronization deserves that surface (flow-native-synchronization 0.4).
// Async so the wide dialog and its sync widgets stay out of the entry bundle.
registerFlowNodeEditor(
	'openconnector.synchronization-run',
	defineAsyncComponent(() => import('./views/Flow/SynchronizationNodeEditor.vue')),
)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn(
		'[openconnector] registerTranslations failed; falling back to English',
		e,
	)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache and rewrite everything else to index.php —
// there is no route for /custom_apps/<app>/l10n/<locale>.json so the request
// 404s. Boot MUST NOT depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('openconnector', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline.
// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * underlying detail/custom component receives the route param.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Legacy redirect: /cloud-events → /cloud-events/events (preserves bookmarks)
	routes.push({ path: '/cloud-events', redirect: '/cloud-events/events' })

	// ADR-079 / ADR-080 navigation rework. Every path below was a real page
	// before this change, so a bare rename would 404 existing bookmarks,
	// `deepLinks[]` entries and e2e specs. Redirects are part of the rework,
	// not cleanup afterwards.
	//
	// Catalog → Store (ADR-080: "catalogue" names a different concept).
	routes.push({ path: '/catalog', redirect: '/store' })
	// The two dead-letter queues merged into one Operations page; the queue
	// param lands the caller on the queue their old link meant.
	routes.push({
		path: '/cloud-events/deliveries',
		redirect: { path: '/dead-letters', query: { queue: 'events' } },
	})
	routes.push({
		path: '/synchronizations/dead-letters',
		redirect: { path: '/dead-letters', query: { queue: 'sync' } },
	})
	// Environments stopped being an index: an environment is metadata plus a
	// sourceRef, so it now renders as a widget on the Source it points at.
	routes.push({ path: '/environments', redirect: '/sources' })
	// ADR-079: the in-app settings page is gone — app configuration lives in
	// Nextcloud's settings framework, which authorizes it server-side. This
	// leaves the SPA entirely, so it cannot be a vue-router `redirect` (those
	// resolve within the app); `beforeEnter` + `return false` navigates away
	// and cancels the in-app transition instead of landing on the dashboard.
	routes.push({
		path: '/settings',
		beforeEnter: () => {
			window.location.href = generateUrl('/settings/admin/openconnector')
			return false
		},
		component: RoutePageRenderer,
	})
	// Catch-all redirect to dashboard. vue-router 4: the bare '*' catch-all
	// became a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

const router = createRouter({
	// Path-based history, not hash. The server-side half of this was already
	// built (ui#dashboard's `/{path}` catch-all in appinfo/routes.php, from the
	// unfinished "chain-C" cutover — excludes `/api/*` so deleted API routes
	// still 404 correctly) and verified live before this line changed: a
	// never-before-hit deep path returned 200/text-html. This was the only
	// missing piece.
	history: createWebHistory(generateUrl('/apps/openconnector')),
	routes: routesFromManifest(mergedManifest),
})

// Expose the router instance to registry-resolved row-action handlers
// (CnIndexPage invokes those with `{ actionId, item }` — no Vue
// component context, so `this.$router` is not available). See #837 /
// nc-vue#330 — the `viewLogsHandler` reads it for query-aware navigation
// until nc-vue gains a `queryParam` field on the built-in `navigate`
// handler.
setRouter(router)

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen module
// objects in some bundle shapes — Vue 2's `Vue.extend()` mutates component
// definitions to attach an internal `_Ctor` cache, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source map. Cloning here yields extensible objects without changing
// the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
// V2 component registry (ADR-036): kind-tagged custom-page map CnAppRoot uses to
// resolve `type:"custom"` page components under nc-vue@2. Shallow-cloned for the
// same extensibility reason as the maps above.
const registryProp = { ...registry }

const app = createApp({
	// Pure Vue 3 (ADR-066): native render() with `h` from 'vue'. Props pass
	// FLAT (no `props:` wrapper in the data object).
	render: () =>
		h(App, {
			manifest: mergedManifest,
			customComponents: customComponentsProp,
			registry: registryProp,
			pageTypes: pageTypesProp,
		}),
})

// Vue 3 global install contract (ADR-066): t/n move from Vue.mixin /
// Vue.prototype to app.config.globalProperties.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.use(pinia)
app.use(router)
app.mount('#content')
