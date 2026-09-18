/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-the-retry-schedule-is-configuration-per-connection-req-ocd-004
 *
 * One partner asks for six attempts over a day, another refuses more than
 * three. The schedule is therefore configuration on the connection, and the
 * policy that governed a call is recorded on it, so a call that stopped after
 * two attempts can still be explained a month later.
 *
 * The exhaustion hand-off to the dead-letter list is covered by PHPUnit; it
 * needs a call that actually fails its last attempt, which a browser cannot
 * stage against a real partner.
 */

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

test.describe('outbound call retry policy', () => {
	test('a connection gets its own schedule, and no release is needed', async ({ request }) => {
		const created = await request.post(`${OR_BASE}/source`, {
			failOnStatusCode: false,
			data: {
				name: 'E2E Digikoppeling partner',
				description: 'Seeded by tests/e2e/outbound-call-retry-policy.spec.ts',
				type: 'api',
				isEnabled: true,
				location: 'https://partner.e2e.example',
				retryPolicy: {
					maxAttempts: 6,
					backoffStrategy: 'exponential',
					baseDelayMs: 1000,
					maxDelayMs: 3600000,
				},
			},
		})
		expect(created.status(), 'seeding the connection must succeed').toBeLessThan(300)
		const id = String((await created.json()).id ?? '')

		// Changing the schedule is an edit to the connection, not a release.
		const updated = await request.put(`${OR_BASE}/source/${id}`, {
			failOnStatusCode: false,
			data: {
				name: 'E2E Digikoppeling partner',
				type: 'api',
				isEnabled: true,
				location: 'https://partner.e2e.example',
				retryPolicy: { maxAttempts: 3, backoffStrategy: 'fixed', baseDelayMs: 500 },
			},
		})
		expect(updated.status(), 'the schedule is editable in place').toBeLessThan(300)

		const after = await (await request.get(`${OR_BASE}/source/${id}`)).json()
		expect(after.retryPolicy.maxAttempts).toBe(3)
	})

	test('the policy that governed a call is on the call', async ({ page, request }) => {
		const seeded = await request.post(`${OR_BASE}/call_log`, {
			failOnStatusCode: false,
			data: {
				target: 'e2e-digikoppeling-partner',
				direction: 'outbound',
				request: { method: 'POST', endpoint: '/bericht' },
				response: { body: 'timeout' },
				statusCode: 504,
				statusMessage: 'E2E partner antwoordde niet',
				kind: 'triggered',
				retryPolicy: { maxAttempts: 6, backoffStrategy: 'exponential' },
				created: new Date().toISOString(),
				attempts: [
					{ at: new Date().toISOString(), kind: 'triggered', statusCode: 504, outcome: 'failed' },
					{ at: new Date().toISOString(), kind: 'triggered', statusCode: 504, outcome: 'failed' },
				],
			},
		})
		expect(seeded.status(), 'seeding the call must succeed').toBeLessThan(300)
		const id = String((await seeded.json()).id ?? '')

		const record = await (await request.get(`${OR_BASE}/call_log/${id}`)).json()
		expect(
			record.retryPolicy.maxAttempts,
			'a call that stopped after two attempts names the policy that let it stop',
		).toBe(6)

		await page.goto(`${APP_BASE}/sources/logs`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('E2E partner antwoordde niet').first()).toBeVisible({ timeout: 20_000 })
	})
})
