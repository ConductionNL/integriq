/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md
 * (REQ-DEVP-004, subscribers are told when a product they use changes; the
 * Playwright half of task 4 in that change's tasks.md: "one notification
 * observed in the Nextcloud notifications list").
 *
 * What an administrator does here: mark an API product deprecated with a
 * sunset date, then open the product's page (ApiProductDetail, route
 * `/products/:id`) and read the Change notices section, which lists who was
 * told what and when. The developer who owns the subscription then finds a
 * Nextcloud notification naming the product.
 *
 * The product, the developer's application (a consumer with `userId` and
 * `ownerKind: developer`) and its active subscription are staged through
 * OpenRegister's object API `/index.php/apps/openregister/api/objects/integriq/<schema>`
 * with `OCS-APIRequest: true`, because building them through the screens
 * needs the developer portal's approval flow, which developer-portal.spec.ts
 * already drives. The product is this run's own, not the seeded "Besluiten
 * API": the seed is already deprecated, so marking it deprecated again would
 * write no notice.
 *
 * The deprecation itself is the same PATCH on the api_product object that
 * the app's screens send. The only screen that edits a product's status and
 * sunset date is the generic API Products index form (manifest page
 * `ApiProducts`, includeFields `status` and `sunsetDate`), whose fields are
 * rendered by @conduction/nextcloud-vue and carry no selector this repository
 * declares, so the test does not guess them.
 *
 * The notification is read through the Nextcloud notifications app's OCS API
 * as the developer, with the developer's own credentials and an empty storage
 * state, not the admin session the project config carries.
 *
 * Left to PHPUnit: one notice per active subscription and the kind chosen
 * for each change (ProductChangeNoticeServiceTest), the listener on product
 * creates and updates (ProductChangeNoticeListenerTest), and a retired product
 * answering 410 (EndpointServiceTierPolicyTest::testARetiredProductAnswersGone).
 * The mail channel is not observed here: a browser run has no mailbox.
 *
 * Runs as the admin the global setup logs in. The notices, the subscription,
 * the consumer, the product and the developer account are removed at the end.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { BASE_URL } from './support/baseUrl.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const OCS = { 'OCS-APIRequest': 'true' }
const NOTIFICATIONS_URL = '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json'
const DEV_GROUP = process.env.INTEGRIQ_DEVELOPER_GROUP ?? 'integriq-developers'
const PASSWORD = process.env.INTEGRIQ_E2E_DEV_PASS ?? 'Notice-Dev-e2e-1!'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-notice-${Date.now().toString(36)}`

/** The developer who owns the subscription. */
const DEVELOPER = `e2e-notice-dev-${Date.now().toString(36)}`

/** The product's name; the notice and the notification must name it. */
const PRODUCT_NAME = `Besluiten API ${RUN}`

/** The sunset date the administrator sets, and the day the notice must name. */
const SUNSET = '2027-01-01T00:00:00+00:00'
const SUNSET_DAY = '2027-01-01'

const ids: Record<string, string> = {}
const cleanup: Array<{ schema: string, id: string }> = []

/**
 * The id of a stored OpenRegister object, whichever key carries it.
 *
 * @param row The object as the API returned it.
 * @return Its id.
 */
function idOf(row: Record<string, any>): string {
	return String(row.id ?? row.uuid ?? row['@self']?.id ?? '')
}

/**
 * Create one integriq object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param data The object.
 * @return The stored object's id.
 */
async function seedObject(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, {
		failOnStatusCode: false,
		headers: OCS,
		data,
	})
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const id = idOf(await resp.json())
	expect(id, `the seeded ${schema} has an id`).not.toBe('')
	cleanup.push({ schema, id })

	return id
}

/**
 * The change notices stored for one product, as ProductChangeNotices.vue reads them.
 *
 * @param request The request fixture.
 * @param productId The api_product's id.
 * @return The notices.
 */
async function noticesOf(request: APIRequestContext, productId: string): Promise<Array<Record<string, any>>> {
	const resp = await request.get(`${OR_BASE}/product_change_notice`, {
		failOnStatusCode: false,
		headers: OCS,
		params: { product: productId, _limit: 100 },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	const body = await resp.json()

	return (Array.isArray(body.results) ? body.results : []) as Array<Record<string, any>>
}

/**
 * A request context for one account: no cookies from the project config, its own credentials.
 *
 * @param userId The account id.
 * @return A context the caller disposes.
 */
async function asAccount(userId: string): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: OCS,
	})
}

test.describe('access-developer-portal-and-subscriptions: a product change reaches its subscribers', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		// The developer: an account in the developer group, as the portal's
		// developers are. "Already exists" answers are ignored.
		await request.post('/ocs/v2.php/cloud/groups?format=json', {
			failOnStatusCode: false,
			headers: OCS,
			form: { groupid: DEV_GROUP },
		})
		const created = await request.post('/ocs/v2.php/cloud/users?format=json', {
			failOnStatusCode: false,
			headers: OCS,
			form: { userid: DEVELOPER, password: PASSWORD },
		})
		expect(created.ok(), `creating ${DEVELOPER}: ${await created.text()}`).toBeTruthy()
		const joined = await request.post(`/ocs/v2.php/cloud/users/${DEVELOPER}/groups?format=json`, {
			failOnStatusCode: false,
			headers: OCS,
			form: { groupid: DEV_GROUP },
		})
		expect(joined.ok(), `adding ${DEVELOPER} to ${DEV_GROUP}`).toBeTruthy()

		ids.product = await seedObject(request, 'api_product', {
			name: PRODUCT_NAME,
			description: 'Seeded by tests/e2e/product-change-notice.spec.ts',
			productSlug: RUN,
			version: '1.0.0',
			visibility: 'public',
			status: 'active',
			endpoints: [],
			tiers: { basic: { rateLimit: { requestsPerWindow: 60, windowSeconds: 60 } } },
			defaultTier: 'basic',
		})
		// The developer's application: a consumer the developer owns. The
		// notice goes to the consumer's userId (ProductChangeNoticeService::recipientOf).
		ids.consumer = await seedObject(request, 'consumer', {
			name: `${RUN} application`,
			description: 'Seeded by tests/e2e/product-change-notice.spec.ts',
			userId: DEVELOPER,
			ownerKind: 'developer',
		})
		ids.subscription = await seedObject(request, 'api_product_subscription', {
			product: ids.product,
			consumer: ids.consumer,
			tier: 'basic',
			status: 'active',
			requesterUserId: DEVELOPER,
			createdAt: new Date().toISOString(),
			activatedAt: new Date().toISOString(),
		})

		expect(await noticesOf(request, ids.product), 'no notice exists before the change').toHaveLength(0)
	})

	test.afterAll(async ({ request }) => {
		if (ids.product) {
			for (const notice of await noticesOf(request, ids.product).catch(() => [])) {
				await request.delete(`${OR_BASE}/product_change_notice/${idOf(notice)}`, {
					failOnStatusCode: false,
					headers: OCS,
				})
			}
		}
		// The subscription first, then what it points at.
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: OCS })
		}
		await request.delete(`/ocs/v2.php/cloud/users/${DEVELOPER}?format=json`, {
			failOnStatusCode: false,
			headers: OCS,
		})
	})

	// @e2e api-product-gateway::a-deprecation-reaches-the-developer-before-the-sunset
	test('a deprecation with a sunset date notifies the developer and the product page lists the notice as sent', async ({
		page,
		request,
	}) => {
		const deprecated = await request.patch(`${OR_BASE}/api_product/${ids.product}`, {
			failOnStatusCode: false,
			headers: OCS,
			data: { status: 'deprecated', sunsetDate: SUNSET },
		})
		expect(deprecated.status(), await deprecated.text()).toBeLessThan(300)

		// One notice for the one active subscription, addressed to its owner.
		await expect
			.poll(async () => (await noticesOf(request, ids.product)).length, {
				message: 'one notice is written for the active subscription',
				timeout: 20_000,
			})
			.toBe(1)
		const [notice] = await noticesOf(request, ids.product)
		expect(notice.recipientUserId, 'the notice goes to the subscription owner').toBe(DEVELOPER)
		expect(notice.kind).toBe('deprecated')
		expect(notice.message, 'the notice names the product').toContain(PRODUCT_NAME)
		expect(notice.message, 'and the sunset date').toContain(SUNSET_DAY)
		expect(notice.sentAt, 'and records when it was sent').toBeTruthy()

		// The developer's own notifications list names the product and the date.
		const developer = await asAccount(DEVELOPER)
		try {
			await expect
				.poll(
					async () => {
						const resp = await developer.get(NOTIFICATIONS_URL, { failOnStatusCode: false })
						if (resp.status() !== 200) {
							return `notifications answered ${resp.status()}`
						}
						const rows = ((await resp.json()).ocs?.data ?? []) as Array<Record<string, unknown>>
						const ours = rows.find((row) => JSON.stringify(row).includes(PRODUCT_NAME))

						return ours ? JSON.stringify(ours) : 'no notification names the product'
					},
					{ message: 'the developer has a notification naming the product', timeout: 30_000 },
				)
				.toContain(SUNSET_DAY)
		} finally {
			await developer.dispose()
		}

		// The product page lists the notice as sent.
		await gotoAppRoute(page, `/products/${ids.product}`)
		await expectRouteMatched(page, `/products/${ids.product}`)
		await expect(page.getByRole('heading', { name: PRODUCT_NAME })).toBeVisible({ timeout: 30_000 })
		// The date is shown as stored; OpenRegister may normalise the time part.
		await expect(
			page.getByText(new RegExp(`Deprecated — sunset ${SUNSET_DAY}`)),
			'the page shows the product is deprecated',
		).toBeVisible()

		const notices = page.getByTestId('api-product-change-notices')
		await expect(notices.getByRole('heading', { name: 'Change notices' })).toBeVisible()
		await expect(notices.getByText('No notices sent yet.')).toHaveCount(0)

		// Cells: Sent, Recipient, Kind, Message.
		const row = notices.getByRole('row').filter({ hasText: DEVELOPER })
		await expect(row, 'one row for the one subscriber').toHaveCount(1, { timeout: 15_000 })
		const cells = row.getByRole('cell')
		await expect(cells.nth(0), 'the notice is marked sent with its time').toHaveText(/\d{4}-\d{2}-\d{2}/)
		await expect(cells.nth(1)).toHaveText(DEVELOPER)
		await expect(cells.nth(2)).toHaveText('Deprecated')
		await expect(cells.nth(3)).toContainText(PRODUCT_NAME)
		await expect(cells.nth(3)).toContainText(SUNSET_DAY)
	})
})
