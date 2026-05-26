/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/synchronization-engine/spec.md
 *
 * Tests REQ-001 (synchronization orchestration) against the live Nextcloud
 * instance. These tests verify the HTTP surface of the synchronization API
 * (run trigger, statistics, logs) and the UI list page.
 *
 * Note: The spec describes full sync engine internals; many behaviors
 * (pagination, rate-limiting, mapping) are not UI-testable without
 * pre-configured source+sync+target data. These tests cover the observable
 * API + UI surface accessible in the dev environment.
 */

import { test, expect, type Page } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'

/**
 * Resolve the openconnector app URL base (Apache vs PHP built-in server).
 * Cached per test file invocation via module-level variable.
 */
let _appBase: string | null = null
async function appBase(page: Page): Promise<string> {
	if (_appBase) return _appBase
	for (const candidate of ['/apps/openconnector', '/index.php/apps/openconnector']) {
		const res = await page.request.get(`${candidate}/synchronizations`, { failOnStatusCode: false })
		const body = await res.text()
		if (res.ok() && body.includes('openconnector-main.js')) {
			_appBase = candidate
			return candidate
		}
	}
	throw new Error('Cannot resolve openconnector app base')
}

test.describe('REQ-001: Synchronization orchestration — API surface', () => {
	test('GET statistics endpoint returns aggregate synchronization counts', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/synchronizations/statistics`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// Statistics must include total/enabled/disabled counts.
		expect(body).toHaveProperty('totalCount')
		expect(typeof body.totalCount).toBe('number')
	})

	test('GET synchronizations list from OR returns synchronization objects', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/synchronization?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// OR returns paged results under "results".
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})

	test('POST run on a non-existent sync UUID returns 400 or 404 (not 500)', async ({ request }) => {
		// An unknown UUID triggers a validation/lookup error, not a server crash.
		const resp = await request.post(
			`${API_BASE}/synchronizations/00000000-0000-0000-0000-000000000000/run`,
			{ failOnStatusCode: false },
		)
		// Accept 400 (validation) or 404 (not found); must NOT be 500.
		expect(resp.status()).toBeLessThan(500)
		expect(resp.status()).toBeGreaterThanOrEqual(400)
	})

	test('GET synchronizations/logs returns a log list', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/synchronizations/logs`, {
			failOnStatusCode: false,
		})
		// The logs endpoint should return 200 (may be empty on a fresh install).
		expect(resp.status()).toBe(200)
	})
})

test.describe('REQ-001: Synchronization orchestration — UI surface', () => {
	test('/synchronizations page mounts and displays the synchronization index', async ({ page }) => {
		const base = await appBase(page)
		await page.goto(`${base}/synchronizations`, { waitUntil: 'domcontentloaded' })
		// The app-content shell must be visible.
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 10_000 })
		// The page should render something meaningful in the app-content area.
		const html = await page.locator('#app-content, .app-content').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})

	test('/synchronizations/logs page mounts', async ({ page }) => {
		const base = await appBase(page)
		await page.goto(`${base}/synchronizations/logs`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 10_000 })
	})

	test('/synchronizations/contracts page mounts', async ({ page }) => {
		const base = await appBase(page)
		await page.goto(`${base}/synchronizations/contracts`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 10_000 })
	})

	test('synchronization UI page — create via Add button', async ({ page }) => {
		const base = await appBase(page)
		await page.goto(`${base}/synchronizations`, { waitUntil: 'domcontentloaded' })

		// Wait for the Add Synchronization button to appear.
		const addBtn = page.getByRole('button', { name: /Add\s+Synchronization/i })
		await expect(addBtn, 'Add Synchronization button present').toBeVisible({ timeout: 10_000 })

		// Click it and verify the CnFormDialog opens.
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'CnFormDialog opens for new synchronization').toBeVisible({ timeout: 10_000 })

		// Close/dismiss without saving.
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close|×/i }).first()
		if (await cancelBtn.isVisible().catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})
