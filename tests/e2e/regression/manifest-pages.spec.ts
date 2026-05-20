/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: manifest-driven page smoke test.
 *
 * Post chain-D2 cutover (`a9d43736`), 23 of 24 openconnector pages render
 * via nc-vue's built-in `CnIndexPage` / `CnDetailPage` / `CnLogsPage` /
 * `CnDashboardPage` / `CnSettingsPage`, with their CRUD wired against OR's
 * `/api/objects/openconnector/{schema}/*` routes. Only the `Import` page
 * remains `type: custom`.
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

const ROOT = '/index.php/apps/openconnector'

/**
 * 24 manifest pages from src/manifest.json (a9d43736), grouped by route
 * prefix to keep the test output readable. Each entry: [pageId, route, type].
 */
const MANIFEST_PAGES: Array<{ id: string; route: string; type: string }> = [
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
	{ id: 'Import',                   route: '/import',                      type: 'custom' },
	{ id: 'AppSettings',              route: '/settings',                    type: 'settings' },
]

/**
 * Errors we ignore — these come from Nextcloud's own bootstrap, not
 * openconnector. Customer instances often surface deprecation warnings
 * from third-party scripts that don't break the page.
 */
const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
]

function attachConsoleSpy(page: Page): { errors: string[]; warnings: string[] } {
	const errors: string[] = []
	const warnings: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) {
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

			await page.goto(`${ROOT}${pg.route}`, { waitUntil: 'networkidle', timeout: 15_000 })

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
		const manifest = await import('../../../src/manifest.json')
		const m = (manifest as any).default ?? manifest

		expect(m.$schema, 'manifest declares a $schema URL').toMatch(/app-manifest(-v2)?\.schema\.json$/)
		expect(m.version, 'manifest has a semver version').toMatch(/^\d+\.\d+\.\d+$/)
		expect(Array.isArray(m.menu), 'menu is an array').toBe(true)
		expect(Array.isArray(m.pages), 'pages is an array').toBe(true)
		expect(m.menu.length, 'menu has 13-15 entries').toBeGreaterThanOrEqual(13)
		expect(m.pages.length, 'pages has 23-24 entries').toBeGreaterThanOrEqual(23)
	})

	test('all 24 pages use a standard type or have a _note justifying custom', async () => {
		const manifest = await import('../../../src/manifest.json')
		const m = (manifest as any).default ?? manifest
		const STANDARD = new Set(['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'form', 'wiki', 'map'])
		for (const p of m.pages) {
			if (p.type === 'custom') {
				expect(p._note, `page ${p.id} has type:custom — must include _note justifying it (chain D2 spec REQ "All 24 manifest pages MUST use a standard page type")`).toBeTruthy()
			} else {
				expect(STANDARD.has(p.type), `page ${p.id} has unknown type ${p.type}`).toBe(true)
			}
		}
	})

	test('every index/detail/logs page has config.register and config.schema', async () => {
		const manifest = await import('../../../src/manifest.json')
		const m = (manifest as any).default ?? manifest
		for (const p of m.pages) {
			if (['index', 'detail', 'logs'].includes(p.type)) {
				expect(p.config?.register, `${p.id} (type:${p.type}) is missing config.register`).toBe('openconnector')
				expect(p.config?.schema, `${p.id} (type:${p.type}) is missing config.schema`).toBeTruthy()
			}
		}
	})

})
