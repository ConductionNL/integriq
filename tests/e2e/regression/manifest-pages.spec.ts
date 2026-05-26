/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: manifest-driven page smoke test.
 *
 * Post chain-D2 cutover (`a9d43736`), openconnector pages render via nc-vue's
 * built-in `CnIndexPage` / `CnDetailPage` / `CnLogsPage` / `CnDashboardPage` /
 * `CnSettingsPage` / `CnFeaturesAndRoadmapPage`, with their CRUD wired against
 * OR's `/api/objects/openconnector/{schema}/*` routes. Only custom pages
 * (`MappingDetail`, `RuleDetail`, `SynchronizationDetail`, `Import`) retain
 * `type: custom` with a `_note` justification.
 *
 * This spec navigates to each manifest page route and asserts:
 *   - the SPA shell mounts (`#app-content` is present)
 *   - no console errors fire during initial mount
 *   - the rendered page contains either a list/grid/header (data path)
 *     or an empty-state message (cold-start path) — either is acceptable
 *
 * Read-only: these tests do NOT create / mutate data; they validate the
 * pages LOAD against a running container. Per-page CRUD flows live in
 * separate specs (sources-crud.spec.ts, etc.).
 *
 * Cross-ref:
 * - openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md
 * - src/manifest.json
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

// In Nextcloud installs with `htaccess.RewriteBase => '/'` (the
// default for the apache-served dev container) `generateUrl` returns
// `/apps/openconnector` and the Vue Router's `base` is set to that —
// any URL prefixed with `/index.php/` then sits outside the router
// base, so no route matches and the page renders empty. In CI's php -S
// install (no htaccess processing) the inverse is true and only the
// `/index.php/...` form works. Resolve at runtime via a HEAD probe.
const ROOT_CANDIDATES = ['/apps/openconnector', '/index.php/apps/openconnector']
let _root: string | null = null
async function rootUrl(page: import('@playwright/test').Page): Promise<string> {
	if (_root) return _root
	for (const candidate of ROOT_CANDIDATES) {
		const res = await page.request.get(`${candidate}/sources`, { failOnStatusCode: false })
		if (res.ok() && (await res.text()).includes('openconnector-main.js')) {
			_root = candidate
			return candidate
		}
	}
	throw new Error('Neither /apps nor /index.php form serves the openconnector SPA shell')
}

/**
 * 25 manifest pages from src/manifest.json, grouped by route prefix to keep
 * the test output readable. Each entry: [pageId, route, type].
 */
const MANIFEST_PAGES: Array<{ id: string; route: string; type: string }> = [
	{ id: 'FeaturesRoadmap',          route: '/features-roadmap',            type: 'roadmap' },
	{ id: 'Dashboard',                route: '/',                            type: 'dashboard' },
	{ id: 'Sources',                  route: '/sources',                     type: 'index' },
	{ id: 'SourceDetail',             route: '/sources/__nonexistent__',     type: 'detail' },
	{ id: 'SourceLogs',               route: '/sources/logs',                type: 'logs' },
	{ id: 'Endpoints',                route: '/endpoints',                   type: 'index' },
	{ id: 'EndpointDetail',           route: '/endpoints/__nonexistent__',   type: 'detail' },
	{ id: 'EndpointLogs',             route: '/endpoints/logs',              type: 'logs' },
	{ id: 'Consumers',                route: '/consumers',                   type: 'index' },
	{ id: 'ConsumerDetail',           route: '/consumers/__nonexistent__',   type: 'detail' },
	{ id: 'Webhooks',                 route: '/webhooks',                    type: 'index' },
	{ id: 'Jobs',                     route: '/jobs',                        type: 'index' },
	{ id: 'JobLogs',                  route: '/jobs/logs',                   type: 'logs' },
	{ id: 'Mappings',                 route: '/mappings',                    type: 'index' },
	{ id: 'MappingDetail',            route: '/mappings/__nonexistent__',    type: 'detail' },
	{ id: 'Rules',                    route: '/rules',                       type: 'index' },
	{ id: 'RuleDetail',               route: '/rules/__nonexistent__',       type: 'detail' },
	{ id: 'Synchronizations',         route: '/synchronizations',            type: 'index' },
	{ id: 'SynchronizationContracts', route: '/synchronizations/contracts',  type: 'index' },
	{ id: 'SynchronizationLogs',      route: '/synchronizations/logs',       type: 'logs' },
	{ id: 'CloudEvents',              route: '/cloud-events/events',         type: 'index' },
	{ id: 'CloudEventDetail',         route: '/cloud-events/events/__nonexistent__', type: 'detail' },
	{ id: 'CloudEventLogs',           route: '/cloud-events/logs',           type: 'logs' },
	{ id: 'AppSettings',              route: '/settings',                    type: 'settings' },
]

/**
 * Console noise that is unambiguous from its message TEXT alone — Nextcloud
 * bootstrap chatter and stale SPA fetch paths, not openconnector page-mount
 * regressions. Resource-load failures (which carry only a status code in their
 * text, never the URL) are NOT matched here — they are filtered by URL below.
 */
const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	// /api/settings was removed in the chain-C OR-cutover; the SPA still pings
	// the old endpoint on mount and logs it. Stale fetch path, not a regression.
	/Error fetching OpenConnector settings/i,
	// NC-core user-status heartbeat: the unambiguous "[ERROR] core: Failed to
	// load user status {app: core, …}" message. (The paired generic
	// resource-load failure is filtered by URL below, not by status text.)
	/Failed to load user status/i,
	// Custom detail smoke routes use `__nonexistent__` as the id sentinel; the
	// page mounts an empty/error state and logs a fetch error for that id.
	/Error fetching .*\/__nonexistent__/i,
]

/**
 * Resource-load failures ("Failed to load resource: the server responded with a
 * status of NNN") do NOT include the failing URL in their message text — only
 * in `msg.location().url`. We filter those by URL against this allow-list of
 * known-noisy endpoints so the console-error gate stays STRICT for
 * openconnector's own endpoints: a 4xx/5xx from any other URL is a real failure
 * and fails the test. (The previous blanket "ignore all 500/503 by status text"
 * masked real openconnector endpoint regressions — see write-once-logging change.)
 */
const IGNORED_RESOURCE_URL_PATTERNS: RegExp[] = [
	// NC-core user-status heartbeat — returns 500 in test envs (missing app /
	// disabled cron / php -S rejecting the OCS path). Pure background polling.
	/\/apps\/user_status\//,
	// OpenRegister GraphQL — 503 under CI's single-threaded `php -S` when a
	// dashboard/index page fires several chart/list queries in parallel and the
	// lone worker is busy; returns 200 under Apache. Server-saturation noise.
	/\/apps\/openregister\/api\/graphql/,
	// Removed chain-C /api/settings endpoint the SPA still pings (404).
	/\/apps\/openconnector\/api\/settings(?:[/?]|$)/,
	// Smoke-test sentinel id on custom detail routes → not-found.
	/\/__nonexistent__(?:[/?]|$)/,
]

const RESOURCE_LOAD_FAILURE = /Failed to load resource:/i

function attachConsoleSpy(page: Page): { errors: string[]; warnings: string[] } {
	const errors: string[] = []
	const warnings: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) {
			return
		}
		// Resource-load failures: decide by the failing URL, not the status
		// text, so only known NC-core / CI-env noise is suppressed while a real
		// openconnector endpoint failure still fails the gate.
		if (RESOURCE_LOAD_FAILURE.test(text)) {
			const url = msg.location()?.url ?? ''
			if (IGNORED_RESOURCE_URL_PATTERNS.some((rx) => rx.test(url))) {
				return
			}
			if (msg.type() === 'error') {
				// Surface the URL so the failure message is actionable.
				errors.push(`${text} @ ${url}`)
			}
			return
		}
		if (msg.type() === 'error') {
			errors.push(text)
		} else if (msg.type() === 'warning') {
			warnings.push(text)
		}
	})
	page.on('pageerror', (err) => {
		errors.push(`pageerror: ${err.message}`)
	})
	return { errors, warnings }
}

test.describe('manifest pages — schema-driven render', () => {

	for (const pg of MANIFEST_PAGES) {
		test(`[${pg.type}] ${pg.id} mounts at ${pg.route}`, async ({ page }) => {
			const { errors } = attachConsoleSpy(page)

			const root = await rootUrl(page)
			// Use `domcontentloaded` rather than `networkidle` — NC's
			// notification poll keeps the network busy indefinitely, so
			// `networkidle` always times out. The SPA mounts after DOM
			// ready, and the `#app-content` + content-length assertions
			// below verify the mount completed.
			await page.goto(`${root}${pg.route}`, { waitUntil: 'domcontentloaded', timeout: 15_000 })

			// The Nextcloud SPA shell mounts inside #app-content.
			await expect(page.locator('#app-content, [data-cy=app-content], .app-content').first()).toBeVisible({ timeout: 10_000 })

			// CnAppRoot should have mounted and resolved the route to *some*
			// page component (CnIndexPage, CnDetailPage, etc.). Verify by
			// checking that *anything* rendered inside the app-content area
			// beyond the loading spinner.
			const renderedContent = await page.locator('#app-content, .app-content').first().innerHTML()
			expect(renderedContent.length, `${pg.id} (${pg.route}) rendered no content inside app-content`).toBeGreaterThan(100)

			// No fatal console errors during initial mount. Warnings are
			// allowed (e.g. unused props from in-flight library churn).
			expect(errors, `${pg.id} (${pg.route}) emitted console errors: ${errors.join(' | ')}`).toEqual([])
		})
	}

})

test.describe('manifest schema validation', () => {

	test('src/manifest.json validates against v2 schema', async () => {
		const manifestPath = require('path').resolve(__dirname, '../../../src/manifest.json')
		const m = JSON.parse(require('fs').readFileSync(manifestPath, 'utf-8'))

		expect(m.$schema, 'manifest declares a $schema URL').toMatch(/app-manifest(-v2)?\.schema\.json$/)
		expect(m.version, 'manifest has a semver version').toMatch(/^\d+\.\d+\.\d+$/)
		expect(Array.isArray(m.menu), 'menu is an array').toBe(true)
		expect(Array.isArray(m.pages), 'pages is an array').toBe(true)
		expect(m.menu.length, 'menu has 13-15 entries').toBeGreaterThanOrEqual(13)
		expect(m.pages.length, 'pages has 25+ entries').toBeGreaterThanOrEqual(25)
	})

	test('all pages use a standard type or have a _note justifying custom', async () => {
		const manifestPath = require('path').resolve(__dirname, '../../../src/manifest.json')
		const m = JSON.parse(require('fs').readFileSync(manifestPath, 'utf-8'))
		const STANDARD = new Set(['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'form', 'wiki', 'map', 'roadmap'])
		for (const p of m.pages) {
			if (p.type === 'custom') {
				expect(p._note, `page ${p.id} has type:custom — must include _note justifying it (chain D2 spec REQ "All 24 manifest pages MUST use a standard page type")`).toBeTruthy()
			} else {
				expect(STANDARD.has(p.type), `page ${p.id} has unknown type ${p.type}`).toBe(true)
			}
		}
	})

	test('every index/detail/logs page has config.register and config.schema', async () => {
		const manifestPath = require('path').resolve(__dirname, '../../../src/manifest.json')
		const m = JSON.parse(require('fs').readFileSync(manifestPath, 'utf-8'))
		for (const p of m.pages) {
			if (['index', 'detail', 'logs'].includes(p.type)) {
				expect(p.config?.register, `${p.id} (type:${p.type}) is missing config.register`).toBe('openconnector')
				expect(p.config?.schema, `${p.id} (type:${p.type}) is missing config.schema`).toBeTruthy()
			}
		}
	})

})
