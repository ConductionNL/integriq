// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Global integration-registration entry (Path 2).
//
// Loaded on EVERY Nextcloud page via `\OCP\Util::addInitScript` (see
// lib/AppInfo/Application.php) — NOT the per-app SPA. That's what lets
// OpenConnector's "Synced from" leaf render inside OTHER apps' detail pages
// (e.g. an OpenCatalogi publication) where OpenConnector's main SPA bundle is
// never loaded.
//
// RENDER MODE — `mount` (openregister#2127, ADR-066): OpenConnector is now Vue 3
// while the OpenRegister/OpenBuild host that renders the integration registry
// may still be Vue 2.7. A Vue-3 SFC handed to the host as `tab`/`widget` is
// interpreted under the host's own (incompatible) Vue runtime and renders blank
// — and because this leaf loads on EVERY NC page and renders in EVERY app's OR
// sidebar fleet-wide, a bare Vue-3 SFC descriptor would crash the sidebar
// fleet-wide. Instead the leaf ships a `mount(el, props)` / `unmount(el)` pair:
// the host hands us a bare, host-owned DOM element and we root OpenConnector's
// OWN Vue 3 app at it, so each side runs its own framework across the neutral
// DOM boundary. No `tab`/`widget` SFC is registered — a mount-mode descriptor
// renders through the pair, not the host's dynamic-component machinery.
//
// The host (openregister on @conduction/nextcloud-vue beta.223 + CnLeafMountHost)
// is renderMode-aware. `registerIntegration()` stays load-order-safe: if OR's
// bundle hasn't installed the real registry yet, the call is queued on a stub
// and replayed on install. This bundle MUST NOT call installIntegrationRegistry.

import { createApp } from 'vue'
import { registerIntegration } from '@conduction/nextcloud-vue'
import SyncedFromTab from './integration/SyncedFromTab.vue'

/**
 * Per-element registry of the Vue 3 app instances this leaf has mounted, so
 * `unmount(el)` finds and destroys the right one. Keyed by the host-owned DOM
 * element — NOT by leaf id — because the same leaf may be mounted into several
 * elements on one page at once (a sidebar tab AND a detail-page widget), each
 * its own instance.
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Mount hand-off (renderMode 'mount'). The host hands us a bare, host-owned
 * element already in the DOM; we root OpenConnector's OWN Vue 3 app at it with
 * the forwarded object context as root props, so the leaf renders under its own
 * framework even if the host runs Vue 2.7. Idempotent per element.
 *
 * @param {Element} el    Host-owned container element to root the app at.
 * @param {object}  props Forwarded context: { objectId, register, schema, apiBase, surface, … }.
 * @return {void}
 */
function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const app = createApp(SyncedFromTab, { ...(props || {}) })
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Teardown hand-off. Destroy the Vue 3 app instance rooted at `el` and release
 * the map entry so a mount/unmount cycle leaks no instance. Guarded against a
 * double-unmount and an unknown element.
 *
 * @param {Element} el The container element previously passed to `mount`.
 * @return {void}
 */
function unmount(el) {
	const app = mountedApps.get(el)
	if (app === undefined) {
		return
	}
	mountedApps.delete(el)
	app.unmount()
}

registerIntegration({
	id: 'sync-contract',
	label: 'Synced from',
	icon: 'SyncOutline',
	group: 'workflow',
	order: 50,
	requiredApp: 'openconnector',
	// Vue 3 leaf under a possibly-Vue-2.7 host: render via the DOM mount
	// hand-off, not an SFC the host would interpret under its own runtime
	// (openregister#2127). `mount`/`unmount` travel as a pair; no `tab`/`widget`
	// in mount mode. Same SyncedFromTab app drives the sidebar tab, the detail/
	// dashboard widget and the single-entity surface (the host forwards
	// `surface` on props when a surface distinction is needed).
	renderMode: 'mount',
	mount,
	unmount,
})
