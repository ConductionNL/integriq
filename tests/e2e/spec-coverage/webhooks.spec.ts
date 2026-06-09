/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the openconnector Webhooks page.
 *
 * BUG (flagged, not fixed): src/manifest.json's "Webhooks" page is a
 * schema-driven index bound to schema "consumer" (the SAME schema as the
 * Consumers page). There is no "webhook" schema in OpenRegister (the OR
 * endpoint /api/objects/openconnector/webhook returns 404). As a result
 * the Webhooks index:
 *   - lists consumer objects, not webhooks, and
 *   - renders its create button as "Add Consumer" instead of "Add Webhook".
 *
 * The page heading is correct ("Webhooks") because that comes from the
 * page title, but the create-button label + underlying data are wrong.
 * See the test.fixme below documenting the expected correct behaviour.
 */
import { test, expect } from '@playwright/test'
import { navTo, trackErrors, assertNoAppErrors } from './_helpers'

test.describe('Webhooks — index surface', () => {
	// @e2e openconnector-comprehensive-tests::webhooks-page-mounts
	test('Webhooks page renders heading via nav-click without app errors', async ({ page }) => {
		const sink = trackErrors(page)
		await navTo(page, 'Webhooks', '/webhooks')
		await expect(page.getByRole('heading', { name: /^Webhooks$/ }).first())
			.toBeVisible({ timeout: 15_000 })
		// A create button is present (currently mislabeled — see fixme).
		const addBtn = page.getByRole('button', { name: /Add (Webhook|Consumer)/i }).first()
		await expect(addBtn, 'Webhooks page must offer a create action').toBeVisible({ timeout: 15_000 })
		assertNoAppErrors(sink)
	})

	// Documents the manifest mis-binding. Remove .fixme once the Webhooks
	// page is bound to a real "webhook" schema and labels its create button
	// "Add Webhook".
	test.fixme('Webhooks create button should read "Add Webhook" (manifest binds wrong schema)', async ({ page }) => {
		await navTo(page, 'Webhooks', '/webhooks')
		const addWebhook = page.getByRole('button', { name: /Add Webhook/i }).first()
		await expect(addWebhook, 'BUG: Webhooks page is bound to the "consumer" schema → button reads "Add Consumer"')
			.toBeVisible({ timeout: 10_000 })
	})
})
