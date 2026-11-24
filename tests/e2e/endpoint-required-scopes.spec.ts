/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * access-oauth-and-token-validation, REQ-TOKV-005: an administrator adding a
 * scope to an endpoint's authentication rule sees, in the save dialog, the
 * consumers that lack it. The fixture endpoint has no recorded calls, so the
 * dialog lists every consumer without the scope; the call-log narrowing is
 * covered by vitest (tests/vitest/scopeLockout.spec.js).
 *
 * Runs as the admin the global setup signs in. The consumer, rule and
 * endpoint are made through OpenRegister's object API and removed at the end.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/**
 * Create an object and return it.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param data The object.
 * @return The stored object.
 */
async function create(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<Record<string, unknown>> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { data, failOnStatusCode: false })
	expect(resp.status(), `create ${schema}`).toBeLessThan(300)
	return resp.json()
}

/**
 * The id of a stored object.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: Record<string, unknown>): string {
	return String(object.id ?? object.uuid ?? (object['@self'] as Record<string, unknown> | undefined)?.id)
}

/**
 * The stored `requiredScopes` of a rule.
 *
 * @param request The request fixture.
 * @param id The rule id.
 * @return The value.
 */
async function requiredScopesOf(request: APIRequestContext, id: string): Promise<unknown> {
	const resp = await request.get(`${OR_BASE}/rule/${id}`)
	const rule = await resp.json()
	return rule?.configuration?.authentication?.requiredScopes ?? []
}

test.describe('access-oauth-and-token-validation: who a new scope requirement locks out', () => {
	const cleanup: Array<[string, string]> = []
	let ruleId = ''
	let consumerName = ''

	test.beforeEach(async ({ request }) => {
		const stamp = Date.now()
		consumerName = `e2e-lockout-${stamp}`
		const consumer = await create(request, 'consumer', { name: consumerName, scopes: ['zaken:read'] })
		cleanup.push(['consumer', idOf(consumer)])
		const rule = await create(request, 'rule', {
			name: `e2e-lockout-rule-${stamp}`,
			action: 'POST',
			timing: 'before',
			type: 'authentication',
			configuration: { type: 'authentication', authentication: { type: 'jwt' } },
		})
		ruleId = idOf(rule)
		cleanup.push(['rule', ruleId])
		const endpoint = await create(request, 'endpoint', {
			name: `e2e-lockout-endpoint-${stamp}`,
			endpoint: `e2e/lockout/${stamp}`,
			method: 'POST',
			rules: [ruleId],
		})
		cleanup.push(['endpoint', idOf(endpoint)])
	})

	test.afterEach(async ({ request }) => {
		for (const [schema, id] of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e consumer-management::an-administrator-sees-who-a-new-requirement-locks-out
	test('the save dialog lists a consumer without the added scope, and Cancel keeps the rule', async ({ page, request }) => {
		await page.goto(`${APP_BASE}/rules/${ruleId}`, { waitUntil: 'domcontentloaded' })
		const write = page.getByTestId('rule-required-scopes-write').locator('input')
		await expect(write).toBeVisible({ timeout: 15_000 })
		await write.fill('zaken:write')

		await page.getByTestId('rule-save').click()
		const dialog = page.getByTestId('rule-scope-lockout')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		const row = dialog.getByTestId('rule-scope-lockout-consumer').filter({ hasText: consumerName })
		await expect(row).toContainText('zaken:write')

		await dialog.getByTestId('rule-scope-lockout-cancel').click()
		await expect(dialog).toBeHidden()
		expect(await requiredScopesOf(request, ruleId)).toEqual([])
	})

	// @e2e consumer-management::an-administrator-sees-who-a-new-requirement-locks-out
	test('Save anyway stores the requirement', async ({ page, request }) => {
		await page.goto(`${APP_BASE}/rules/${ruleId}`, { waitUntil: 'domcontentloaded' })
		const write = page.getByTestId('rule-required-scopes-write').locator('input')
		await expect(write).toBeVisible({ timeout: 15_000 })
		await write.fill('zaken:write')

		await page.getByTestId('rule-save').click()
		const dialog = page.getByTestId('rule-scope-lockout')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('rule-scope-lockout-confirm').click()
		await expect(dialog).toBeHidden()

		await expect.poll(async () => requiredScopesOf(request, ruleId), { timeout: 10_000 })
			.toEqual({ POST: ['zaken:write'], PUT: ['zaken:write'], PATCH: ['zaken:write'], DELETE: ['zaken:write'] })
	})
})
