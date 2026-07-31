/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/cloud-event-management/spec.md
 *
 * REQ-CE-UI-001 (Cloud Event Management UI) — real Playwright UI tests
 * covering the Cloud Events SPA section: list, modal, logs.
 *
 * REQ-CE-001 describes backend CloudEvents inbound routing internals that
 * carry @e2e exclude and are covered by PHPUnit/Newman.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Assertions use
 * the main content area rather than relying on table cell content.
 */

import { test, expect } from '@playwright/test'
import { appDialog } from '../support/dialogs'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
// The in-app router runs in HASH mode (src/main.js `mode: 'hash'`), so a
// path-form deep-link (`/apps/openconnector/cloud-events/events`) is ignored
// and lands on the dashboard; the hash form
// (`/apps/openconnector/#/cloud-events/events`) renders the target page.
// APP_BASE carries the `/#`.
const APP_BASE = '/apps/openconnector/#'

// ---------------------------------------------------------------------------
// REQ-CE-UI-001: Cloud Event Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-CE-UI-001: Cloud Events list page mounts', () => {
	// @e2e cloud-event-management::cloud-events-list-page-mounts-and-shows-content
	test('Cloud Events index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/cloud-events/events`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-CE-UI-001: Add Cloud Event modal', () => {
	// @e2e cloud-event-management::add-cloud-event-button-opens-the-creation-modal
	test('Add Item button on Cloud Events page opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/cloud-events/events`, { waitUntil: 'domcontentloaded' })
		const addBtn = page.getByRole('button', { name: /Add (Item|Cloud Event|Event)/i })
		await expect(addBtn, 'Add Item button must be visible on Cloud Events page').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = appDialog(page)
		await expect(dialog, 'Modal must open after clicking Add Item on Cloud Events').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-CE-UI-001: Cloud Event logs sub-page', () => {
	// @e2e cloud-event-management::cloud-event-logs-sub-page-mounts
	test('Cloud Event logs page mounts and shows main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/cloud-events/logs`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Cloud Events OR API — list', () => {
	test('OR returns event objects for the openconnector register', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/event?_limit=10`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})
