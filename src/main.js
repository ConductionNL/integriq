// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Must run before any other import so webpack's publicPath is set before the
// first lazy chunk loads (fixes chunk 404s on non-/apps install paths).
import './publicpath.js'

import { createApp, h } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
	buildManifest,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import customComponents from './registry.js'
import { setRouter } from './handlers/routerRef.js'
import { createMappingAndOpen } from './handlers/actionHandlers.js'

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
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import Api from 'vue-material-design-icons/Api.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileCogOutline from 'vue-material-design-icons/FileCogOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PowerPlugOutline from 'vue-material-design-icons/PowerPlugOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import VectorPolylinePlus from 'vue-material-design-icons/VectorPolylinePlus.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'

// Library CSS — must be an explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

// Vue 3 (ADR-066): global t/n install via app.config.globalProperties after
// createApp (below), not Vue.mixin. pinia + router install via app.use. There
// is no PiniaVuePlugin in Vue 3.

// Register library-side icon set + lib translations once at bootstrap.
registerIcons({
	AccountMultipleOutline,
	Api,
	BookOpenVariant,
	CloudUploadOutline,
	Cog,
	DatabaseArrowLeftOutline,
	Download,
	EyeOutline,
	FileCogOutline,
	Pencil,
	PowerPlugOutline,
	ScaleBalance,
	SitemapOutline,
	TextBoxOutline,
	Update,
	Upload,
	VectorPolylinePlus,
	ViewGridOutline,
	Webhook,
})
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[openconnector] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache and rewrite everything else to index.php —
// there is no route for /custom_apps/<app>/l10n/<locale>.json so the request
// 404s. Boot MUST NOT depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('openconnector', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
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

// The Mappings index Add button must open the bespoke MappingDetail editor
// (a page) rather than the generic name/description form dialog. The primary
// Add button delegates to a parent `@add` listener when one is present
// (CnIndexPage.onAddClick), so this thin wrapper — used only for the Mappings
// route — attaches that listener while keeping the primary button intact.
// CnPageRenderer forwards $listeners down to CnIndexPage, so `@add` here
// reaches the button; all other routes use the plain RoutePageRenderer and
// keep the default form-dialog create.
const MappingsPageRenderer = {
	name: 'MappingsPageRenderer',
	// Vue 3 (ADR-066): native h() — the Vue-2 `{ on: { add } }` data object
	// became a flat `onAdd` listener prop. CnPageRenderer forwards fall-through
	// attrs (incl. onAdd) down to CnIndexPage, so `@add` still reaches the
	// primary Add button.
	render() {
		return h(RoutePageRenderer, { onAdd: createMappingAndOpen })
	},
}

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline.
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
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
		component: page.id === 'Mappings' ? MappingsPageRenderer : RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Legacy redirect: /cloud-events → /cloud-events/events (preserves bookmarks)
	routes.push({ path: '/cloud-events', redirect: '/cloud-events/events' })
	// Catch-all redirect to dashboard. vue-router 4: the bare '*' catch-all
	// became a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/openconnector')),
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

const app = createApp({
	// This root uses a native Vue-3 render() (h from 'vue'). @vue/compat would
	// otherwise treat ANY `render` option as the deprecated Vue-2 RENDER_FUNCTION
	// API and wrap it, which nulls `currentRenderingInstance` for the whole
	// subtree — breaking renderSlot/resolveComponent in CnAppRoot (ADR-066).
	compatConfig: { RENDER_FUNCTION: false },
	// Vue 3: props pass FLAT (no `props:` wrapper in the data object).
	render: () => h(App, {
		manifest: mergedManifest,
		customComponents: customComponentsProp,
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
