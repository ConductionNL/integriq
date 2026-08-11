/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: manifest-driven page smoke test.
 *
 * Most openconnector pages render via nc-vue's built-in `CnIndexPage` /
 * `CnDetailPage` / `CnLogsPage` / `CnDashboardPage`, with their CRUD wired
 * against OR's `/api/objects/openconnector/{schema}/*` routes. Ten pages are
 * `type: custom` and render a bespoke component named by the manifest.
 *
 * This spec navigates to EVERY manifest page route and asserts:
 *   - the SPA shell mounts (`#app-content` is present)
 *   - no console errors fire during initial mount
 *   - the rendered page contains either a list/grid/header (data path)
 *     or an empty-state message (cold-start path) — either is acceptable
 *
 * Read-only: these tests do NOT create / mutate data; they validate the
 * pages LOAD against a running container. Per-page CRUD flows live in
 * separate specs (sources-crud.spec.ts, etc.).
 *
 * WHY THE PAGE TABLE IS WRITTEN OUT AND THEN GUARDED
 * --------------------------------------------------
 * `MANIFEST_PAGES` used to be a hand-maintained list with a comment claiming
 * it held "24 manifest pages". The manifest has since grown to 35 and nothing
 * compared the two, so ten pages — every `type: custom` screen added after the
 * list was written, plus `Flows` and `Traces` — were never navigated to by any
 * test. The list had also gone stale in the other direction: it still drove
 * `/import`, a route the manifest no longer declares, and passed, because the
 * router silently lands an unknown hash on the dashboard and the dashboard
 * mounts fine. A stale table does not fail; it quietly tests the wrong page.
 *
 * The table is still literal, because the component names have to be readable
 * here (both for a human debugging a failure and for gate-26 visual-coverage,
 * which asks whether any e2e test drives a given page component). What is new
 * is `manifest page table is complete and current` below: it reads
 * `src/manifest.json` and asserts the table matches it exactly — id, route,
 * type and component. Add a page to the manifest without adding it here and
 * that test fails naming the page.
 *
 * Cross-ref:
 * - openspec/specs/openconnector-frontend-vue-rewrite/spec.md
 * - src/manifest.json
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

/*
 * SCENARIOS THIS FILE PROVES.
 *
 * Each tag below was checked by reading the scenario's GIVEN/WHEN/THEN in the
 * spec and the assertion in this file side by side; a tag is here only when the
 * assertions establish the scenario's THEN, not merely touch its subject. The
 * page-mount tags cover the loop at `manifest pages — schema-driven render`,
 * which drives every manifest route and asserts the shell mounted, that content
 * rendered inside `#app-content`, and that no console errors fired.
 *
 * @e2e openconnector-app-manifest::manifest-file-present-at-canonical-path
 * @e2e openconnector-app-manifest::version-field-is-valid-semver
 * @e2e openconnector-app-manifest::dashboard-page-type-is-dashboard
 * @e2e openconnector-app-manifest::log-pages-use-type-logs
 * @e2e openconnector-app-manifest::detail-pages-carry-id-parameter-in-route
 * @e2e approval-workflow::approvals-list-page-mounts-and-shows-content
 * @e2e openconnector-comprehensive-tests::endpointsspects-page-loads
 *
 * NOT tagged here, deliberately, though this file touches their subject:
 *   openconnector-app-manifest::schema-field-is-present-and-correct — the
 *     scenario demands the $schema value EQUAL the full published URL; the
 *     assertion below only matches the filename suffix.
 *   flow-orchestration::flows-index-page-mounts-and-lists-flows — the mount is
 *     proven, but the scenario also requires each flow's name, enabled state
 *     and last-run status to be shown, which nothing here asserts.
 *   openconnector-direct-or-usage::dashboard-page-uses-declarative-manifest-widgets-not-the-deleted-controller
 *     — the manifest type and the mount are proven; that widget counts resolve
 *     via dataSource blocks against OR's aggregate endpoint is not.
 */

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

/** One manifest page, transcribed verbatim from src/manifest.json. */
type ManifestPage = {
	/** `id` in the manifest. */
	id: string
	/** `route` in the manifest, parameter placeholders included. */
	route: string
	/** `type` in the manifest. */
	type: string
	/** `component` for `type: custom` pages; absent for renderer-drawn types. */
	component?: string
}

/**
 * All 35 manifest pages. Kept in manifest order so a diff against
 * `src/manifest.json` reads straight down.
 *
 * Guarded by `manifest page table is complete and current` — do not edit this
 * without editing the manifest, or vice versa.
 */
const MANIFEST_PAGES: ManifestPage[] = [
	{ id: 'FeaturesRoadmap',          route: '/features-roadmap',          type: 'roadmap' },
	{ id: 'Dashboard',                route: '/',                          type: 'dashboard' },
	{ id: 'Sources',                  route: '/sources',                   type: 'index' },
	{ id: 'SourceDetail',             route: '/sources/:id',               type: 'detail' },
	{ id: 'SourceLogs',               route: '/sources/logs',              type: 'logs' },
	{ id: 'Endpoints',                route: '/endpoints',                 type: 'index' },
	{ id: 'EndpointDetail',           route: '/endpoints/:id',             type: 'detail' },
	{ id: 'EndpointLogs',             route: '/endpoints/logs',            type: 'logs' },
	{ id: 'Consumers',                route: '/consumers',                 type: 'index' },
	{ id: 'ConsumerDetail',           route: '/consumers/:id',             type: 'detail' },
	{ id: 'ApiProducts',              route: '/products',                  type: 'index' },
	{ id: 'ApiProductDetail',         route: '/products/:id',              type: 'custom', component: 'ApiProductDetail' },
	{ id: 'Webhooks',                 route: '/webhooks',                  type: 'index' },
	{ id: 'NotificatiesAbonnementen', route: '/notificaties/abonnementen', type: 'custom', component: 'NotificatiesAbonnementenPage' },
	{ id: 'Jobs',                     route: '/jobs',                      type: 'index' },
	{ id: 'JobLogs',                  route: '/jobs/logs',                 type: 'logs' },
	{ id: 'Mappings',                 route: '/mappings',                  type: 'index' },
	{ id: 'MappingDetail',            route: '/mappings/:id',              type: 'custom', component: 'MappingDetailPage' },
	{ id: 'Rules',                    route: '/rules',                     type: 'index' },
	{ id: 'RuleDetail',               route: '/rules/:id',                 type: 'custom', component: 'RuleDetailPage' },
	{ id: 'Synchronizations',         route: '/synchronizations',          type: 'index' },
	{ id: 'SynchronizationContracts', route: '/synchronizations/contracts', type: 'index' },
	{ id: 'SynchronizationLogs',      route: '/synchronizations/logs',     type: 'logs' },
	{ id: 'SynchronizationDetail',    route: '/synchronizations/:id',      type: 'custom', component: 'SynchronizationDetailPage' },
	{ id: 'CloudEvents',              route: '/cloud-events/events',       type: 'index' },
	{ id: 'CloudEventDetail',         route: '/cloud-events/events/:id',   type: 'detail' },
	{ id: 'CloudEventLogs',           route: '/cloud-events/logs',         type: 'logs' },
	{ id: 'Approvals',                route: '/approvals',                 type: 'custom', component: 'ApprovalsIndex' },
	{ id: 'Flows',                    route: '/flows',                     type: 'index' },
	{ id: 'FlowDetail',               route: '/flows/:id',                 type: 'custom', component: 'FlowDetailPage' },
	{ id: 'ApprovalDetail',           route: '/approvals/:id',             type: 'custom', component: 'ApprovalDetail' },
	{ id: 'Traces',                   route: '/traces',                    type: 'logs' },
	{ id: 'TraceDetail',              route: '/traces/:id',                type: 'custom', component: 'TraceDetailPage' },
	{ id: 'Store',                    route: '/store',                     type: 'index' },
	{ id: 'DeadLetters',              route: '/dead-letters',              type: 'custom', component: 'DeadLettersPage' },
]

/**
 * The hash a browser should be sent to for a page.
 *
 * Detail routes carry a `:id` placeholder. We drive them with a deliberately
 * absent id so the page component mounts against a cold store — that is the
 * shell-mount property this smoke test is about, and it needs no fixture.
 */
function navigableRoute(page: ManifestPage): string {
	return page.route.replace(/:[A-Za-z_][\w]*/g, '__nonexistent__')
}

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
	// /api/settings was removed in the chain-C OR-cutover (replaced by
	// OR's /api/settings/* surface — see appinfo/routes.php comment).
	// The SPA still pings the old endpoint at every page mount and logs
	// the 404; that's a stale fetch path scheduled for cleanup, not a
	// page-mount regression. Filter it from the strict console-error
	// gate until the SPA is updated.
	/Error fetching OpenConnector settings/i,
	/Failed to load resource:.*Not Found/i,
	// The user_status app returns HTTP 500 on this dev instance due to a
	// PostgreSQL collation version mismatch (database was created with
	// collation 2.41, OS provides 2.36). This is a pre-existing platform
	// issue unrelated to openconnector — filter it globally.
	/Failed to load user status/i,
	/user_status/i,
	// Generic 500 resource failures that accompany the user_status 500.
	/the server responded with a status of 500/i,
	// Detail pages are navigated to with `__nonexistent__` as the object ID
	// so we can smoke-test that the SPA shell mounts. CnDetailPage will
	// always log an "Error fetching {schema}/__nonexistent__" console error
	// because the object does not exist in OR — that is expected for the
	// smoke route and must not fail the console-gate.
	/Error fetching .+\/__nonexistent__/i,
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
		const label = pg.component ? `${pg.id} (${pg.component})` : pg.id
		test(`[${pg.type}] ${label} mounts at ${pg.route}`, async ({ page }) => {
			const { errors } = attachConsoleSpy(page)

			const root = await rootUrl(page)
			// The in-app router runs in HASH mode (src/main.js `mode: 'hash'`),
			// so the route must be a hash fragment (`/apps/openconnector/#/sources`).
			// A path-form deep-link (`/apps/openconnector/sources`) is ignored by
			// the router and silently lands on the dashboard, so each page would
			// be smoke-tested against the dashboard rather than its own component.
			// Use `domcontentloaded` rather than `networkidle` — NC's
			// notification poll keeps the network busy indefinitely, so
			// `networkidle` always times out. The SPA mounts after DOM
			// ready, and the `#app-content` + content-length assertions
			// below verify the mount completed.
			await page.goto(`${root}/#${navigableRoute(pg)}`, { waitUntil: 'domcontentloaded', timeout: 30_000 })

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

	function readManifest(): Record<string, any> {
		const manifestPath = require('path').resolve(__dirname, '../../../src/manifest.json')
		return JSON.parse(require('fs').readFileSync(manifestPath, 'utf-8'))
	}

	test('src/manifest.json validates against v2 schema', async () => {
		// The canonical path and the parse are asserted EXPLICITLY rather than
		// left to `readManifest()` throwing. A throw does fail the test, but it
		// fails it as an error with no statement of intent — and a reader
		// checking whether "the manifest exists and parses" is covered cannot
		// see an assertion that isn't written down.
		const manifestPath = require('path').resolve(__dirname, '../../../src/manifest.json')
		expect(require('fs').existsSync(manifestPath), `manifest.json must exist at ${manifestPath}`).toBe(true)
		expect(require('fs').statSync(manifestPath).isFile(), 'manifest.json must be a regular file').toBe(true)
		expect(() => JSON.parse(require('fs').readFileSync(manifestPath, 'utf-8')),
			'manifest.json must parse as valid JSON with no syntax errors').not.toThrow()

		const m = readManifest()

		expect(m.$schema, 'manifest declares a $schema URL').toMatch(/app-manifest(-v2)?\.schema\.json$/)
		expect(m.version, 'manifest has a semver version').toMatch(/^\d+\.\d+\.\d+$/)
		expect(Array.isArray(m.menu), 'menu is an array').toBe(true)
		expect(Array.isArray(m.pages), 'pages is an array').toBe(true)

		// Count NAVIGABLE entries, not top-level array slots.
		//
		// This assertion used to read `m.menu.length >= 13` against a flat
		// menu. The manifest has since grouped its entries — today the array
		// holds 7 slots, two of which (`ConnectionsGroup`, `AutomationGroup`)
		// carry 6 and 10 `children` — so the flat count collapsed to 7 and the
		// check failed while the menu had in fact GROWN, from ~13 destinations
		// to 21. The number being defended is "how many places can a user
		// navigate to", and that is what this now counts.
		const countNavEntries = (entries: Array<Record<string, unknown>>): number =>
			entries.reduce((total, entry) => {
				const children = entry.children
				return total + 1 + (Array.isArray(children) ? countNavEntries(children as Array<Record<string, unknown>>) : 0)
			}, 0)

		expect(countNavEntries(m.menu), 'menu exposes at least 13 navigable entries (groups + children)')
			.toBeGreaterThanOrEqual(13)
	})

	/**
	 * THE ANTI-STALENESS GUARD.
	 *
	 * `MANIFEST_PAGES` is the list this file actually navigates. If it drifts
	 * from the manifest, pages stop being tested WITHOUT anything going red —
	 * which is exactly what happened: ten pages were never driven, and one
	 * entry (`/import`) pointed at a route the manifest had dropped.
	 *
	 * Comparing id + route + type + component in both directions is what makes
	 * "every page is smoke-tested" a checked claim rather than a comment.
	 */
	test('manifest page table is complete and current', async () => {
		const m = readManifest()

		const fromManifest = (m.pages as Array<Record<string, any>>).map((p) => ({
			id: String(p.id),
			route: String(p.route),
			type: String(p.type),
			component: p.type === 'custom' ? String(p.component) : undefined,
		}))

		// POSITIVE CONTROL: a comparison against an empty manifest would pass
		// vacuously if the table were also empty.
		expect(fromManifest.length, 'the manifest must declare pages for this guard to mean anything')
			.toBeGreaterThan(20)

		const key = (p: ManifestPage) => `${p.id}|${p.route}|${p.type}|${p.component ?? ''}`
		const manifestKeys = fromManifest.map(key).sort()
		const tableKeys = MANIFEST_PAGES.map(key).sort()

		const missingFromTable = manifestKeys.filter((k) => !tableKeys.includes(k))
		const staleInTable = tableKeys.filter((k) => !manifestKeys.includes(k))

		expect(missingFromTable, 'manifest pages that NO test in this file navigates to — add them to MANIFEST_PAGES')
			.toEqual([])
		expect(staleInTable, 'MANIFEST_PAGES entries the manifest no longer declares — these navigate to a dead route and pass anyway')
			.toEqual([])
	})

	test('every page uses a standard type or has a _note justifying custom', async () => {
		const m = readManifest()
		// Standard nc-vue page types (ADR-030). `roadmap` is a recognised
		// extension type used by FeaturesRoadmap.
		const STANDARD = new Set(['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'form', 'wiki', 'map', 'roadmap'])
		for (const p of m.pages) {
			if (p.type === 'custom') {
				expect(p._note, `page ${p.id} has type:custom — must include _note justifying it (chain D2 spec REQ "All manifest pages MUST use a standard page type")`).toBeTruthy()
			} else {
				expect(STANDARD.has(p.type), `page ${p.id} has unknown type ${p.type}`).toBe(true)
			}
		}
	})

	test('every index/detail/logs page has config.register and config.schema', async () => {
		const m = readManifest()
		for (const p of m.pages) {
			if (['index', 'detail', 'logs'].includes(p.type)) {
				expect(p.config?.register, `${p.id} (type:${p.type}) is missing config.register`).toBe('openconnector')
				expect(p.config?.schema, `${p.id} (type:${p.type}) is missing config.schema`).toBeTruthy()
			}
		}
	})

})
