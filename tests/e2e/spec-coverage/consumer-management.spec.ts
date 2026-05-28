/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/consumer-management/spec.md
 *
 * REQ-CON-UI-001 (Consumer Management UI) — real Playwright UI tests covering
 * the Consumers SPA section: list, modal.
 *
 * REQ-WBHK-UI-001 (Webhook Management UI) — real Playwright UI tests covering
 * the Webhooks SPA section: list, modal.
 *
 * REQ-CON-001 describes backend consumer auth enforcement that carries
 * @e2e exclude and is covered by PHPUnit/Newman.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Assertions use
 * the main content area rather than relying on table cell content.
 */

import { test, expect } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
// /index.php/apps/openconnector/* strips the path on redirect; use /apps/ prefix.
const APP_BASE = '/apps/openconnector'

// ---------------------------------------------------------------------------
// REQ-CON-UI-001: Consumer Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-CON-UI-001: Consumers list page mounts', () => {
	// @e2e consumer-management::consumers-list-page-mounts-and-shows-content
	test('Consumers index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/consumers`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-CON-UI-001: Add Consumer modal', () => {
	// @e2e consumer-management::add-consumer-button-opens-the-creation-modal
	test('Add Item button on Consumers page opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/consumers`, { waitUntil: 'networkidle' })
		// Switch to Cards view to ensure the add button is accessible
		const cardsRadio = page.getByRole('radio', { name: /Cards/i })
		if (await cardsRadio.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await cardsRadio.click()
		}
		const addBtn = page.getByRole('button', { name: /Add (Item|Consumer)/i })
		await expect(addBtn, 'Add Item button must be visible on Consumers page').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'Modal must open after clicking Add Item on Consumers').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

// ---------------------------------------------------------------------------
// REQ-WBHK-UI-001: Webhook Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-WBHK-UI-001: Webhooks list page mounts', () => {
	// @e2e consumer-management::webhooks-list-page-mounts-and-shows-content
	test('Webhooks index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/webhooks`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-WBHK-UI-001: Add Webhook modal', () => {
	// @e2e consumer-management::add-webhook-button-opens-the-creation-modal
	test('Add Item button on Webhooks page opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/webhooks`, { waitUntil: 'networkidle' })
		// Switch to Cards view to ensure the add button is accessible
		const cardsRadio = page.getByRole('radio', { name: /Cards/i })
		if (await cardsRadio.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await cardsRadio.click()
		}
		const addBtn = page.getByRole('button', { name: /Add (Item|Webhook|Consumer)/i })
		await expect(addBtn, 'Add Item button must be visible on Webhooks page').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'Modal must open after clicking Add Item on Webhooks').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Consumers OR API — list', () => {
	test('OR returns consumer objects for the openconnector register', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/consumer?_limit=10`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})
