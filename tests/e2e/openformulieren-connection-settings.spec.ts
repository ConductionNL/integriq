/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md
 * (REQ-007, the Open Formulieren connection's account is chosen and checked by an administrator).
 *
 * What an administrator does here: open the Open Formulieren connection section, see
 * which account the intake acts as, and choose one. An account that
 * cannot store submissions is refused with a field error and the stored account
 * stays as it was.
 *
 * The signed anonymous submission is covered by PHPUnit and by the live
 * proof in tasks.md 6.1: a browser cannot sign an Open Formulieren webhook.
 */

import { expect, test } from '@playwright/test'

const CONFIG_URL = '/index.php/apps/integriq/api/admin/open-formulieren-connection'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('open formulieren connection settings', () => {
	// @e2e open-formulieren-intake::no-account-set-is-shown-plainly
	test('no account set is shown plainly', async ({ page, request }) => {
		const cleared = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { scheme: 'openconnector', userId: '' },
		})
		expect(cleared.status(), 'clearing the account must succeed').toBe(200)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(
			page.getByTestId('admin-openformulieren-account-state'),
		).toHaveText(
			/No account set: Open Formulieren submissions are refused with 503/,
			{ timeout: 20_000 },
		)
	})

	// @e2e open-formulieren-intake::choosing-a-valid-account
	test('choosing a valid account', async ({ page, request }) => {
		const saved = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { scheme: 'openconnector', secret: 'e2e-secret', userId: 'admin' },
		})
		expect(saved.status(), 'an account with the rights saves').toBe(200)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(
			page.getByTestId('admin-openformulieren-account-state'),
		).toHaveText(/Intake acts as/, { timeout: 20_000 })
	})

	// @e2e open-formulieren-intake::an-account-without-rights-is-refused
	test('an account that cannot store submissions is refused', async ({
		request,
	}) => {
		const refused = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { scheme: 'openconnector', userId: 'e2e-no-such-account' },
		})
		expect(refused.status()).toBe(400)
		const body = await refused.json()
		expect(body.fieldErrors.userId).toContain('e2e-no-such-account')

		const after = await (
			await request.get(CONFIG_URL, { headers: HEADERS })
		).json()
		expect(after.userId, 'the stored account is unchanged').not.toBe(
			'e2e-no-such-account',
		)
	})
})
