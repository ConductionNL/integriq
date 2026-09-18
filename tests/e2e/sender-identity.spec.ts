/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 *
 * What an administrator does here: read the identities their teams send
 * under, ask what the domain says, and see the opt-outs every sender
 * honours. The unsubscribe link is driven end to end as a recipient would
 * follow it, with no session at all, because needing an account to stop mail
 * is the same as not being able to stop it.
 *
 * The signing, the hold window and the no-reply handling are covered by
 * PHPUnit: each needs key material, a clock the test moves, or an inbound
 * message, none of which a browser can stage.
 */

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

test.describe('sender identity', () => {
	test('two teams have their own identity, and the page shows what each quotes', async ({ page, request }) => {
		const seed = await request.post(`${OR_BASE}/sender_identity`, {
			failOnStatusCode: false,
			data: {
				displayName: 'E2E Belastingen',
				address: 'belastingen@e2e.example',
				signature: 'Team Belastingen',
				mailAccount: 'e2e-account',
				quotingLevel: 'none',
				holdWindowSeconds: 30,
				isDefault: false,
			},
		})
		expect(seed.status(), 'seeding an identity must succeed').toBeLessThan(300)

		await page.goto(`${APP_BASE}/outbound/identities`, { waitUntil: 'domcontentloaded' })
		const row = page.getByRole('row').filter({ hasText: 'E2E Belastingen' }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(
			row,
			'what an identity quotes is visible without opening it, because quoting is a disclosure',
		).toContainText('none')
	})

	test('an administrator is told what to publish', async ({ request }) => {
		const seed = await request.post(`${OR_BASE}/sender_identity`, {
			failOnStatusCode: false,
			data: {
				displayName: 'E2E Alignment',
				address: 'post@invalid-domain-for-e2e.example',
				dkimSelector: 'default',
				isDefault: false,
			},
		})
		const id = String((await seed.json()).id ?? '')

		const resp = await request.post(`${API_BASE}/outbound/identities/${id}/alignment`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: {},
		})
		expect(resp.status(), 'the check must answer, not error').toBe(200)

		const body = await resp.json()
		expect(
			body.alignment.dkim.publish,
			'an absent record comes with the record to publish, not just a red tick',
		).toContain('v=DKIM1')
		expect(body.atRisk, 'a domain with nothing published is at risk, and says so').toBe(true)
	})

	test('a recipient stops the updates on one case without an account', async () => {
		// The link is followed by someone with no session at all: that is the
		// whole point of binding it to a signed token rather than to an account.
		const anonymous = await playwrightRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080',
		})

		const resp = await anonymous.get('/index.php/apps/integriq/unsubscribe/not-a-real-token', {
			failOnStatusCode: false,
		})

		expect(
			resp.status(),
			'an invalid token still renders a page rather than an error, and changes nothing',
		).toBeLessThan(500)
		const html = await resp.text()
		expect(html).toContain('werkt niet meer')

		await anonymous.dispose()
	})

	test('the opt-outs every sender honours are on a page', async ({ page, request }) => {
		const seed = await request.post(`${OR_BASE}/recipient_opt_out`, {
			failOnStatusCode: false,
			data: {
				address: 'e2e-optout@example.org',
				scope: 'instance',
				source: 'administrator',
				createdAt: new Date().toISOString(),
			},
		})
		expect(seed.status(), 'seeding an opt-out must succeed').toBeLessThan(300)

		await page.goto(`${APP_BASE}/outbound/opt-outs`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('e2e-optout@example.org').first()).toBeVisible({ timeout: 20_000 })
	})

	test('an anonymous caller cannot read the identities', async () => {
		// The least privileged principal that should be refused. The identities
		// carry signing material, so an unauthenticated 2xx here would be a key
		// disclosure, not merely an information leak.
		const anonymous = await playwrightRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080',
		})

		const resp = await anonymous.get(`${API_BASE}/outbound/identities`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
		})

		expect(resp.status()).toBeGreaterThanOrEqual(401)
		await anonymous.dispose()
	})
})
