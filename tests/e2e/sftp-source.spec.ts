/*
 * SPDX-FileCopyrightText: 2026 Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator pins a partner's SFTP server (sources-sftp-adapter, REQ-SFTP-001).
 *
 * Needs an SFTP server reachable from the instance and allowed by the egress
 * guard. Set E2E_SFTP_HOST (for example `sftp:22`, an atmoz/sftp container in
 * the dev compose); the test skips without it, because without a server there
 * is no key to pin.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/integriq'
const SFTP_HOST = process.env.E2E_SFTP_HOST ?? ''

test.describe('SFTP source host key', () => {
	test.skip(SFTP_HOST === '', 'E2E_SFTP_HOST is not set')

	test('the first test shows the fingerprint and confirming it pins it', async ({ page, request }) => {
		const created = await request.post('/index.php/apps/openregister/api/objects/integriq/source', {
			headers: { 'OCS-APIRequest': 'true' },
			data: { name: 'E2E SFTP partner', type: 'sftp', location: SFTP_HOST, rootPath: '/upload' },
		})
		expect(created.ok()).toBeTruthy()
		const source = await created.json()
		const id = source['@self']?.id ?? source.id

		await page.goto(`${APP_BASE}/sources`)
		const row = page.getByRole('row', { name: /E2E SFTP partner/ })
		await row.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: 'Test connection' }).click()

		const modal = page.getByTestId('file-server-test')
		await modal.getByRole('button', { name: 'Run test' }).click()
		const fingerprint = modal.getByTestId('presented-fingerprint')
		await expect(fingerprint).toContainText(/SHA256:[A-Za-z0-9+/]{43}/)

		await modal.getByTestId('pin-fingerprint').click()
		await expect(modal.getByText('Pinned fingerprint')).toBeVisible()

		const stored = await request.get(`/index.php/apps/openregister/api/objects/integriq/source/${id}`)
		expect((await stored.json()).hostKeyFingerprint).toBe((await fingerprint.textContent())?.trim())
	})
})
