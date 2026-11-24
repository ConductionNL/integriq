/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-24-gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md
 * (REQ-APGU-001, product usage is split per consumer and per endpoint).
 *
 * What an administrator does here: open an API product's detail page and read
 * its usage in the Analytics section, once per consumer and once per
 * endpoint, next to the product total. A non-administrator asking for the
 * same figures is refused.
 *
 * Traffic is staged as the inbound call_log rows EndpointService writes for a
 * proxied call (product, consumer, endpoint, status code, response time),
 * because driving real gateway calls needs consumer credentials a browser run
 * does not hold. How a live call records its consumer, and the computation of
 * the figures and percentiles, are covered by PHPUnit on
 * ProductUsageFigures, ProductSubscriptionsController and EndpointService.
 *
 * Everything is seeded through OpenRegister's object API under this run's
 * marker and deleted again afterwards, the extra account included.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { anonymousRequest } from './support/anonymous.ts'
import { BASE_URL } from './support/baseUrl.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const OUTSIDER = 'e2e-product-usage-outsider'
const OUTSIDER_PASSWORD = process.env.INTEGRIQ_E2E_DEV_PASS ?? 'Usage-e2e-1!'

/** This run's marker, so the objects are this run's and nothing else's. */
const RUN = `e2e-usage-${Date.now()}`

const PARTNER_FAILING = `${RUN} Partner A`
const PARTNER_STEADY = `${RUN} Partner B`
const ENDPOINT_BUSY = `${RUN} zaken`
const ENDPOINT_SLOW = `${RUN} documenten`
const ENDPOINT_QUIET = `${RUN} besluiten`

/** One staged call: who called which endpoint, with what result and how fast. */
interface StagedCall {
	consumer: string
	endpoint: string
	statusCode: number
	responseTime: number
}

/**
 * The staged traffic. Partner A makes five calls of which one fails; Partner
 * B makes three that all succeed. The documenten endpoint answers in about
 * two seconds, the other two within a hundred milliseconds. Request counts
 * per endpoint are 4, 3 and 1, so the order of the list is unambiguous.
 */
const CALLS: StagedCall[] = [
	{ consumer: PARTNER_FAILING, endpoint: ENDPOINT_BUSY, statusCode: 200, responseTime: 40 },
	{ consumer: PARTNER_FAILING, endpoint: ENDPOINT_BUSY, statusCode: 200, responseTime: 60 },
	{ consumer: PARTNER_FAILING, endpoint: ENDPOINT_QUIET, statusCode: 200, responseTime: 80 },
	{ consumer: PARTNER_FAILING, endpoint: ENDPOINT_SLOW, statusCode: 500, responseTime: 2000 },
	{ consumer: PARTNER_FAILING, endpoint: ENDPOINT_SLOW, statusCode: 200, responseTime: 2100 },
	{ consumer: PARTNER_STEADY, endpoint: ENDPOINT_BUSY, statusCode: 200, responseTime: 50 },
	{ consumer: PARTNER_STEADY, endpoint: ENDPOINT_BUSY, statusCode: 200, responseTime: 55 },
	{ consumer: PARTNER_STEADY, endpoint: ENDPOINT_SLOW, statusCode: 200, responseTime: 1900 },
]

/** What each seeded object is, keyed by name, and every id to delete afterwards. */
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
async function seedObject(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data,
	})
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, `the seeded ${schema} has an id`).toBe('string')
	cleanup.push({ schema, id: id as string })

	return id as string
}

/**
 * The product's analytics, as the detail page reads them.
 *
 * @param request   A request context.
 * @param productId The product's OpenRegister id.
 * @return The response.
 */
async function readAnalytics(request: APIRequestContext, productId: string) {
	return request.get(`${APP_BASE}/api/products/${productId}/analytics`, {
		failOnStatusCode: false,
		headers: HEADERS,
	})
}

/**
 * A request context logged in as one account, with no admin session.
 *
 * @param userId The account id.
 * @return A context the caller must dispose.
 */
async function asAccount(userId: string): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: OUTSIDER_PASSWORD, send: 'always' },
		extraHTTPHeaders: HEADERS,
	})
}

test.describe('gateway-usage-per-consumer-and-endpoint: usage of one API product', () => {
	test.beforeAll(async ({ request }) => {
		ids.product = await seedObject(request, 'api_product', {
			name: `${RUN} Zaken-API`,
			productSlug: RUN,
			version: '1.0.0',
			visibility: 'public',
			status: 'active',
			endpoints: [],
			tiers: {},
		})
		for (const name of [PARTNER_FAILING, PARTNER_STEADY]) {
			ids[name] = await seedObject(request, 'consumer', { name })
		}
		for (const name of [ENDPOINT_BUSY, ENDPOINT_SLOW, ENDPOINT_QUIET]) {
			ids[name] = await seedObject(request, 'endpoint', {
				name,
				endpoint: `/${RUN}/${name.split(' ').pop()}`,
				method: 'GET',
			})
		}
		for (const call of CALLS) {
			await seedObject(request, 'call_log', {
				direction: 'inbound',
				product: ids.product,
				consumer: ids[call.consumer],
				endpoint: ids[call.endpoint],
				statusCode: call.statusCode,
				statusMessage: `${RUN} ${call.endpoint}`,
				responseTime: call.responseTime,
				created: new Date().toISOString(),
			})
		}
	})

	test.afterAll(async ({ request }) => {
		// Rows first, then what they point at, then the product.
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
		await request.delete(`/index.php/ocs/v2.php/cloud/users/${OUTSIDER}?format=json`, {
			failOnStatusCode: false,
			headers: HEADERS,
		})
	})

	// @e2e api-product-gateway::an-administrator-sees-which-partner-causes-the-errors
	test('the consumer list shows each partner with its own request count and error rate, adding up to the total', async ({
		page,
		request,
	}) => {
		const resp = await readAnalytics(request, ids.product)
		expect(resp.status()).toBe(200)
		const analytics = await resp.json()
		const consumerSum = (analytics.byConsumer as Array<{ requestCount: number }>)
			.reduce((sum, entry) => sum + entry.requestCount, 0)
		expect(analytics.requestCount, 'the product total').toBe(CALLS.length)
		expect(consumerSum, 'the consumer entries add up to the product total').toBe(analytics.requestCount)

		await page.goto(`${APP_BASE}/products/${ids.product}`, { waitUntil: 'domcontentloaded' })
		const summary = page.getByTestId('analytics-summary')
		await expect(summary).toBeVisible({ timeout: 30_000 })
		await expect(summary).toContainText(String(CALLS.length))
		await expect(summary).toContainText('12.50%')

		const consumers = page.getByTestId('analytics-consumer')
		await expect(consumers.getByRole('heading', { name: 'Per consumer' })).toBeVisible()
		await expect(
			consumers.getByRole('rowheader'),
			'highest request count first',
		).toHaveText([PARTNER_FAILING, PARTNER_STEADY])

		// Cells after the row header: requests, error rate, p50, p95, p99.
		const failing = consumers.getByRole('row').filter({ hasText: PARTNER_FAILING }).getByRole('cell')
		await expect(failing.nth(0)).toHaveText('5')
		await expect(failing.nth(1), 'one call in five fails').toHaveText('20.00%')

		const steady = consumers.getByRole('row').filter({ hasText: PARTNER_STEADY }).getByRole('cell')
		await expect(steady.nth(0)).toHaveText('3')
		await expect(steady.nth(1), 'none of its calls fail').toHaveText('0.00%')
	})

	// @e2e api-product-gateway::an-administrator-sees-which-endpoint-is-slow
	test('the endpoint list shows the slow endpoint with its own p95 response time', async ({ page }) => {
		await page.goto(`${APP_BASE}/products/${ids.product}`, { waitUntil: 'domcontentloaded' })
		const endpoints = page.getByTestId('analytics-endpoint')
		await expect(endpoints).toBeVisible({ timeout: 30_000 })
		await expect(endpoints.getByRole('heading', { name: 'Per endpoint' })).toBeVisible()
		await expect(
			endpoints.getByRole('rowheader'),
			'highest request count first',
		).toHaveText([ENDPOINT_BUSY, ENDPOINT_SLOW, ENDPOINT_QUIET])

		const slow = endpoints.getByRole('row').filter({ hasText: ENDPOINT_SLOW }).getByRole('cell')
		await expect(slow.nth(0)).toHaveText('3')
		await expect(slow.nth(3), 'the slow endpoint\'s own p95').toHaveText('2100 ms')

		const busy = endpoints.getByRole('row').filter({ hasText: ENDPOINT_BUSY }).getByRole('cell')
		await expect(busy.nth(3), 'a fast endpoint keeps its own p95').toHaveText('60 ms')
	})

	// @e2e api-product-gateway::a-subscriber-cannot-read-another-subscribers-figures
	test('an account that is not an administrator is refused and gets no consumer entry', async ({ request }) => {
		await request.post('/index.php/ocs/v2.php/cloud/users?format=json', {
			failOnStatusCode: false,
			headers: HEADERS,
			form: { userid: OUTSIDER, password: OUTSIDER_PASSWORD },
		})
		const outsider = await asAccount(OUTSIDER)
		try {
			const resp = await readAnalytics(outsider, ids.product)
			expect(resp.status()).toBe(403)
			expect(await resp.text()).not.toContain('byConsumer')
		} finally {
			await outsider.dispose()
		}

		const anonymous = await anonymousRequest(HEADERS)
		try {
			const resp = await readAnalytics(anonymous, ids.product)
			expect(resp.status()).toBeGreaterThanOrEqual(401)
			expect(await resp.text()).not.toContain('byConsumer')
		} finally {
			await anonymous.dispose()
		}
	})
})
