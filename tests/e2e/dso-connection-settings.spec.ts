/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md
 * (REQ-DSO-071, the DSO connection's account is chosen and checked by an administrator).
 *
 * What an administrator does here: open the DSO connection section, see
 * which account the STAM intake acts as, and choose one. An account that
 * cannot store verzoeken is refused with a field error and the stored account
 * stays as it was.
 *
 * The signed anonymous push and the cron job are covered by PHPUnit and by
 * the live proof in tasks.md 6.1: a browser cannot sign a STAM push.
 */

import { expect, test } from '@playwright/test'

const CONFIG_URL = '/index.php/apps/integriq/api/admin/dso-pki-config'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('dso connection settings', () => {
	// @e2e dso-omgevingsloket::no-account-set-is-shown-plainly
	test('no account set is shown plainly', async ({ page, request }) => {
		const cleared = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { mode: 'hmac', userId: '' },
		})
		expect(cleared.status(), 'clearing the account must succeed').toBe(200)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('admin-dso-account-state')).toHaveText(
			/No account set: DSO-LV pushes are refused with 503/,
			{ timeout: 20_000 },
		)
	})

	// @e2e dso-omgevingsloket::choosing-a-valid-account
	test('choosing a valid account', async ({ page, request }) => {
		const saved = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { mode: 'hmac', hmacSecret: 'e2e-secret', userId: 'admin' },
		})
		expect(saved.status(), 'an account with the rights saves').toBe(200)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('admin-dso-account-state')).toHaveText(
			/Intake acts as/,
			{ timeout: 20_000 },
		)
	})

	// @e2e dso-omgevingsloket::an-account-without-rights-is-refused
	test('an account that cannot store verzoeken is refused', async ({
		request,
	}) => {
		const refused = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { mode: 'hmac', userId: 'e2e-no-such-account' },
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
