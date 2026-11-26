/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The administrator finds the slow GraphQL operation in the endpoint call log
 * (gateway-graphql-and-streaming-protocols, REQ-GQLP-001, task 2).
 *
 * WHAT IS DRIVEN. Calls of two operations through one graphql endpoint are
 * seeded as inbound call_log rows (the rows EndpointService writes for a
 * proxied GraphQL call, with graphqlOperationType and graphqlOperationName),
 * then the Endpoint logs page is filtered by operation, once through the
 * address (a filtered view is a link) and once through the filter control.
 * Only zakenLijst's calls must be listed, with their durations.
 *
 * WHAT IT LEAVES. The seeded call_log rows are deleted afterwards.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/integriq'
const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** This run's marker, so the rows are this run's and nothing else's. */
const RUN = `e2e-gql-${Date.now()}`

/** The seeded calls: two of zakenLijst, one of zaakDetails. */
const CALLS = [
	{ graphqlOperationName: 'zakenLijst', responseTime: 2400 },
	{ graphqlOperationName: 'zakenLijst', responseTime: 1900 },
	{ graphqlOperationName: 'zaakDetails', responseTime: 120 },
]

const seeded: string[] = []

/**
 * Seed one inbound GraphQL call.
 *
 * @param request The request context (signed in as the administrator).
 * @param call The operation and its duration.
 * @return The row's id.
 */
async function seedCall(
	request: APIRequestContext,
	call: { graphqlOperationName: string, responseTime: number },
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/call_log`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: {
			direction: 'inbound',
			statusCode: 200,
			statusMessage: `${RUN} ${call.graphqlOperationName}`,
			graphqlOperationType: 'query',
			graphqlOperationName: call.graphqlOperationName,
			responseTime: call.responseTime,
			created: new Date().toISOString(),
		},
	})
	expect(resp.status(), 'seeding a call must succeed').toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

test.describe('GraphQL operations in the endpoint call log', () => {
	test.beforeAll(async ({ request }) => {
		for (const call of CALLS) {
			seeded.push(await seedCall(request, call))
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of seeded) {
			await request.delete(`${OR_BASE}/call_log/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e endpoint-runtime::an-administrator-finds-the-slow-operation
	test('filtering by operation lists only that operation\'s calls, with durations', async ({ page }) => {
		await page.goto(`${APP_BASE}/endpoints/logs?graphqlOperationName=zakenLijst`, {
			waitUntil: 'domcontentloaded',
		})

		const logs = page.getByTestId('cn-logs-page')
		await expect(logs.getByText(`${RUN} zakenLijst`).first()).toBeVisible({ timeout: 30_000 })
		await expect(logs.getByText(`${RUN} zakenLijst`)).toHaveCount(2)
		await expect(logs.getByText(`${RUN} zaakDetails`)).toHaveCount(0)

		// The Operation column shows the name, the Duration column a duration.
		const row = logs.locator('tr', { hasText: `${RUN} zakenLijst` }).first()
		await expect(row).toContainText('zakenLijst')
		await expect(row).toContainText(/\d/)
	})

	test('the operation filter control offers the seeded operations and narrows the list', async ({ page }) => {
		await page.goto(`${APP_BASE}/endpoints/logs`, { waitUntil: 'domcontentloaded' })

		const logs = page.getByTestId('cn-logs-page')
		await expect(logs.getByText(`${RUN} zaakDetails`).first()).toBeVisible({ timeout: 30_000 })

		const control = page.getByTestId('cn-logs-page-filter-graphqlOperationName')
		await expect(control, 'the endpoint call log offers a GraphQL operation filter').toBeVisible()
		await control.locator('input').first().click()
		// NcSelect splits a long option label over two spans (name-parts), so the
		// accessible name is not the plain label; match the option by its text.
		await page.getByRole('option').filter({ hasText: 'zakenLijst' }).first().click()

		await expect(page).toHaveURL(/graphqlOperationName=zakenLijst/)
		await expect(logs.getByText(`${RUN} zaakDetails`)).toHaveCount(0)
		await expect(logs.getByText(`${RUN} zakenLijst`)).toHaveCount(2)
	})
})
