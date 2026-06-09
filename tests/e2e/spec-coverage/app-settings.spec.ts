/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the openconnector in-app Settings
 * page (manifest type "settings", reached from the settings-section nav).
 * Asserts the settings form renders with its Save action and produces no
 * openconnector-origin error.
 */
import { test, expect } from '@playwright/test'
import { trackErrors, assertNoAppErrors, APP_BASE } from './_helpers'

test.describe('App Settings — index surface', () => {
	// @e2e openconnector-comprehensive-tests::app-settings-page-mounts
	//
	// Settings lives in the nav "settings" section; its accessible name
	// "Settings" collides with Nextcloud's own personal-settings gear, so a
	// label-based nav-click is ambiguous. Deep-link the route, then assert
	// the app's settings surface renders (Save action + non-trivial content).
	test('Settings page renders with a Save action', async ({ page }) => {
		const sink = trackErrors(page)
		await page.goto(`${APP_BASE}/settings`, { waitUntil: 'networkidle' })

		// Settings page mounts inside the main content area.
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })

		// The settings form exposes a Save button.
		const save = page.getByRole('button', { name: /^Save$/i }).first()
		await expect(save, 'Settings page must expose a Save button').toBeVisible({ timeout: 15_000 })

		assertNoAppErrors(sink)
	})

	// @e2e openconnector-comprehensive-tests::app-settings-content-renders
	test('Settings page renders non-trivial content', async ({ page }) => {
		const sink = trackErrors(page)
		await page.goto(`${APP_BASE}/settings`, { waitUntil: 'networkidle' })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length, 'settings content area should be non-trivial').toBeGreaterThan(150)
		assertNoAppErrors(sink)
	})
})
