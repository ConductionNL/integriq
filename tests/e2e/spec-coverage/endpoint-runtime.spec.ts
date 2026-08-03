/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/endpoint-runtime/spec.md
 *
 * REQ-EP-UI-001 (Endpoint Management UI) — real Playwright UI tests covering
 * the Endpoints SPA section: list, modal, detail, logs.
 *
 * REQ-EP-001 through REQ-EP-005 describe backend dispatch/cache/normalisation
 * internals that are covered by PHPUnit + Newman. Those scenarios carry
 * @e2e exclude in the spec. HTTP surface helpers are retained without @e2e tags.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
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
// REQ-EP-UI-001: Endpoint Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-EP-UI-001: Endpoints list page mounts', () => {
	// @e2e endpoint-runtime::endpoints-list-page-mounts-and-shows-navigation-item
	test('Endpoints index page renders inside app-content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/endpoints`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-EP-UI-001: Add Endpoint modal', () => {
	// @e2e endpoint-runtime::add-endpoint-button-opens-the-creation-modal
	test('Add Endpoint button opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/endpoints`, { waitUntil: 'networkidle' })
		// Wait for Vue to mount the list view
		const addBtn = page.getByRole('button', { name: 'Add Endpoint' })
		await expect(addBtn, 'Add Endpoint button must be visible').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = appDialog(page)
		await expect(dialog, 'Modal must open after clicking Add Endpoint').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-EP-UI-001: Endpoint detail page', () => {
	// @e2e endpoint-runtime::endpoint-detail-page-renders-for-an-existing-endpoint
	test('Endpoint detail URL renders app-content without crashing', async ({ page }) => {
		// Navigate directly to a detail-style URL; SPA gracefully handles nonexistent IDs.
		// Like the mapping-detail surface (see mapping-and-search.spec.ts), the
		// endpoint-detail surface keeps polling an OR fetch for the nonexistent id,
		// so `networkidle` never settles and the goto burns the whole test timeout.
		// Wait for the DOM and assert on the rendered content instead — the
		// assertions below are unchanged and remain the real signal.
		await page.goto(`${APP_BASE}/endpoints/__nonexistent__`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(50)
	})
})

test.describe('REQ-EP-UI-001: Endpoint logs sub-page', () => {
	// @e2e endpoint-runtime::endpoints-logs-sub-page-mounts
	test('Endpoint logs page mounts and shows main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/endpoints/logs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

// ---------------------------------------------------------------------------
// HTTP-contract assertions for the endpoint dispatch / OR list surfaces are
// API-direct (no @e2e tag) and have been relocated to
// tests/e2e/api-direct/endpoint-runtime.api.spec.ts (Newman-equivalent,
// excluded from the gate-19 UI run). gate-19: API-direct → Newman.
// ---------------------------------------------------------------------------
