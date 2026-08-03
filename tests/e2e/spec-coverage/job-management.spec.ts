/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/job-management/spec.md
 *
 * REQ-JOB-UI-001 (Job Management UI) — real Playwright UI tests covering
 * the Jobs SPA section: list, modal, logs.
 *
 * REQ-JOB-001 and REQ-JOB-002 describe backend job-execution and dry-run
 * internals that carry @e2e exclude and are covered by PHPUnit/Newman.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Assertions use
 * the main content area rather than relying on table cell content.
 */

import { test, expect } from '@playwright/test'
import { appDialog } from '../support/dialogs'
// APP_BASE comes from _helpers.ts, the one place that knows both that the
// router is hash-mode and that the URL needs the `/index.php/` prefix (without
// it, PHP's built-in server on CI 404s the app directory and every assertion
// below runs against a 404 page). This file used to keep a private copy of
// that string that was missing the prefix.
import { APP_BASE } from './_helpers'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'

// ---------------------------------------------------------------------------
// REQ-JOB-UI-001: Job Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-JOB-UI-001: Jobs list page mounts', () => {
	// @e2e job-management::jobs-list-page-mounts-and-shows-content
	test('Jobs index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/jobs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-JOB-UI-001: Add Job modal', () => {
	// @e2e job-management::add-job-button-opens-the-creation-modal
	test('Add Item button on Jobs page opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/jobs`, { waitUntil: 'domcontentloaded' })
		const addBtn = page.getByRole('button', { name: /Add (Item|Job)/i })
		await expect(addBtn, 'Add Item button must be visible on Jobs page').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = appDialog(page)
		await expect(dialog, 'Modal must open after clicking Add Item on Jobs').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-JOB-UI-001: Job logs sub-page', () => {
	// @e2e job-management::job-logs-sub-page-mounts
	test('Job logs page mounts and shows main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/jobs/logs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Jobs OR API — list', () => {
	test('OR returns job objects for the openconnector register', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/job?_limit=10`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})
