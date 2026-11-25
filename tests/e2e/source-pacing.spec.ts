/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md
 * (REQ-RLPC-001, task 3: the source page).
 *
 * An administrator opens a paced source and sees the pace, the budget the
 * source announced and the time calls waited for a paced turn over the last
 * day. The source is seeded with a configured pace and an announced
 * `rateLimitRemaining`; one call-log entry records a paced wait, so the
 * `src-kpis-pacing` widget has a known sum to show. The live 429 and the
 * shared budget are covered by Newman
 * (tests/postman/integriq-source-pacing.postman_collection.json).
 *
 * Base URL comes from the environment (tests/e2e/playwright.config.ts).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './support/appRoot.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * Create one object through OpenRegister's object API.
 *
 * @param request The Playwright request context.
 * @param schema The integriq schema slug.
 * @param data The object.
 * @return The object's id.
 */
async function create(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { failOnStatusCode: false, headers: HEADERS, data })
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = (await resp.json()) as Record<string, unknown>

	return String(body.id ?? (body['@self'] as Record<string, string>)?.id)
}

test.describe.serial('source pacing on the source page', () => {
	const stamp = Date.now()
	let sourceId = ''
	let logId = ''

	test.beforeAll(async ({ request }) => {
		sourceId = await create(request, 'source', {
			name: `E2E paced source ${stamp}`,
			description: 'Seeded by tests/e2e/source-pacing.spec.ts',
			type: 'api',
			isEnabled: true,
			auth: 'none',
			location: `https://paced-${stamp}.e2e.invalid/api`,
			pace: { maxCalls: 100, perSeconds: 60, burst: 1 },
			rateLimitRemaining: 37,
		})
		logId = await create(request, 'call_log', {
			source: sourceId,
			method: 'GET',
			statusCode: 200,
			pacedMs: 1234,
			created: new Date().toISOString(),
		})
	})

	test.afterAll(async ({ request }) => {
		await request.delete(`${OR_BASE}/call_log/${logId}`, { failOnStatusCode: false, headers: HEADERS })
		await request.delete(`${OR_BASE}/source/${sourceId}`, { failOnStatusCode: false, headers: HEADERS })
	})

	test('a paced source shows its budget and the time waited for a turn', async ({ page }) => {
		// @e2e sources-outbound-rate-limit-pacing/http-call-engine::calls-to-a-source-are-spaced-to-stay-under-its-limit
		await gotoAppRoute(page, `/sources/${sourceId}`)
		await expect(page.getByText(`E2E paced source ${stamp}`).first()).toBeVisible({ timeout: 30_000 })

		// The dashboard widgets render as groups named after their manifest id.
		const pacing = page.getByRole('group', { name: 'src-kpis-pacing' })
		await expect(pacing).toBeVisible({ timeout: 30_000 })
		await expect(pacing.getByRole('heading', { name: 'Pacing' })).toBeVisible()
		await expect(pacing.getByRole('heading', { name: 'Budget left' })).toBeVisible()
		await expect(pacing).toContainText(/37\s*calls in this window/)
		await expect(pacing.getByRole('heading', { name: 'Waited' })).toBeVisible()
		await expect(pacing).toContainText(/1[.,]?234\s*ms for a paced turn, last day/)

		// The configured pace is an object property; the Connection widget names its
		// three numbers through dotted field overrides in src/manifest.json.
		const connection = page.getByRole('group', { name: 'src-data' })
		await connection.getByRole('button', { name: /^Show all \d+ fields$/ }).click()
		await expect(connection.getByRole('button', { name: 'Edit Pace: calls per window' })).toHaveText('100')
		await expect(connection.getByRole('button', { name: 'Edit Pace: window (seconds)' })).toHaveText('60')
		await expect(connection.getByRole('button', { name: 'Edit Pace: burst' })).toHaveText('1')
	})
})
