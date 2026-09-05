/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the integriq Features & roadmap
 * page (manifest type "roadmap"). Reached from the footer nav entry; shows
 * a "Features" surface with "Show roadmap" / "Suggest a feature" actions.
 */
import { expect, test } from '@playwright/test'
import { APP_BASE, assertNoAppErrors, navTo, trackErrors } from './_helpers.ts'

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
		// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
		// suggestion modal (team decision 2026-09-04: the forge is where the
		// conversation happens), and the CTA is an anchor to the forge's
		// feature-request issue form now. An `<a href>` has role `link`.
		const action = page
			.getByRole('button', { name: /Show roadmap/i })
			.or(page.getByRole('link', { name: /Suggest (a )?feature/i }))
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
		// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
		// suggestion modal (team decision 2026-09-04: the forge is where the
		// conversation happens), and the CTA is an anchor to the forge's
		// feature-request issue form now. An `<a href>` has role `link`.
		const suggest = page
			.getByRole('link', { name: /Suggest (a )?feature/i })
			.first()
		await expect(suggest).toBeVisible({ timeout: 15_000 })

		// 🔴 READ THE TARGET, DO NOT FOLLOW IT. The CTA now leaves the app for a
		// real issue form on a real forge, so clicking it in CI would navigate
		// off the instance under test and out to the network.
		const href = await suggest.getAttribute('href')
		expect(href, 'the CTA rendered without a target').toBeTruthy()
		expect(href, `the CTA points at ${href}`).toMatch(/issues\/new/)
		await expect(suggest).toHaveAttribute('target', '_blank')
		assertNoAppErrors(sink)
	})
})
