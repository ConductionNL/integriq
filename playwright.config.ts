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
 * Point at a running Nextcloud with NEXTCLOUD_URL (default
 * http://localhost:8080). `globalSetup` logs in once (admin/admin by
 * default; override with NC_ADMIN_USER / NC_ADMIN_PASS) and persists
 * the session to `tests/e2e/.auth/admin.json`; every spec reuses it via
 * `use.storageState`.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 30_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/regression/**'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Chain-E regression project — schema-driven page smoke tests for the
		// 24 manifest pages post OR-cutover. Opt-in:
		//   npm run test:regression  (or: npx playwright test --project regression)
		// 4 parallel workers per chain-E spec requirement.
		{
			name: 'regression',
			testMatch: /regression\/.*\.spec\.ts$/,
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
	],
})
