/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/synchronization-engine/spec.md
 *
 * REQ-UI-001 (Synchronization Management UI) — real Playwright UI tests
 * covering the Synchronizations SPA section: list, modal, contracts, logs.
 *
 * REQ-001 through REQ-005 describe backend sync engine internals (97 methods)
 * that carry @e2e exclude and are covered by PHPUnit/Newman.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known issue #996: Table view renders all cells as "—". Assertions avoid
 * relying on table cell content.
 */

import { test, expect } from '@playwright/test'
import { appDialog } from '../support/dialogs'
// APP_BASE comes from _helpers.ts, the one place that knows both that the
// router is hash-mode and that the URL needs the `/index.php/` prefix (without
// it, PHP's built-in server on CI 404s the app directory and every assertion
// below runs against a 404 page). This file used to keep a private copy of
// that string that was missing the prefix.
import { APP_BASE } from './_helpers'


// ---------------------------------------------------------------------------
// REQ-UI-001: Synchronization Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-UI-001: Synchronizations list page mounts', () => {
	// @e2e synchronization-engine::synchronizations-list-page-mounts-and-shows-content
	test('Synchronizations index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/synchronizations`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-UI-001: Add Synchronization modal', () => {
	// @e2e synchronization-engine::add-synchronization-button-opens-the-creation-modal
	test('Add Synchronization button opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/synchronizations`, { waitUntil: 'networkidle' })
		const addBtn = page.getByRole('button', { name: 'Add Synchronization' })
		await expect(addBtn, 'Add Synchronization button must be visible').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = appDialog(page)
		await expect(dialog, 'Modal must open after clicking Add Synchronization').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-UI-001: Synchronization contracts sub-page', () => {
	// @e2e synchronization-engine::synchronization-contracts-sub-page-mounts
	test('Synchronization contracts page mounts and shows main content', async ({ page }) => {
		await page.goto(`${APP_BASE}/synchronizations/contracts`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

test.describe('REQ-UI-001: Synchronization logs sub-page', () => {
	// @e2e synchronization-engine::synchronization-logs-sub-page-mounts
	test('Synchronization logs page mounts and shows main content', async ({ page }) => {
		await page.goto(`${APP_BASE}/synchronizations/logs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

// ---------------------------------------------------------------------------
// HTTP-contract assertions for the synchronization OR list + run surfaces are
// API-direct (no @e2e tag) and have been relocated to
// tests/e2e/api-direct/synchronization-engine.api.spec.ts (Newman-equivalent,
// excluded from the gate-19 UI run). gate-19: API-direct → Newman.
// ---------------------------------------------------------------------------
