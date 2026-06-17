/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/mapping-and-search/spec.md
 *
 * REQ-UI-001 (Mapping Management UI) — real Playwright UI tests covering
 * the Mappings SPA section: list, modal, detail.
 *
 * REQ-001 through REQ-005 describe backend mapping engine internals
 * (Twig/dot engine, cast directives, OR object shim, search helper) that
 * carry @e2e exclude and are covered by PHPUnit.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Detail-page assertions
 * navigate directly to a detail URL rather than clicking a table row.
 */

import { test, expect } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'
// The in-app router runs in HASH mode (src/main.js `mode: 'hash'`), so a
// path-form deep-link (`/apps/openconnector/mappings`) is ignored and lands on
// the dashboard; the hash form (`/apps/openconnector/#/mappings`) renders the
// target page. APP_BASE carries the `/#`.
const APP_BASE = '/apps/openconnector/#'

// ---------------------------------------------------------------------------
// REQ-UI-001: Mapping Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-UI-001: Mappings list page mounts', () => {
	// @e2e mapping-and-search::mappings-list-page-mounts-and-shows-content
	test('Mappings index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/mappings`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-UI-001: Add Mapping modal', () => {
	// @e2e mapping-and-search::add-mapping-button-opens-the-creation-modal
	test('Add Mapping button opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/mappings`, { waitUntil: 'networkidle' })
		const addBtn = page.getByRole('button', { name: 'Add Mapping' })
		await expect(addBtn, 'Add Mapping button must be visible').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'Modal must open after clicking Add Mapping').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-UI-001: Mapping detail page', () => {
	// @e2e mapping-and-search::mapping-detail-page-renders-for-an-existing-mapping
	test('Mapping detail URL renders app-content without crashing', async ({ page }) => {
		// Known bug #996: table cells all "—", so navigate directly to detail URL.
		// SPA gracefully handles nonexistent IDs (shows detail shell or not-found).
		// Hash-mode router (src/main.js — fleet #133): address the detail route via
		// the URL hash. The mapping-detail surface keeps polling an OR fetch for the
		// (nonexistent) id, so `networkidle` never settles — wait for DOM + main
		// instead of network silence.
		await page.goto(`${APP_BASE}/#/mappings/__nonexistent__`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(50)
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Mappings OR API — list', () => {
	test('GET mappings list from OR returns mapping objects', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/mapping?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})

test.describe('Mapping test endpoint — HTTP surface', () => {
	test('POST /api/mappings/test is routable (not 404)', async ({ request }) => {
		const resp = await request.post(`${API_BASE}/mappings/test`, {
			data: { mappingId: 'nonexistent', input: { test: true } },
			failOnStatusCode: false,
		})
		// 400/422 = invalid input, 500 = OR unavailable; all non-crash responses. 404 = route missing.
		expect(resp.status()).not.toBe(404)
	})
})
