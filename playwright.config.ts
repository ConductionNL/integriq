/*
 * SPDX-FileCopyrightText: 2026 OpenConnector Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright config for OpenConnector.
 *
 * Scaffolded by /journeydoc-init (ADR-030). OpenConnector previously
 * had no Playwright setup — this is a from-scratch config with two
 * projects:
 *
 *   - `chromium`     — the default regression project. Excludes the
 *                      docs capture spec so PR pipelines don't reshoot
 *                      screenshots on every push. Add regression specs
 *                      under `tests/e2e/` and they run here.
 *   - `docs-capture` — the journeydoc screenshot capture project.
 *                      Opt-in: `npx playwright test --project docs-capture`.
 *                      Output lands in
 *                      `docusaurus/static/screenshots/tutorials/{user,admin}/`.
 *                      Note: openconnector keeps its Docusaurus site in
 *                      `docusaurus/` (sibling of the markdown `docs/`),
 *                      so screenshots go under `docusaurus/static/`
 *                      rather than `docs/static/` — see the capture
 *                      spec for details.
 *
 * Point at a running Nextcloud with PLAYWRIGHT_BASE_URL (or BASE_URL, which is
 * what the shared quality workflow exports in CI). There is no default — see
 * tests/e2e/support/baseUrl.ts for why (the old
 * `NEXTCLOUD_URL || 'http://localhost:8080'` fallback silently targeted the
 * SHARED dev container). `globalSetup` logs in once (admin/admin by default;
 * override with NC_ADMIN_USER / NC_ADMIN_PASS) and persists the session to
 * `tests/e2e/.auth/admin.json`; every spec reuses it via `use.storageState`.
 *
 *   PLAYWRIGHT_BASE_URL=http://localhost:8097 npm run test:e2e
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './tests/e2e/support/baseUrl'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	// Default to a single worker so the serial default (`chromium`) and the
	// docs-capture screenshot project stay deterministic. The chain-E
	// `regression` project opts into 4 parallel workers via the
	// `PLAYWRIGHT_REGRESSION_WORKERS` override (set by `npm run test:regression`
	// → `--workers=4`) to keep its wall time under the 10-minute budget the
	// openconnector-comprehensive-tests spec mandates (REQ-009).
	workers: process.env.PLAYWRIGHT_REGRESSION_WORKERS
		? Number(process.env.PLAYWRIGHT_REGRESSION_WORKERS)
		: 1,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			// NOTE: a project-level testIgnore REPLACES the top-level testIgnore
			// for this project (Playwright does not merge them), so the
			// api-direct exclusion must be repeated here. The api-direct specs
			// are API/HTTP-contract assertions covered by the Newman suite
			// (tests/postman/openconnector.postman_collection.json), NOT real
			// UI-driving Playwright tests — gate-19: API-direct → Newman.
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/regression/**',
				'**/api-direct/**',
				// Visual specs run only under the opt-in `visual` project (GAP-5).
				'**/visual/**',
			],
			use: { ...devices['Desktop Chrome'] },
		},
		// Chain-E regression project — schema-driven page smoke tests for the
		// 24 manifest pages post OR-cutover, the migration round-trip invariant,
		// plus spec coverage tests.
		// Opt-in: npm run test:regression  (runs with --workers=4 per REQ-009),
		//   or: PLAYWRIGHT_REGRESSION_WORKERS=4 npx playwright test --project regression
		// `fullyParallel: true` lets the 4 workers spread tests across files AND
		// within files, keeping wall time under the spec's 10-minute budget.
		{
			name: 'regression',
			testMatch: /(regression|spec-coverage)\/.*\.spec\.ts$/,
			// api-direct specs live under tests/e2e/api-direct/ and are Newman
			// equivalents (HTTP-contract assertions) — never run them in the UI
			// gate. gate-19: API-direct → Newman.
			testIgnore: ['**/api-direct/**'],
			use: { ...devices['Desktop Chrome'] },
			fullyParallel: true,
			retries: process.env.CI ? 2 : 0,
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in
		// `docusaurus/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			testIgnore: [],
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
			timeout: 90_000,
		},
	],

	// API-direct specs are API/HTTP-contract assertions (Newman equivalents),
	// not real UI-driving Playwright tests. They live under tests/e2e/api-direct/
	// for reference but are excluded from the UI test run (gate-19: API-direct →
	// Newman). Each project that needs the exclusion repeats it above, since a
	// project-level testIgnore replaces (does not merge with) this top-level one.
	testIgnore: ['**/api-direct/**'],
})
