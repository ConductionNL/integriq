/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/source-management/spec.md
 *
 * REQ-SRC-UI-001 (Source Management UI) — real Playwright UI tests covering
 * the Sources SPA section: list, modal, logs.
 *
 * REQ-SRC-001 and REQ-SRC-002 describe backend call/test internals that carry
 * @e2e exclude and are covered by PHPUnit/Newman.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Assertions use
 * the main content area rather than relying on table cell content.
 * Use Cards view where the Add Item button is always visible.
 */

import { test, expect } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
// /index.php/apps/openconnector/* strips the path on redirect; use /apps/ prefix.
const APP_BASE = '/apps/openconnector'

// ---------------------------------------------------------------------------
// REQ-SRC-UI-001: Source Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-SRC-UI-001: Sources list page mounts', () => {
	// @e2e source-management::sources-list-page-mounts-and-shows-content
	test('Sources index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/sources`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-SRC-UI-001: Add Source modal', () => {
	// @e2e source-management::add-source-button-opens-the-creation-modal
	test('Add Item button on Sources page opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/sources`, { waitUntil: 'networkidle' })
		// Switch to Cards view to ensure the add button is accessible
		const cardsRadio = page.getByRole('radio', { name: /Cards/i })
		if (await cardsRadio.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await cardsRadio.click()
		}
		const addBtn = page.getByRole('button', { name: /Add (Item|Source)/i })
		await expect(addBtn, 'Add Item button must be visible on Sources page').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'Modal must open after clicking Add Item on Sources').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-SRC-UI-001: Source logs sub-page', () => {
	// @e2e source-management::source-logs-sub-page-mounts
	test('Source logs page mounts and shows main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/sources/logs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Sources OR API — list', () => {
	test('OR returns source objects for the openconnector register', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/source?_limit=10`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})
