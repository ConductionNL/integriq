/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the openconnector Features & roadmap
 * page (manifest type "roadmap"). Reached from the footer nav entry; shows
 * a "Features" surface with "Show roadmap" / "Suggest a feature" actions.
 */
import { test, expect } from '@playwright/test'
import { navTo, trackErrors, assertNoAppErrors, APP_BASE } from './_helpers'

test.describe('Features & roadmap — index surface', () => {
	// @e2e openconnector-comprehensive-tests::features-roadmap-page-mounts
	test('Roadmap page renders via nav-click with its primary actions', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await navTo(page, 'Features & roadmap', '/features-roadmap')

		await expect(
			page.getByRole('heading', { name: /Features/i }).first(),
		).toBeVisible({ timeout: 15_000 })

		// Primary actions surfaced by the roadmap page.
		const action = page
			.getByRole('button', { name: /Show roadmap|Suggest (a )?feature/i })
			.first()
		await expect(
			action,
			'roadmap page must offer a Show roadmap / Suggest feature action',
		).toBeVisible({ timeout: 10_000 })

		assertNoAppErrors(sink)
	})

	// @e2e openconnector-comprehensive-tests::features-roadmap-suggest-feature
	test('Suggest a feature action is interactive', async ({ page }) => {
		const sink = trackErrors(page)
		await page.goto(`${APP_BASE}/features-roadmap`, {
			waitUntil: 'domcontentloaded',
		})
		const suggest = page
			.getByRole('button', { name: /Suggest (a )?feature/i })
			.first()
		await expect(suggest).toBeVisible({ timeout: 15_000 })
		await suggest.click()
		// Either opens a dialog or navigates to an external suggestion target;
		// assert the click did not produce an app error.
		await page.waitForTimeout(1_000)
		assertNoAppErrors(sink)
	})
})
