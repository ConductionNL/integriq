/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * access-oauth-and-token-validation, REQ-TOKV-005: an administrator gives a
 * consumer its scopes in the consumer editor (the Scopes chip field), and the
 * saved consumer holds exactly those scopes. That an endpoint then refuses a
 * consumer without the scope it requires is backend behaviour, covered by
 * PHPUnit on EndpointService and by Newman folder 18.
 *
 * Runs as the admin the global setup signs in: editing consumers is an admin
 * act. The consumer is found back through OpenRegister's object API and
 * removed at the end.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/**
 * The stored consumer with this name, or null.
 *
 * @param request The request fixture.
 * @param name The consumer's name.
 * @return The consumer object.
 */
async function consumerNamed(
	request: APIRequestContext,
	name: string,
): Promise<Record<string, unknown> | null> {
	const resp = await request.get(`${OR_BASE}/consumer`, {
		params: { name, _limit: '20' },
		failOnStatusCode: false,
	})
	expect(resp.status()).toBe(200)
	const { results } = await resp.json()
	return (results as Array<Record<string, unknown>>).find((row) => row.name === name) ?? null
}

/**
 * Open the consumer editor for a new consumer.
 *
 * @param page The page.
 * @return The dialog.
 */
async function openNewConsumer(page: Page) {
	await page.goto(`${APP_BASE}/consumers`, { waitUntil: 'domcontentloaded' })
	await page.getByRole('button', { name: /Add (Item|Consumer)/i }).click()
	const dialog = appDialog(page)
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	return dialog
}

/**
 * Type one scope into the Scopes chip field and confirm it.
 *
 * @param page The page.
 * @param scope The scope.
 * @return Nothing.
 */
async function addScope(page: Page, scope: string): Promise<void> {
	const input = page.locator('#cn-consumer-editor-scopes')
	await input.fill(scope)
	await input.press('Enter')
}

test.describe('access-oauth-and-token-validation: scopes on a consumer', () => {
	const created: string[] = []

	test.afterEach(async ({ request }) => {
		for (const name of created.splice(0)) {
			const consumer = await consumerNamed(request, name)
			const id = consumer && (consumer.id ?? consumer.uuid)
			if (id) {
				await request.delete(`${OR_BASE}/consumer/${id}`, { failOnStatusCode: false })
			}
		}
	})

	// @e2e consumer-management::a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow
	test('the scopes typed in the editor are what the consumer holds', async ({ page, request }) => {
		const name = `e2e-scopes-${Date.now()}`
		created.push(name)
		const dialog = await openNewConsumer(page)

		await dialog.getByLabel(/^Name/).fill(name)
		await addScope(page, 'zaken:read')
		await addScope(page, 'documenten:read')
		const field = dialog.getByTestId('consumer-editor-scopes')
		await expect(field).toContainText('zaken:read')
		await expect(field).toContainText('documenten:read')

		await dialog.getByRole('button', { name: /^(Create|Save)$/ }).click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect.poll(async () => (await consumerNamed(request, name))?.scopes, { timeout: 10_000 })
			.toEqual(['zaken:read', 'documenten:read'])
	})

	// @e2e consumer-management::a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow
	test('a scope taken off before saving is not stored', async ({ page, request }) => {
		const name = `e2e-scopes-off-${Date.now()}`
		created.push(name)
		const dialog = await openNewConsumer(page)

		await dialog.getByLabel(/^Name/).fill(name)
		await addScope(page, 'zaken:read')
		await addScope(page, 'zaken:write')
		const field = dialog.getByTestId('consumer-editor-scopes')
		await field.getByRole('button', { name: /zaken:write/ }).click()
		await expect(field).not.toContainText('zaken:write')

		await dialog.getByRole('button', { name: /^(Create|Save)$/ }).click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect.poll(async () => (await consumerNamed(request, name))?.scopes, { timeout: 10_000 })
			.toEqual(['zaken:read'])
	})
})
