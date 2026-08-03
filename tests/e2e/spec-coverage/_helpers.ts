/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for genuine behavioral UI coverage of openconnector.
 *
 * Design goals (gate-19 honest coverage):
 *  - Navigate via real nav-clicks from the in-app navigation, not just
 *    deep-link page.goto, so the test exercises the router + nav wiring.
 *  - Filter out Nextcloud core framework noise (user_status 500s, etc.)
 *    so console-error / 500 assertions only fail on openconnector-origin
 *    problems.
 */
import { type Page, expect } from '@playwright/test'
import { appDialog } from '../support/dialogs'

// The one openconnector URL base for the whole spec-coverage suite. Two
// separate things are encoded here, and both were learned from a failing run.
//
// 1. THE `/index.php/` PREFIX IS NOT OPTIONAL.
//
//    This used to read `/apps/openconnector/#`. That form works in the docker
//    dev images, where Apache + Nextcloud's `.htaccess` rewrite pretty URLs
//    onto `index.php`. CI has no Apache: the shared workflow serves Nextcloud
//    with `cd server && php -S 0.0.0.0:8080` and NO router script, and PHP's
//    built-in server resolves a request against the filesystem first.
//
//    Measured on a clean install (php -S, docroot = server/):
//
//        /index.php/apps/openconnector/   -> 200   (PATH_INFO reaches NC)
//        /apps/openconnector/             -> 404   (a real directory on disk
//                                                   with no index.php inside)
//        /apps/openconnector/js/…-main.js -> 200   (a real FILE, served flat)
//
//    Note the shape of that: the assets resolve fine, so nothing about the
//    build looks wrong — only the HTML entry point 404s. Every spec that deep-
//    linked through the short form therefore asserted against PHP's own 404
//    page, which has no `<main>`, no nav and no SPA. That is the single cause
//    behind "element(s) not found" for `main`, `Nav entry "Webhooks" must be
//    present`, and `Add Source button must be visible` alike — one cause
//    wearing ~130 disguises.
//
//    The discriminator is in the CI log itself: in the same run, on the same
//    instance, `configuration-export-import.spec.ts` — which probes
//    `/index.php/apps/openconnector` — PASSED its two page-mount assertions
//    while the specs on either side of it failed on `main`.
//
//    The `/index.php/` form is correct in BOTH environments (verified against
//    Apache: `/index.php/apps/openconnector/#/sources` renders `main` with the
//    "Add Source" button), so this is one form everywhere rather than a probe.
//
// 2. THE `#` IS NOT OPTIONAL EITHER.
//
//    The in-app router runs in HASH mode (`createWebHashHistory()`,
//    src/main.js), so a path-form deep-link such as `…/openconnector/sources`
//    is ignored by the router and silently lands on the dashboard. Only the
//    hash form renders the target page. APP_BASE carries the `/#` so
//    `${APP_BASE}/<route>` is a valid hash deep-link.
//
// Every spec-coverage file imports this rather than redeclaring it — nine of
// them used to keep private copies of the wrong string.
export const APP_BASE = '/index.php/apps/openconnector/#'

/**
 * The openconnector app root, without the router hash.
 *
 * Same `/index.php/` reasoning as APP_BASE above: use this anywhere a spec
 * needs the app entry point itself rather than a route inside it.
 */
export const APP_ROOT_URL = '/index.php/apps/openconnector/'

/**
 * URLs / console substrings that are Nextcloud core framework noise,
 * unrelated to the openconnector app under test. The dev container's
 * user_status OCS endpoint reliably 500s and core logs a matching error;
 * these must NOT fail an openconnector UI assertion.
 */
const NOISE_URL = /\/(user_status|heartbeat|notifications|core\/preview|avatar|files\/api)/i
const NOISE_CONSOLE = [
	'user_status',
	'Failed to load user status',
	'user-status',
	'heartbeat',
	// generic "Failed to load resource 500" lines belong to the noisy OCS
	// calls above; we still capture app-origin errors via the response hook
	'Failed to load resource',
	// NC theming/nldesign stylesheet noise: when the active theme's token CSS
	// is briefly unavailable mid-run it serves the 404 HTML page, tripping a
	// "Refused to apply style … MIME type ('text/html')" console error. This is
	// NC theme/environment noise, never an openconnector-origin failure.
	'Refused to apply style',
	'is not a supported stylesheet MIME type',
]

export interface ErrorSink {
	consoleErrors: string[]
	serverErrors: string[]
}

/**
 * Attach console-error and HTTP>=500 listeners that ignore NC core noise.
 * Returns the sink to assert against after navigation.
 */
export function trackErrors(page: Page): ErrorSink {
	const sink: ErrorSink = { consoleErrors: [], serverErrors: [] }
	page.on('console', (msg) => {
		if (msg.type() !== 'error') return
		const text = msg.text()
		if (NOISE_CONSOLE.some((n) => text.includes(n))) return
		sink.consoleErrors.push(text.slice(0, 300))
	})
	page.on('response', (r) => {
		if (r.status() < 500) return
		if (NOISE_URL.test(r.url())) return
		sink.serverErrors.push(`${r.status()} ${r.url()}`)
	})
	return sink
}

/**
 * Assert the sink saw no openconnector-origin console errors or 5xx.
 * Specifically calls out any /apps/openconnector/ 5xx as a hard failure
 * (catches sync/endpoint dispatch regressions).
 */
export function assertNoAppErrors(sink: ErrorSink): void {
	const appServerErrors = sink.serverErrors.filter((e) => /openconnector|openregister/.test(e))
	expect(appServerErrors, `Unexpected app 5xx responses: ${appServerErrors.join(', ')}`).toEqual([])
	expect(sink.consoleErrors, `Unexpected console errors: ${sink.consoleErrors.join(' | ')}`).toEqual([])
}

/**
 * Expand every collapsible nav group so entries nested inside a collapsed
 * group become visible/clickable.
 *
 * The nav was clustered into groups (commit 429ec463 — "cluster navigation
 * into groups for ≤8 top-level items"). Each group renders a collapsible
 * header (`<a href="#" aria-expanded="false">`); its child entries (Sources,
 * Endpoints, Jobs, …) are hidden until the group is expanded. Tests that click
 * a grouped entry must expand its group first, otherwise the entry link is in
 * the DOM but not visible. We expand all collapsed groups defensively so the
 * caller doesn't need to know which group an entry lives in.
 */
async function expandNavGroups(page: Page): Promise<void> {
	// Re-query after every click: expanding one group re-renders the nav (its
	// child entries appear), which invalidates positional locators. Repeatedly
	// click the FIRST still-collapsed group header until none remain.
	for (let guard = 0; guard < 10; guard++) {
		const header = page.locator('.app-navigation a[href="#"][aria-expanded="false"]').first()
		if (!(await header.isVisible({ timeout: 500 }).catch(() => false))) break
		await header.click().catch(() => {})
		await page.waitForTimeout(200)
	}
}

/**
 * Land in the app (deep-link the first route once), then click the named
 * navigation entry and wait for the route to settle. This exercises the
 * real in-app navigation rather than a raw deep-link to the target page.
 *
 * `expectedRoute` is the route fragment the URL should contain after the
 * click (e.g. '/sources').
 */
export async function navTo(page: Page, navLabel: string, expectedRoute: string): Promise<void> {
	// Land on the app root first so the SPA + nav are mounted.
	if (!page.url().includes('/apps/openconnector')) {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
	}
	// Reveal entries nested inside collapsed nav groups before locating them.
	await expandNavGroups(page)
	// The app-navigation (left sidebar) renders the in-app router-links.
	// Scope strictly to it — NOT the global NC header nav, whose "Dashboard"
	// link points at /apps/dashboard/ and would navigate out of the app.
	const navLink = page
		.locator('.app-navigation')
		.getByRole('link', { name: new RegExp(`^\\s*${navLabel}\\s*$`, 'i') })
		.first()
	await expect(navLink, `Nav entry "${navLabel}" must be present`).toBeVisible({ timeout: 20_000 })
	await navLink.click()
	await page.waitForURL((url) => url.toString().includes(expectedRoute), { timeout: 15_000 }).catch(() => {})
	await page.waitForLoadState('networkidle').catch(() => {})
}

/**
 * Assert an index page surfaced its heading and primary content area.
 */
export async function expectHeading(page: Page, heading: RegExp): Promise<void> {
	await expect(page.getByRole('heading', { name: heading }).first()).toBeVisible({ timeout: 15_000 })
}

/**
 * Open a primary create modal by its "Add X" button, assert a dialog
 * appears, then dismiss it without saving.
 *
 * @param page      the Playwright page
 * @param addButton accessible-name regex for the create button
 */
export async function openAndDismissCreateModal(page: Page, addButton: RegExp): Promise<void> {
	const addBtn = page.getByRole('button', { name: addButton }).first()
	await expect(addBtn, `"${addButton}" button must be visible`).toBeVisible({ timeout: 20_000 })
	await addBtn.click()
	// appDialog(), not getByRole('dialog').first(): NC's first-run wizard and
	// nc-vue's support dialog are themselves role="dialog" overlays, so the
	// naive locator can match one of them after a click they intercepted and
	// report a modal that never opened as open.
	const dialog = appDialog(page)
	await expect(dialog, 'Create modal must open').toBeVisible({ timeout: 10_000 })
	const cancel = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
	if (await cancel.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await cancel.click()
	} else {
		await page.keyboard.press('Escape')
	}
}
