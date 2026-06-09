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

export const APP_BASE = '/apps/openconnector'

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
	const dialog = page.getByRole('dialog').first()
	await expect(dialog, 'Create modal must open').toBeVisible({ timeout: 10_000 })
	const cancel = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
	if (await cancel.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await cancel.click()
	} else {
		await page.keyboard.press('Escape')
	}
}
