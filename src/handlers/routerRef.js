// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Module-scoped vue-router holder. main.js sets the reference once the
// router is constructed; row-action handlers in `actionHandlers.js` read
// it to perform query-aware navigation (`viewLogsHandler` for #837).
//
// Why not use `this.$router` inside the handler? CnIndexPage's
// `resolveHandler` invokes registry handlers with `{ actionId, item }` —
// no Vue component context, no `$router`. We avoid changing nc-vue's
// handler signature contract by stashing the router instance here at
// app boot and pulling it from a 2-line side-effect import.
//
// The reserved-keyword `handler: "navigate"` does NOT need this — that
// path runs inside CnIndexPage where `this.$router` is available. This
// holder exists strictly for the custom-registry path until nc-vue#330
// (queryParam on the navigate handler + CnLogsPage filter pre-fill) ships.

let routerInstance = null

/**
 * Store the app's vue-router instance for later retrieval by registry
 * handlers. Idempotent — calling twice with the same instance is fine;
 * calling with a different instance overwrites (test harnesses do this).
 *
 * @param {import('vue-router').default} router The constructed router.
 */
export function setRouter(router) {
	routerInstance = router
}

/**
 * Retrieve the app's vue-router instance. Returns null when called
 * before `setRouter` (e.g. in unit tests that import the handler in
 * isolation). Callers MUST handle the null case — typically a
 * console.warn + early return.
 *
 * @return {import('vue-router').default|null}
 */
export function getRouter() {
	return routerInstance
}
