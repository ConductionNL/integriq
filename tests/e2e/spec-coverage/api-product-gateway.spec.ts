/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/api-product-gateway/spec.md
 *
 * Covers the index-mounts scenario. Everything else in that spec is gateway
 * behaviour with no browser surface and stays with PHPUnit/Newman.
 *
 * NOT covered here:
 * `product-detail-page-exposes-an-endpoint-picker-and-tier-editor`. It needs a
 * product to open, a cold instance has none, and both ways of getting one were
 * worse than the gap: skipping on an empty fixture is coverage that never runs
 * (what gate-19 exists to catch), and seeding one made this file fail as a
 * pair while each test passed alone. Left uncovered and visible rather than
 * shipped flaky.
 *
 * ⚠️ The `#` is required — the in-app router is hash-mode
 * (`createWebHashHistory()`, src/main.js). Without it the URL serves the SPA
 * shell and renders the DASHBOARD, which looks like the page failing to
 * render its own content.
 */

import { test, expect, type Page } from '@playwright/test'

const APP_BASE = '/index.php/apps/openconnector/#'

async function openPage(page: Page, route: string): Promise<void> {
	await page.goto(`${APP_BASE}${route}`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
	await expect(
		page.locator('#app-content, [data-cy=app-content], .app-content').first(),
	).toBeVisible({ timeout: 20_000 })
}

test.describe('api-product-gateway — operator surfaces', () => {
	// @e2e api-product-gateway::api-products-list-page-mounts-and-shows-content
	test('API Products list page mounts and shows content', async ({ page }) => {
		await openPage(page, '/products')

		const content = page.locator('#app-content, .app-content').first()
		const html = await content.innerHTML()
		expect(
			html.length,
			'API Products rendered nothing inside app-content',
		).toBeGreaterThan(100)

		// Either the product list or its empty state — both are valid on a
		// cold instance. What must NOT be true is the dashboard rendering in
		// its place, which is what a missing `#` produces.
		const body = (await content.innerText()).toLowerCase()
		expect(
			body.includes('product')
				|| body.includes('geen')
				|| body.includes('no '),
			`API Products showed neither a product surface nor an empty state: ${body.slice(0, 200)}`,
		).toBe(true)
	})
})
