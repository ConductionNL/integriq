/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/action-authorization/spec.md
 *
 * This app's adoption of ADR-023. The service-level decisions (admin
 * break-glass, group intersection with "admin" excluded, fail-closed on a
 * corrupt matrix) have no UI surface and are proven in PHPUnit; what a
 * browser can prove is the half the spec says an administrator interacts
 * with — that the matrix renders in settings, lists the declared actions
 * with their groups, and is served by the routes it claims.
 */

import { test, expect } from '@playwright/test'

const MATRIX_URL = '/index.php/apps/openconnector/api/admin/action-matrix'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/openconnector'

test.describe('action-authorization: the admin-facing matrix', () => {

	// @e2e action-authorization::admin-sees-the-action-matrix
	test('an administrator sees the action matrix in settings', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })

		const section = page.locator('[data-testid=admin-action-auth-section]')
		await expect(
			section,
			'the Action authorization section must render in OpenConnector admin settings',
		).toBeVisible({ timeout: 20_000 })

		await expect(section.getByRole('heading', { name: /Action authorization/i })).toBeVisible()

		// The matrix is only meaningful once it has stopped loading and has
		// rows: a heading over an empty table would satisfy a laxer assertion
		// while telling an admin nothing about what is gated.
		const rows = section.locator('table tbody tr')
		await expect
			.poll(async () => await rows.count(), {
				message: 'the matrix must list at least one declared action',
				timeout: 20_000,
			})
			.toBeGreaterThan(0)

		// Every row names an action and shows the groups allowed to invoke it.
		// `source.test` is in lib/actions.seed.json and is seeded admin-only.
		await expect(section.getByText('source.test', { exact: false }).first()).toBeVisible()
	})

	test('the matrix route serves the same actions the UI renders', async ({ page, request }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.locator('[data-testid=admin-action-auth-section]')
		await expect(section).toBeVisible({ timeout: 20_000 })

		// The route carries no #[NoCSRFRequired] — deliberately, it is an admin
		// write surface — so a bare request.get() is a 412 with no token. Read
		// the one the page was served with, exactly as the SPA does.
		const requesttoken = await page.evaluate(
			() => document.head.getAttribute('data-requesttoken') ?? '',
		)
		expect(requesttoken, 'the settings page must carry a request token').not.toBe('')

		const resp = await request.get(MATRIX_URL, {
			headers: { requesttoken },
			failOnStatusCode: false,
		})
		expect(resp.status(), 'an admin session must be able to read the matrix').toBe(200)

		const body = await resp.json()
		const matrix = body?.matrix ?? body
		expect(
			Object.keys(matrix ?? {}).length,
			'the matrix endpoint must return the declared actions, not an empty object',
		).toBeGreaterThan(0)

		// The UI is reading THIS, not a second source of truth: an action the
		// route reports must be on the page.
		const [firstAction] = Object.keys(matrix)
		await expect(section.getByText(firstAction, { exact: false }).first()).toBeVisible({
			timeout: 20_000,
		})
	})
})
