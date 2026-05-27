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

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'
// /index.php/apps/openconnector/* strips the path on redirect; use /apps/ prefix.
const APP_BASE = '/apps/openconnector'

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
		const dialog = page.getByRole('dialog').first()
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
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Synchronizations OR API — list', () => {
	test('GET synchronizations list from OR returns synchronization objects', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/synchronization?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})

	test('POST run on a non-existent sync UUID returns 400 or 404 (not 500)', async ({ request }) => {
		const resp = await request.post(
			`${API_BASE}/synchronizations/00000000-0000-0000-0000-000000000000/run`,
			{ failOnStatusCode: false },
		)
		expect(resp.status()).toBeLessThan(500)
		expect(resp.status()).toBeGreaterThanOrEqual(400)
	})
})
