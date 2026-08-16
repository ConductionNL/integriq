/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The single source of truth for the URL prefix an in-app deep link must use.
 *
 * WHY THIS EXISTS — and why the obvious probe is wrong
 * ----------------------------------------------------
 * `src/main.js` builds the router as
 * `createWebHistory(generateUrl('/apps/openconnector'))`. Since the switch to
 * PATH-based history, a deep link is only honoured when its prefix is BYTE-FOR
 * -BYTE the value `generateUrl()` returned in that browser:
 *
 *   - apache dev container (mod_rewrite on)  → `/apps/openconnector`
 *   - CI's `php -S` install (no rewrite)     → `/index.php/apps/openconnector`
 *
 * A URL under the other prefix sits OUTSIDE the router base, matches no route,
 * hits the `'/:pathMatch(.*)*'` catch-all and is redirected to `/` — the
 * DASHBOARD. Nothing throws, nothing 404s, no console error is emitted.
 *
 * Six spec files each kept a private copy of this probe:
 *
 *     for (const candidate of ['/apps/openconnector', '/index.php/apps/openconnector'])
 *         if (res.ok() && (await res.text()).includes('openconnector-main.js'))
 *             return candidate
 *
 * 🔴 That probe CANNOT WORK, and it fails towards green. Nextcloud serves the
 * identical SPA shell under BOTH prefixes — same 200, same
 * `openconnector-main.js` — so the loop always stops at the first candidate,
 * `/apps/openconnector`, which is the one CI's router will not honour. Every
 * caller then loaded the Dashboard while believing it was on the page it named.
 * Measured in run 31929156734: the browser sat at
 * `http://localhost:8080/apps/openconnector/sources` while the app's own XHRs
 * went to `/index.php/apps/...`, i.e. `generateUrl()` had returned the OTHER
 * prefix. Specs asserting "something rendered" passed against the Dashboard;
 * specs asserting a real selector failed with a misleading message.
 *
 * THE FIX: ask the app. `OC.generateUrl()` is the very function `src/main.js`
 * calls, evaluated in the same browser, so the answer cannot disagree with the
 * router base. It is a resolution, not a guess — there is no candidate list
 * left to get in the wrong order.
 */

import { expect, type Page } from '@playwright/test'

/**
 * The app id, and therefore the path `generateUrl` is asked to resolve.
 */
const APP_ID = 'openconnector'

/**
 * The entry point used to ASK the instance for its webroot.
 *
 * The `/index.php/` form is deliberate and is not the answer being computed:
 * it is the one form Nextcloud serves in every configuration, rewrite or not,
 * so the question can always be put. Which prefix the ROUTER wants is then
 * read out of `OC.generateUrl`, and on a rewrite-enabled instance that is the
 * other one.
 */
const PROBE_URL = `/index.php/apps/${APP_ID}/`

/**
 * Cached per worker process. Playwright reuses a worker across spec files, so
 * the probe navigation happens once for the whole run, not once per file.
 */
let cached: string | null = null

/**
 * Resolve the prefix an in-app deep link must carry.
 *
 * @param page A Playwright page. It is navigated to the app root on the first
 *   call of a worker; later calls return the cached value and navigate nothing.
 *
 * @return The router base, without a trailing slash — e.g.
 *   `/index.php/apps/openconnector` on CI, `/apps/openconnector` on a
 *   rewrite-enabled dev container.
 */
export async function resolveAppRoot(page: Page): Promise<string> {
	if (cached !== null) {
		return cached
	}

	await page.goto(PROBE_URL, { waitUntil: 'domcontentloaded', timeout: 30_000 })

	const resolved = await page.evaluate((appId) => {
		const oc = (
			window as unknown as { OC?: { generateUrl?: (p: string) => string } }
		).OC
		return oc?.generateUrl?.(`/apps/${appId}`) ?? null
	}, APP_ID)

	// An unreadable answer is "I could not tell", never a default. Silently
	// falling back to a hardcoded prefix here would reintroduce exactly the
	// guess this module exists to delete, and it would do it invisibly.
	if (typeof resolved !== 'string' || resolved === '') {
		throw new Error(
			`OC.generateUrl is unavailable at ${PROBE_URL}, so the router base cannot be resolved. `
				+ 'That means Nextcloud core JS did not load — do not guess a prefix, fix the page load.',
		)
	}

	const root = resolved.replace(/\/+$/, '')
	if (root.endsWith(`/apps/${APP_ID}`) === false) {
		throw new Error(
			`OC.generateUrl('/apps/${APP_ID}') returned ${JSON.stringify(resolved)}, `
				+ `which does not end in /apps/${APP_ID}. Refusing to deep-link against it.`,
		)
	}

	cached = root
	return root
}

/**
 * Deep-link to an in-app route, through the resolved router base.
 *
 * Do NOT add a `#`. The router is path-mode and never reads `location.hash`;
 * a hash-form URL is served happily by the SPA shell and then ignored, landing
 * on the Dashboard — the same silent wrong-page failure the prefix probe used
 * to cause, by a different route.
 *
 * @param page  A Playwright page.
 * @param route In-app route beginning with `/`, e.g. `/sources`.
 *
 * @return Nothing.
 */
export async function gotoAppRoute(page: Page, route: string): Promise<void> {
	const root = await resolveAppRoot(page)
	await page.goto(`${root}${route}`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
}

/**
 * Assert the router actually MATCHED the route, rather than falling through.
 *
 * The catch-all is `{ path: '/:pathMatch(.*)*', redirect: '/' }`, so an
 * unmatched deep link rewrites the address bar to the app root. Comparing the
 * final URL against the requested route is therefore an exact, cheap test of
 * "did the router resolve this page" — and it is the assertion whose absence
 * let 36 `manifest-pages` tests photograph the Dashboard and report green.
 *
 * @param page  A Playwright page that has already navigated.
 * @param route The in-app route that was requested, beginning with `/`.
 *
 * @return Nothing.
 */
export async function expectRouteMatched(page: Page, route: string): Promise<void> {
	if (route === '/' || route === '') {
		return
	}

	const url = new URL(page.url())
	expect(
		url.pathname,
		`the router did not match ${route}: the address bar reads ${url.pathname}. `
			+ 'A path outside the router base falls through the catch-all and redirects to the '
			+ 'Dashboard, so every "something rendered" assertion below would pass against the '
			+ 'wrong page.',
	).toContain(route)
}
