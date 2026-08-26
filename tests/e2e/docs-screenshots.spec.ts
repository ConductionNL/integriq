/*
 * SPDX-FileCopyrightText: 2026 Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — integriq.
 *
 * This spec is *not* a regression test — it drives the Integriq UI
 * through the flows documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into
 * `docusaurus/static/screenshots/tutorials/<track>/` for each step the
 * markdown references.
 *
 * Path quirk: integriq's Docusaurus site lives in `docusaurus/`
 * (sibling of `docs/`), not in `docs/` like decidesk / launchpad. The
 * Docusaurus config reads markdown from `../docs` but its static dir
 * is `docusaurus/static/`. Markdown image refs are root-absolute
 * (`/screenshots/tutorials/...`) so the build copies them verbatim.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8097 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Navigation strategy: Integriq's left-nav anchors all carry
 * `href="#"` and bind navigation through a click handler. Rather than
 * fighting click handlers across collapsed parents (multiple "Logs"
 * sub-entries share the same label), the spec navigates by direct URL
 * — `/apps/integriq/sources`, `/apps/integriq/mappings`,
 * `/apps/integriq/synchronizations/contracts`, etc. — which the
 * app's router resolves the same way as a click.
 *
 * Data dependency: Integriq list views render even with zero data
 * (Sources / Endpoints / Mappings / etc. show an empty state). The
 * structural screenshots below capture cleanly on a fresh instance. The
 * flow-detail screenshots (a configured source, a populated sync, a
 * scheduled job) need real objects; until seed data lands those steps
 * fall back to the relevant list/empty-state view, and the markdown
 * pages that reference the as-yet-uncaptured PNGs warn under
 * `onBrokenMarkdownImages: 'warn'` rather than failing the docs build.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import { dismissFirstVisitOverlays } from '@conduction/nextcloud-vue/testing/playwright'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docusaurus',
	'static',
	'screenshots',
	'tutorials',
)
// Note the explicit `/index.php/...` prefix. Integriq's Vue
// router is configured with `base: '/index.php/apps/integriq/'`
// (see `src/router/index.js`), so dropping `/index.php/` makes
// vue-router fall back to the dashboard route on every navigation.
const APP = '/index.php/apps/integriq'

/**
 * Save a viewport screenshot under
 * `docusaurus/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `docusaurus/static/` so Docusaurus copies the PNG into
 * the build root — markdown image refs use `/screenshots/...`
 * (root-absolute).
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

// The local `dismissOverlays` that used to live here is gone in favour of
// `dismissFirstVisitOverlays` from @conduction/nextcloud-vue — the third of
// three places this app had reimplemented overlay dismissal.
//
// It also poked Nextcloud's own `#firstrunwizard`, which the shared helper
// does not cover. That is deliberate rather than a regression: `global-setup`
// retires the wizard through `DELETE /apps/firstrunwizard/wizard`, which
// records server-side against the user and therefore holds for every spec,
// context and browser in the run — strictly better than re-clicking it on
// each navigation.

/** Navigate to an Integriq (or absolute) route and settle. */
async function go(page: Page, route: string): Promise<void> {
	const url =
		route.startsWith('/index.php/')
		|| route.startsWith('/apps/')
		|| route.startsWith('/settings/')
			? route
			: `${APP}${route}`
	await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {
		/* tolerate a 404 — caller decides */
	})
	// ADR-074 rule 4: idle never fires on Nextcloud, so waiting for it only
	// burned a timeout. The 900ms settle below is what actually let the SPA
	// paint before a screenshot.
	await dismissFirstVisitOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view (any of "Add Source",
 * "Add Mapping", …) if the button is present, screenshot it, and close
 * it again. Returns whether the dialog appeared.
 */
async function captureCreateDialog(
	page: Page,
	track: 'user' | 'admin',
	file: string,
	label: RegExp,
): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: label }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {
		/* no dialog */
	})
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// CI guard — this is a screenshot-capture suite, not a regression test. It
// runs against `docs-capture` project only when explicitly opted into via
// `RUN_DOCS_CAPTURE=true` (or invoking `--project docs-capture` locally,
// which still respects this gate). `npx playwright test` without that env
// var will enumerate the project but skip all describes, so PR CI doesn't
// re-shoot tutorial screenshots on every push.
// ---------------------------------------------------------------------------

test.skip(
	!process.env.RUN_DOCS_CAPTURE,
	'docs-capture is opt-in — set RUN_DOCS_CAPTURE=true to refresh tutorial screenshots',
)

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/sources')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/integriq')
	})

	test('U2 add-source', async ({ page }) => {
		// docs/tutorials/user/02-add-source.md
		await go(page, '/sources')
		await shoot(page, 'user', '02-add-source-01.png')
		const had = await captureCreateDialog(
			page,
			'user',
			'02-add-source-02.png',
			/Add Source|Add source|Add/i,
		)
		if (!had) {
			await shoot(page, 'user', '02-add-source-02.png')
		}
		// Steps 3-5 (source detail / test call / source logs) need a real
		// source row; the list view + logs view stand in.
		await go(page, '/sources')
		await shoot(page, 'user', '02-add-source-03.png')
		await shoot(page, 'user', '02-add-source-04.png')
		await go(page, '/sources/logs')
		await shoot(page, 'user', '02-add-source-05.png')
	})

	test('U3 create-mapping', async ({ page }) => {
		// docs/tutorials/user/03-create-mapping.md
		await go(page, '/mappings')
		await shoot(page, 'user', '03-create-mapping-01.png')
		const had = await captureCreateDialog(
			page,
			'user',
			'03-create-mapping-02.png',
			/Add Mapping|Add mapping|Add/i,
		)
		if (!had) {
			await shoot(page, 'user', '03-create-mapping-02.png')
		}
		// Steps 3-5 (mapping/cast/test panes) need a real mapping row;
		// the list view stands in.
		await go(page, '/mappings')
		await shoot(page, 'user', '03-create-mapping-03.png')
		await shoot(page, 'user', '03-create-mapping-04.png')
		await shoot(page, 'user', '03-create-mapping-05.png')
	})

	test('U4 run-synchronization', async ({ page }) => {
		// docs/tutorials/user/04-run-synchronization.md
		await go(page, '/synchronizations')
		await shoot(page, 'user', '04-run-synchronization-01.png')
		const had = await captureCreateDialog(
			page,
			'user',
			'04-run-synchronization-02.png',
			/Add Synchroniz|Add sync|Add/i,
		)
		if (!had) {
			await shoot(page, 'user', '04-run-synchronization-02.png')
		}
		await go(page, '/synchronizations')
		await shoot(page, 'user', '04-run-synchronization-03.png')
		await shoot(page, 'user', '04-run-synchronization-04.png')
		await go(page, '/synchronizations/contracts')
		await shoot(page, 'user', '04-run-synchronization-05.png')
	})

	test('U5 expose-endpoint', async ({ page }) => {
		// docs/tutorials/user/05-expose-endpoint.md
		await go(page, '/endpoints')
		await shoot(page, 'user', '05-expose-endpoint-01.png')
		const had = await captureCreateDialog(
			page,
			'user',
			'05-expose-endpoint-02.png',
			/Add Endpoint|Add endpoint|Add/i,
		)
		if (!had) {
			await shoot(page, 'user', '05-expose-endpoint-02.png')
		}
		await go(page, '/endpoints')
		await shoot(page, 'user', '05-expose-endpoint-03.png')
		await shoot(page, 'user', '05-expose-endpoint-04.png')
		await go(page, '/endpoints/logs')
		await shoot(page, 'user', '05-expose-endpoint-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('A1 import-configuration', async ({ page }) => {
		// docs/tutorials/admin/01-import-configuration.md
		await go(page, '/import')
		await shoot(page, 'admin', '01-import-configuration-01.png')
		await shoot(page, 'admin', '01-import-configuration-02.png')
		await shoot(page, 'admin', '01-import-configuration-03.png')
		// Step 4 — after-import verification — falls back to the Sources
		// list (visible whether import ran or not).
		await go(page, '/sources')
		await shoot(page, 'admin', '01-import-configuration-04.png')
	})

	test('A2 schedule-job', async ({ page }) => {
		// docs/tutorials/admin/02-schedule-job.md
		await go(page, '/jobs')
		await shoot(page, 'admin', '02-schedule-job-01.png')
		const had = await captureCreateDialog(
			page,
			'admin',
			'02-schedule-job-02.png',
			/Add Job|Add job|Add/i,
		)
		if (!had) {
			await shoot(page, 'admin', '02-schedule-job-02.png')
		}
		await go(page, '/jobs')
		await shoot(page, 'admin', '02-schedule-job-03.png')
		await shoot(page, 'admin', '02-schedule-job-04.png')
		await go(page, '/jobs/logs')
		await shoot(page, 'admin', '02-schedule-job-05.png')
	})

	test('A3 review-logs', async ({ page }) => {
		// docs/tutorials/admin/03-review-logs.md
		await go(page, '/')
		await shoot(page, 'admin', '03-review-logs-01.png')
		await go(page, '/sources/logs')
		await shoot(page, 'admin', '03-review-logs-02.png')
		await shoot(page, 'admin', '03-review-logs-03.png')
		await go(page, '/synchronizations/logs')
		await shoot(page, 'admin', '03-review-logs-04.png')
		await go(page, '/jobs/logs')
		await shoot(page, 'admin', '03-review-logs-05.png')
	})
})
