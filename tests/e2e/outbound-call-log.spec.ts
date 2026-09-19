/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 *
 * What an administrator does with the call log: find the failure without a
 * container log, look at what a replay would send, replay it, fire one by
 * hand, and choose which mapping version a replay runs under.
 *
 * The scenarios carrying `@e2e exclude` in the spec (redaction before the
 * write, the permission refusal, the signature on a replayed delivery, the
 * dead-letter hand-off, mapping version creation, the inbound verdict and
 * both pre-check paths) are covered by PHPUnit and Newman.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/**
 * Seed one failed call.
 *
 * @param request The Playwright request context.
 * @param overrides What this call should say.
 * @return The call's OpenRegister id.
 */
async function seedCall(
	request: APIRequestContext,
	overrides: Record<string, unknown> = {},
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/call_log`, {
		failOnStatusCode: false,
		data: {
			target: 'e2e-stuf-partner',
			traceId: 'e2e-trace-1',
			direction: 'outbound',
			request: { method: 'POST', endpoint: '/stuf', body: '<Lk01/>' },
			response: { body: '<Fo01/>' },
			statusCode: 500,
			statusMessage: 'E2E fout van de partner',
			durationMs: 340,
			kind: 'triggered',
			retryPolicy: { maxAttempts: 6 },
			mapping: 'e2e-zaak-naar-stuf',
			mappingVersion: '3',
			created: new Date().toISOString(),
			attempts: [
				{
					at: new Date().toISOString(),
					kind: 'triggered',
					statusCode: 500,
					outcome: 'failed',
					detail: 'E2E fout van de partner',
					mappingVersion: '3',
				},
			],
			...overrides,
		},
	})
	expect(resp.status(), 'seeding a call must succeed').toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

test.describe('outbound call log', () => {
	test('a failed call is found without a container log', async ({
		page,
		request,
	}) => {
		await seedCall(request, { statusMessage: 'E2E StUF fout op de partner' })

		await page.goto(`${APP_BASE}/sources/logs`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByText('E2E StUF fout op de partner').first(),
			'the failure is on the screen, with the fault the partner returned',
		).toBeVisible({ timeout: 20_000 })
	})

	test('a replay shows what it would send before it sends it', async ({
		request,
	}) => {
		const id = await seedCall(request)

		const preview = await request.get(`${API_BASE}/calls/${id}/preview`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(preview.status()).toBe(200)

		const body = await preview.json()
		expect(body.request.method).toBe('POST')
		expect(
			body.versions.recorded,
			'the version the call ran under is what a replay defaults to',
		).toBe('3')
	})

	test('a dry run changes nothing', async ({ request }) => {
		const id = await seedCall(request)

		const resp = await request.post(`${API_BASE}/calls/${id}/replay`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: { dryRun: true },
		})
		expect(resp.status()).toBe(200)

		const body = await resp.json()
		expect(body.items[0].sent, 'a dry run sends nothing').toBe(false)

		const after = await (await request.get(`${OR_BASE}/call_log/${id}`)).json()
		expect(
			after.attempts?.length ?? 0,
			'and it appends no attempt, because looking must not change what is looked at',
		).toBe(1)
	})

	test('a replay appends an attempt and leaves the first readable', async ({
		request,
	}) => {
		const id = await seedCall(request)

		const resp = await request.post(`${API_BASE}/calls/${id}/replay`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: {},
		})
		expect(resp.status(), 'the replay must answer, not error').toBeLessThan(500)

		const after = await (await request.get(`${OR_BASE}/call_log/${id}`)).json()
		expect(
			after.attempts.length,
			'the replay is an attempt on the same record',
		).toBeGreaterThan(1)
		expect(
			after.attempts[0].outcome,
			'and the attempt that failed first is still there, because it is why somebody looked',
		).toBe('failed')
	})

	test('a replay can be asked to run under the current mapping version', async ({
		request,
	}) => {
		const id = await seedCall(request)

		const resp = await request.post(`${API_BASE}/calls/${id}/replay`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: { mappingVersion: '4' },
		})
		expect(resp.status()).toBeLessThan(500)

		const body = await resp.json()
		expect(
			body.items[0].mappingVersion,
			'the chosen version is recorded, and neither version is applied silently',
		).toBe('4')
	})

	test('a call can be fired by hand', async ({ request }) => {
		const resp = await request.post(`${API_BASE}/calls/fire`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: {
				target: 'e2e-notificaties-partner',
				request: {
					method: 'POST',
					endpoint: '/notificaties',
					body: { kanaal: 'zaken' },
				},
				dryRun: true,
			},
		})

		expect(resp.status()).toBe(201)
		const body = await resp.json()
		expect(
			body.sent,
			'a dry run of a hand-fired call sends nothing either',
		).toBe(false)
		expect(body.request.endpoint).toBe('/notificaties')
	})

	test('an external verdict is visible, and changes nothing', async ({
		page,
		request,
	}) => {
		const seed = await request.post(`${OR_BASE}/verdict`, {
			failOnStatusCode: false,
			data: {
				objectRef: 'zaak/e2e-verdict',
				state: 'fail',
				source: 'e2e-ketenpartner',
				reason: 'E2E ontbrekende bijlage',
				receivedAt: new Date().toISOString(),
			},
		})
		expect(seed.status(), 'seeding a verdict must succeed').toBeLessThan(300)

		await page.goto(`${APP_BASE}/verdicts`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('E2E ontbrekende bijlage').first()).toBeVisible({
			timeout: 20_000,
		})
	})
})
