/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e for openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md
 *
 * REQ-CRED-002 (a new key is shown exactly once) and REQ-CRED-003 (a key can be
 * replaced without downtime): on a consumer's detail page an administrator
 * generates a key, sees it once with a copy button, copies it, finds only its
 * prefix after a reload, and revokes it.
 *
 * The consumer is created through the OpenRegister object API with a
 * `newman-e2e-cred-` name and removed again in afterAll.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API = '/index.php/apps/integriq/api'
const CONSUMER_NAME = `newman-e2e-cred-${Date.now()}`
const LABEL = 'Koppeling zaaksysteem'

let consumerId = ''

/**
 * JSON headers with the session's request token.
 *
 * @param request The request context.
 * @return The headers.
 */
async function headers(request: APIRequestContext): Promise<Record<string, string>> {
	return {
		...(await withRequestToken(request)),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Open the consumer's detail page and wait for its credentials panel.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openConsumer(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/consumers/${consumerId}`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(page.getByTestId('consumer-credentials')).toBeVisible({
		timeout: 20_000,
	})
}

test.describe('REQ-CRED-002: a generated key is shown once', () => {
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		const res = await page.request.post(`${OR_BASE}/consumer`, {
			headers: await headers(page.request),
			data: {
				name: CONSUMER_NAME,
				description: 'Seeded by tests/e2e/spec-coverage (safe to delete)',
				authorizationType: 'apiKey',
			},
		})
		expect([200, 201]).toContain(res.status())
		const body = await res.json()
		consumerId = String(body.uuid ?? body.id ?? body['@self']?.id ?? '')
		expect(consumerId).not.toEqual('')
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		if (consumerId !== '') {
			await page.request
				.delete(`${OR_BASE}/consumer/${consumerId}`, {
					headers: await headers(page.request),
				})
				.catch(() => null)
		}
		await page.close()
	})

	// @e2e consumer-management::an-administrator-hands-a-new-key-to-a-partner
	test('generate, copy, reload and revoke a key', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await openConsumer(page)
		await expect(page.getByTestId('consumer-credentials-empty')).toBeVisible()

		// Generate: the key is shown once, with a copy button.
		await page.getByTestId('consumer-credentials-generate').click()
		const dialog = page.getByTestId('add-credential-dialog')
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Label').fill(LABEL)
		await dialog.getByTestId('add-credential-submit').click()

		const keyBox = dialog.getByTestId('add-credential-key')
		await expect(keyBox).toBeVisible()
		const key = (await keyBox.textContent())?.trim() ?? ''
		expect(key.length).toBeGreaterThan(20)

		// Copy puts exactly the key on the clipboard.
		await dialog.getByTestId('add-credential-copy').click()
		await expect(dialog.getByTestId('add-credential-copied')).toBeVisible()
		expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(key)

		await dialog.getByTestId('add-credential-done').click()
		await expect(dialog).toHaveCount(0)

		// After a reload the list names the key by its label and prefix only.
		await page.reload({ waitUntil: 'domcontentloaded' })
		const panel = page.getByTestId('consumer-credentials')
		const row = panel.locator('tr', { hasText: LABEL })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('API key')
		await expect(row).toContainText('Not used yet')
		const shown = (await row.locator('code').textContent())?.trim() ?? ''
		expect(shown.length).toBeGreaterThan(0)
		expect(key.startsWith(shown.replace(/[.…]+$/, ''))).toBe(true)
		expect(await panel.innerText()).not.toContain(key)

		// No route returns the key: the list answer does not hold it either.
		const listed = await page.request.get(
			`${API}/consumers/${consumerId}/credentials`,
			{ headers: await headers(page.request) },
		)
		expect(listed.status()).toBe(200)
		expect(await listed.text()).not.toContain(key)

		// Revoke: the dialog names the label, and the row leaves the list.
		await row.getByRole('button', { name: 'Revoke' }).click()
		const revoke = page.getByTestId('revoke-credential-dialog')
		await expect(revoke).toContainText(LABEL)
		await revoke.getByTestId('revoke-credential-submit').click()
		await expect(panel.locator('tr', { hasText: LABEL })).toHaveCount(0)
		await expect(page.getByTestId('consumer-credentials-empty')).toBeVisible()
	})

	// @e2e consumer-management::a-partner-moves-to-a-new-key
	test('two keys are active at once and revoking one keeps the other', async ({
		page,
	}) => {
		const keys: string[] = []
		for (const label of ['Oude sleutel', 'Nieuwe sleutel']) {
			const res = await page.request.post(
				`${API}/consumers/${consumerId}/credentials`,
				{ headers: await headers(page.request), data: { label } },
			)
			expect(res.status()).toBe(201)
			keys.push(String((await res.json()).key ?? ''))
		}

		await openConsumer(page)
		const panel = page.getByTestId('consumer-credentials')
		const oldRow = panel.locator('tr', { hasText: 'Oude sleutel' })
		const newRow = panel.locator('tr', { hasText: 'Nieuwe sleutel' })
		await expect(oldRow).toBeVisible()
		await expect(newRow).toBeVisible()
		for (const row of [oldRow, newRow]) {
			await expect(row).toContainText('Not used yet')
		}

		await oldRow.getByRole('button', { name: 'Revoke' }).click()
		await page
			.getByTestId('revoke-credential-dialog')
			.getByTestId('revoke-credential-submit')
			.click()
		await expect(oldRow).toHaveCount(0)
		await expect(newRow).toBeVisible()

		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(panel.locator('tr', { hasText: 'Nieuwe sleutel' })).toBeVisible(
			{
				timeout: 20_000,
			},
		)
		await expect(panel.locator('tr', { hasText: 'Oude sleutel' })).toHaveCount(0)
		for (const key of keys) {
			expect(await panel.innerText()).not.toContain(key)
		}
	})
})
