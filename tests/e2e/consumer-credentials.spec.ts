/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * access-consumer-credentials, REQ-CRED-002 and REQ-CRED-003: an
 * administrator generates a key on a consumer's page, sees it exactly once
 * with a copy button, and after a reload sees only its label and prefix. A
 * second key stands next to the first, and revoking the old one leaves the
 * new one in place.
 *
 * The consumer is seeded through OpenRegister's objects API and removed at
 * the end. That a call with the new key passes and one with a revoked key
 * fails is backend behaviour, covered by PHPUnit on AuthorizationService and
 * ConsumerCredentialsController; the scenarios carrying `@e2e exclude` in the
 * spec (the hashing repair step and the client certificate checks) are not
 * restated here.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const RUN = Date.now().toString(36)

/** The header that lets a cookie-session API call past Nextcloud's CSRF check (412 without it). */
const OCS = { 'OCS-APIRequest': 'true' }

/**
 * Seed one consumer with no credentials.
 *
 * @param request The request fixture.
 * @return The consumer's id.
 */
async function seedConsumer(request: APIRequestContext): Promise<string> {
	const resp = await request.post(`${OR_BASE}/consumer`, {
		headers: OCS,
		failOnStatusCode: false,
		data: {
			name: `E2E credentials ${RUN}`,
			description: 'Seeded by tests/e2e/consumer-credentials.spec.ts',
			authorizationType: 'apiKey',
			userId: 'admin',
		},
	})
	expect(resp.status(), 'seeding a consumer must succeed').toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid ?? body['@self']?.id)
}

/**
 * Generate a key with this label on the open consumer page.
 *
 * @param page The page.
 * @param label The key's label.
 * @return The key as shown once.
 */
async function generateKey(page: Page, label: string): Promise<string> {
	// The test id sits on the input itself (NcTextField passes it through).
	await page.getByTestId('credential-key-label').fill(label)
	await page.getByTestId('credential-generate-key').click()

	const shown = page.getByTestId('credential-new-key')
	await expect(shown).toBeVisible({ timeout: 15_000 })
	await expect(shown, 'the key says it is shown only once').toContainText(/only time the key is shown/i)

	const key = (await shown.locator('code').textContent())?.trim() ?? ''
	expect(key.length, 'the generated key is shown').toBeGreaterThan(8)

	return key
}

test.describe('consumer credentials', () => {
	let consumerId = ''

	test.beforeEach(async ({ request }) => {
		consumerId = await seedConsumer(request)
	})

	test.afterEach(async ({ request }) => {
		if (consumerId !== '') {
			await request.delete(`${OR_BASE}/consumer/${consumerId}`, { headers: OCS, failOnStatusCode: false })
		}
	})

	test('an administrator hands a new key to a partner', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await page.goto(`${APP_BASE}/consumers/${consumerId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('credential-keys')).toBeVisible({ timeout: 20_000 })

		const label = `partner ${RUN}`
		const key = await generateKey(page, label)

		await page.getByTestId('credential-copy-key').click()
		await expect(page.getByTestId('credential-copy-key')).toContainText(/Copied/i)

		// No route answers the key again: the list carries the prefix, never the key.
		const list = await page.request.get(`${APP_BASE}/api/consumers/${consumerId}/credentials`, { headers: OCS })
		expect(list.status()).toBe(200)
		expect(await list.text(), 'the credentials list never contains the key').not.toContain(key)

		await page.reload({ waitUntil: 'domcontentloaded' })
		const row = page.getByTestId('credential-key').filter({ hasText: label })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId('credential-new-key'), 'the key is not shown after a reload').toHaveCount(0)
		await expect(page.getByText(key), 'the full key is gone from the page').toHaveCount(0)
		await expect(row.locator('code'), 'the row shows the key prefix').toHaveText(/\S+…/)
	})

	test('a partner moves to a new key', async ({ page }) => {
		await page.goto(`${APP_BASE}/consumers/${consumerId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('credential-keys')).toBeVisible({ timeout: 20_000 })

		const oldLabel = `old ${RUN}`
		const newLabel = `new ${RUN}`
		await generateKey(page, oldLabel)
		await generateKey(page, newLabel)

		const keys = page.getByTestId('credential-key')
		await expect(keys.filter({ hasText: oldLabel })).toBeVisible()
		await expect(keys.filter({ hasText: newLabel }), 'two keys stand side by side').toBeVisible()

		await keys.filter({ hasText: oldLabel }).getByTestId('credential-revoke').click()
		await expect(keys.filter({ hasText: oldLabel }), 'the old key is revoked').toHaveCount(0, { timeout: 15_000 })
		await expect(keys.filter({ hasText: newLabel }), 'the new key stays').toBeVisible()
	})
})
