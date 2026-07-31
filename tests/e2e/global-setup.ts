/*
 * SPDX-FileCopyrightText: 2026 OpenConnector Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Why a real browser login (instead of POSTing to /login directly):
 * Nextcloud's login form ships a CSRF token (`requesttoken`) plus a
 * `oc_session_passphrase` cookie that must be set in the same browser
 * context. Driving the form via Playwright sidesteps having to
 * reverse-engineer the token-rotation contract, which has shifted across
 * NC 28 / 29 / 30.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from decidesk's journeydoc setup.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import { BASE_URL } from './support/baseUrl'
import { seedFirstVisitOverlaysSeen } from '@conduction/nextcloud-vue/testing/playwright'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'openconnector-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/openconnector/`.
 *
 * The shared `ConductionNL/.github/quality.yml` Playwright job runs
 * `npm ci` + `npx playwright install` before the spec run, but never
 * `npm run build`. On a fresh CI VM the `js/openconnector-main.js`
 * artefact doesn't exist, so the rendered page loads a 404 script tag
 * and the Vue app never mounts — every selector wait then times out.
 *
 * Locally, the dev container typically mounts a *separate* checkout
 * into `custom_apps/openconnector` and serves that build, so this
 * step is a no-op when the bundle is already present.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. ` +
				`Make sure the docker container is running and reachable.`,
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// BASE_URL is the single authoritative target (see support/baseUrl.ts).
	// The old `?? 'http://localhost:8080'` tail meant a config that failed to
	// carry a baseURL silently logged in to the shared dev container.
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined) ?? BASE_URL
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Hit the login form so the CSRF token + session passphrase land in
	// the browser jar.
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	// Click Submit and wait up to 30s for the URL to leave the login page.
	// Use Promise.race: either the navigation resolves, or a 30s timeout.
	// NC redirects to /apps/dashboard/ on success; on brute-force block
	// it stays on /login with an error message.
	await Promise.all([
		page.waitForURL(url => !/\/login/.test(url), { timeout: 30_000 }).catch(() => null),
		page.locator('button[type="submit"]').first().click(),
	])

	const currentUrl = page.url()
	if (/\/login/.test(currentUrl)) {
		// Check for a visible error message that would explain why login failed.
		const errorText = await page.locator('.warning, .error, [class*="error"]').first().textContent().catch(() => '')
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. ` +
			`Error on page: "${errorText}". ` +
			`Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).`,
		)
	}

	// Seed the nc-vue first-visit overlays as "already seen" for this origin
	// BEFORE any spec loads the app, using the shared helpers rather than a
	// hand-rolled localStorage write.
	//
	// `useSupportDialog` (mode `'server'`, wired by CnAppRoot with
	// `app-id="openconnector"`) treats
	// `localStorage['cn-support-dialog-shown:openconnector'] === '1'` as the
	// authoritative "already seen" signal and never opens the dialog when it is
	// set. Seeding means the support-dialog `modal-mask` never mounts, so it can
	// never intercept the pointer events that `expandNavGroups` / `navTo` rely
	// on. Seeding also decouples the harness from the server preferences
	// endpoint: on a fresh browser with no flag, a non-2xx GET of that endpoint
	// sends the composable down its fail-open catch branch and re-opens the
	// dialog on every load.
	//
	// The app id MUST be named explicitly here. `seedSupportDialogSeen(page,
	// '*')` installs a `Storage.prototype.getItem` shim but deliberately skips
	// writing concrete keys, and a shim lives on the page — it does NOT survive
	// into the `storageState` file this setup persists. Passing 'openconnector'
	// takes the write-through branch, so the flag rides in storageState and is
	// durable for every spec, context and browser in the run.
	//
	// `seedWalkthroughSeen` is included via seedFirstVisitOverlaysSeen even
	// though this app ships no CnWalkthrough — it is inert here and keeps the
	// harness correct if one is ever added.
	await page.goto('/apps/openconnector/')
	await seedFirstVisitOverlaysSeen(page, 'openconnector')

	// Retire Nextcloud's own first-run wizard for this user.
	//
	// The wizard mounts as `#firstrunwizard`, a `[role="dialog"]` carrying
	// `modal-mask--opaque`, and it is the *other* full-screen overlay a fresh
	// instance puts in front of the app — the CnSupportDialog seed above says
	// nothing about it. Its failure mode is the nasty one: it hides nothing that
	// a visibility assertion looks at, so `toBeVisible()` on the page's own
	// buttons keeps passing and only the *click* is intercepted
	// ("subtree intercepts pointer events"). Two specs died exactly that way.
	//
	// Worse, it is itself a `[role="dialog"]`, so a spec that reaches for
	// `getByRole('dialog').first()` after a click that never landed can match the
	// wizard and pass green against the wrong element.
	//
	// `DELETE /apps/firstrunwizard/wizard` is the app's own dismissal route and
	// records the result server-side against this user, so unlike a localStorage
	// seed it holds for every spec, context and browser in the run. Issued from
	// inside the page so the session cookie and CSRF token come along for free.
	// Best-effort: an instance without the wizard app installed simply 404s.
	const wizardStatus = await page.evaluate(async () => {
		try {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? ''
			const res = await fetch('/index.php/apps/firstrunwizard/wizard', {
				method: 'DELETE',
				headers: { requesttoken: token },
			})
			return res.status
		} catch (e) {
			return -1
		}
	})
	if (wizardStatus !== 200 && wizardStatus !== 404) {
		// eslint-disable-next-line no-console
		console.warn(`[playwright globalSetup] first-run wizard dismissal returned ${wizardStatus}; `
			+ 'specs may hit an overlay that blocks clicks without hiding anything.')
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
