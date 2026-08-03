/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * declared in that config runs. The caller used to pass
 * `playwright-test-path: tests/e2e/regression`, which holds no config file, so
 * the lookup fell through to the ROOT `playwright.config.ts` and ran all four
 * of its projects at once:
 *
 *   chromium     — spec-coverage + workflows. CI wants these.
 *   regression   — regression/ + spec-coverage/. CI wants these.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot; it has its own dedicated job which
 *                  invokes it explicitly with `--project docs-capture`.
 *   visual       — pixel-diff baselines. The root config's own header says the
 *                  PNGs are host-font/GPU specific and that "a CI Linux runner
 *                  will not byte-match a dev-container baseline", i.e. it is
 *                  documented as unable to pass here until it rebaselines
 *                  in-CI.
 *
 * That is how one CI run came to execute 243 tests in 1.6 hours, two of the
 * four projects being ones their own documentation says cannot pass on a CI
 * runner. `playwright-test-path: tests/e2e` in the caller makes the workflow's
 * FIRST lookup hit THIS file, which declares exactly one project covering the
 * suites that can pass. The root config is untouched and stays the entry point
 * for local runs, `npm run test:e2e:docs`, `npm run test:regression` and
 * `--project visual`.
 *
 * WHAT RUNS HERE, AND WHY api-direct DOES NOT
 * -------------------------------------------
 * Included: `spec-coverage/**`, `regression/**`, `workflows/**` — every suite
 * that drives the real UI or asserts real end-to-end data movement.
 *
 * Excluded, deliberately:
 *   `visual/**`               — see above.
 *   `docs-screenshots.spec.ts` — has its own job.
 *   `api-direct/**`            — these are HTTP-contract assertions, and the
 *                                same contracts are already asserted by the
 *                                Newman suite (tests/postman/), which the
 *                                caller runs as its own job
 *                                (`enable-newman: true`). Running them here
 *                                too would double-count API coverage inside a
 *                                job whose name promises browser coverage —
 *                                gate-19's "API-direct → Newman" rule. The
 *                                root config records the same decision in its
 *                                top-level `testIgnore`.
 *
 * ⚠️ The exclusion is written as a project-level `testIgnore` and NOT relied
 * upon from a top-level one. Playwright REPLACES rather than merges the two:
 * a project that declares its own `testIgnore` silently loses the top-level
 * list. Everything this config wants excluded is therefore in the one list on
 * the project below, and there is no top-level `testIgnore` to be shadowed.
 *
 * REPORT AND OUTPUT PATHS
 * -----------------------
 * The workflow's upload steps look at BOTH `<app>/playwright-report/` and
 * `<app>/tests/e2e/playwright-report/`, but both uploads use
 * `if-no-files-found: ignore`, so a path mismatch produces an empty artifact
 * instead of an error. The failing run left no `playwright-report` artifact at
 * all and a `playwright-traces` artifact that had picked up committed visual
 * baselines rather than this run's traces. Writing to the app root keeps the
 * report next to what the upload step names first and keeps run output out of
 * the committed `tests/e2e/` tree entirely — see the `.gitignore` entries
 * added alongside this file.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { BASE_URL } from './support/baseUrl'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/visual/**',
				'**/api-direct/**',
			],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
