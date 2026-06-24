// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
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
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import VectorPolylinePlus from 'vue-material-design-icons/VectorPolylinePlus.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'

// Library CSS — must be an explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons({
	AccountMultipleOutline,
	Api,
	BookOpenVariant,
	CloudUploadOutline,
	Cog,
	DatabaseArrowLeftOutline,
	EyeOutline,
	Pencil,
	ScaleBalance,
	SitemapOutline,
	TextBoxOutline,
	Update,
	VectorPolylinePlus,
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
	render(h) {
		return h(RoutePageRenderer, { on: { add: createMappingAndOpen } })
	},
}

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` and `menu` arrays are concatenated.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu appended.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [...(base.menu || [])] }
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a _placeholder.json). It is a no-op when the directory holds no
	// real fragments beyond the empty placeholder.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			merged.pages.push(...frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			merged.menu.push(...frag.menu)
		}
	})
	merged.menu = applySettingsSection(merged.menu, menuLayout.settingsSection)
	return merged
}

/**
 * Promote the menu entries listed in `src/menu-layout.json#settingsSection`
 * into Nextcloud's settings foldout — the NcAppNavigationSettings gear at the
 * bottom-left of the navigation, OUTSIDE the scrollable list. CnAppNav renders
 * every TOP-LEVEL item carrying `section: "settings"` as a flat entry inside
 * that foldout. This lifts each listed id out of wherever it currently sits,
 * tags it `section: "settings"`, flattens it (the foldout has no nested
 * groups), and appends it to the top level. Empty non-clickable groups left
 * behind are dropped; a clickable group (route/href/action) is kept.
 *
 * Fragments stay the canonical source of WHAT exists in the menu (ADR-037);
 * this map keeps the WHERE decision in the canonical layout file.
 *
 * @param {Array<object>} menu        The merged menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const want = new Set(settingsIds)
	const isClickable = (n) => n.route !== undefined || n.href !== undefined || n.action !== undefined
	const lifted = []
	const strip = (nodes) => nodes.reduce((acc, n) => {
		if (want.has(n.id)) {
			const { children, ...leaf } = n
			lifted.push({ ...leaf, section: 'settings' })
			return acc
		}
		if (Array.isArray(n.children)) {
			const children = strip(n.children)
			if (children.length === 0 && n.children.length > 0 && !isClickable(n)) return acc
			acc.push({ ...n, children })
			return acc
		}
		acc.push(n)
		return acc
	}, [])
	const remaining = strip(menu)
	return [...remaining, ...lifted]
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
	// Catch-all redirect to dashboard
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'hash',
	base: generateUrl('/apps/openconnector'),
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

new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest: mergedManifest,
			customComponents: customComponentsProp,
			pageTypes: pageTypesProp,
		},
	}),
}).$mount('#content')
