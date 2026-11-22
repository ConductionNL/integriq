/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md
 * (REQ-UPRT-003, the call log records which target served a call; the
 * Playwright half of task 4 in that change's tasks.md).
 *
 * What an administrator does here: open an api endpoint with a canary split
 * in its edit dialog (EndpointTargetsFields, on the Endpoints page) and see
 * both targets with their share of the calls, add a target, and open the
 * endpoint's page to read how many calls each target served (the "Calls per
 * target" chart over call_log.routedTarget).
 *
 * Traffic is staged as the inbound call_log rows EndpointService writes for a
 * routed call (endpoint, routedTarget, routedReason), because routing real
 * calls needs two reachable upstreams a browser run does not have. Which
 * target a call is routed to, and what the call log records, are covered by
 * PHPUnit on UpstreamSelector, UpstreamRouter and EndpointService.
 *
 * Runs as the admin the global setup logs in. Everything is seeded through
 * OpenRegister's object API under this run's marker and deleted afterwards.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-targets-${Date.now().toString(36)}`

const ENDPOINT_NAME = `E2E canary ${RUN}`
const PRIMARY = `E2E primary ${RUN}`
const CANARY = `E2E canary source ${RUN}`

/** The staged traffic: nineteen calls to the primary, one to the canary. */
const PRIMARY_CALLS = 19
const CANARY_CALLS = 1

const ids: Record<string, string> = {}
const cleanup: Array<{ schema: string, id: string }> = []

/**
 * Create one object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request The request fixture.
 * @param schema  The schema slug.
 * @param data    The object.
 * @return The object's OpenRegister id.
 */
async function seedObject(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { failOnStatusCode: false, headers: HEADERS, data })
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, `the seeded ${schema} has an id`).toBe('string')
	cleanup.push({ schema, id: id as string })

	return id as string
}

/**
 * Find this file's endpoint on the Endpoints page, walking the pager.
 *
 * @param page The page, on the Endpoints route.
 * @return The row.
 */
async function endpointRow(page: Page): Promise<Locator> {
	const row = page.getByRole('row').filter({ hasText: ENDPOINT_NAME }).first()
	for (let hop = 0; hop < 20; hop++) {
		if (await row.isVisible({ timeout: 2_000 }).catch(() => false)) {
			break
		}
		const next = page.getByRole('button', { name: 'Next', exact: true }).first()
		if ((await next.isEnabled({ timeout: 2_000 }).catch(() => false)) === false) {
			break
		}
		await next.click()
		await page.waitForTimeout(900)
	}
	await expect(row, 'the endpoint is listed on the Endpoints page').toBeVisible({ timeout: 20_000 })

	return row
}

/**
 * Open the endpoint's edit dialog from the Endpoints page.
 *
 * @param page The page.
 * @return The dialog, with its Upstream section shown.
 */
async function openEndpointEditor(page: Page): Promise<Locator> {
	await gotoAppRoute(page, '/endpoints')
	await expectRouteMatched(page, '/endpoints')
	const row = await endpointRow(page)
	await row.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()

	const dialog = appDialog(page)
	await expect(dialog, 'the edit dialog opens').toBeVisible({ timeout: 15_000 })
	await expect(dialog.getByTestId('endpoint-targets-fields')).toBeVisible({ timeout: 15_000 })

	return dialog
}

test.describe('gateway-upstream-routing: an endpoint with several targets', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		ids.primary = await seedObject(request, 'source', { name: PRIMARY, type: 'json', location: 'https://primary.invalid' })
		ids.canary = await seedObject(request, 'source', { name: CANARY, type: 'json', location: 'https://canary.invalid' })
		ids.endpoint = await seedObject(request, 'endpoint', {
			name: ENDPOINT_NAME,
			endpoint: `${RUN}/zaken`,
			method: 'GET',
			targetType: 'api',
			targets: [
				{ source: ids.primary, weight: 95 },
				{ source: ids.canary, weight: 5 },
			],
		})
		const calls = [
			...Array.from({ length: PRIMARY_CALLS }, () => ids.primary),
			...Array.from({ length: CANARY_CALLS }, () => ids.canary),
		]
		for (const target of calls) {
			await seedObject(request, 'call_log', {
				direction: 'inbound',
				endpoint: ids.endpoint,
				routedTarget: target,
				routedReason: 'weight',
				statusCode: 200,
				statusMessage: `${RUN} routed`,
				created: new Date().toISOString(),
			})
		}
	})

	test.afterAll(async ({ request }) => {
		// Rows first, then the endpoint, then the sources.
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	test('the edit dialog shows both targets with their share and adds a third', async ({ page }) => {
		const dialog = await openEndpointEditor(page)
		const targets = dialog.getByTestId('endpoint-target')
		await expect(targets).toHaveCount(2)
		await expect(targets.nth(0)).toContainText(PRIMARY)
		await expect(targets.nth(1)).toContainText(CANARY)
		await expect(dialog.getByTestId('endpoint-target-share')).toHaveText(['95% of its group', '5% of its group'])
		await expect(dialog.getByTestId('endpoint-sticky'), 'two targets offer the sticky switch').toBeVisible()

		await dialog.getByTestId('endpoint-add-target').click()
		await expect(targets).toHaveCount(3)
	})

	// @e2e endpoint-runtime::an-administrator-watches-a-canary
	test('the endpoint page shows how many calls each target served', async ({ page }) => {
		await gotoAppRoute(page, `/endpoints/${ids.endpoint}`)
		const chart = page.getByTestId('cn-detail-widget-chart-card').filter({ hasText: 'Calls per target' })
		await expect(chart).toBeVisible({ timeout: 30_000 })
		await expect(chart).toContainText(PRIMARY)
		await expect(chart).toContainText(CANARY)
	})
})
