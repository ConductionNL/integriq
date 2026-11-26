/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/api-product-gateway/spec.md
 *
 * The developer portal as its users meet it. Two developers and one account
 * outside the developer group are made through the provisioning API by the
 * admin session; every developer step after that runs in a context with an
 * EMPTY storage state and the developer's own login, never the admin session
 * the project config carries (see support/anonymous.ts for why that has to be
 * spelled out).
 *
 * Covered here: the product list a developer sees and an outsider is refused
 * (REQ-DEVP-001), creating an application and requesting access that waits for
 * the approver, and another developer's application being out of reach
 * (REQ-DEVP-002), and the seeded products being there on a fresh install
 * (task 6), and a developer replacing a key of their own application through
 * the portal key routes, which another developer cannot reach (REQ-DEVP-003).
 * The notice fan-out and the end-date check are covered by PHPUnit and by the
 * Newman folder "Developer portal" in tests/postman/integriq.postman_collection.json.
 */

import type { APIRequestContext, Browser, BrowserContext } from '@playwright/test'

import { seedFirstVisitOverlaysSeen } from '@conduction/nextcloud-vue/testing/playwright'
import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { BASE_URL } from './support/baseUrl.ts'

const PORTAL_API = `${APP_BASE}/api/portal`
const DEV_GROUP = process.env.INTEGRIQ_DEVELOPER_GROUP ?? 'integriq-developers'
const PASSWORD = process.env.INTEGRIQ_E2E_DEV_PASS ?? 'Portal-Dev-e2e-1!'
const DEV_A = 'e2e-portal-dev-a'
const DEV_B = 'e2e-portal-dev-b'
const OUTSIDER = 'e2e-portal-outsider'
const OCS = { 'OCS-APIRequest': 'true' }

/**
 * Make an account (ignoring "already exists") and, when asked, put it in the developer group.
 *
 * @param request The admin request context.
 * @param userId  The account id.
 * @param inGroup Whether the account joins the developer group.
 * @return Resolves when the account is in the wanted state.
 */
async function ensureAccount(request: APIRequestContext, userId: string, inGroup: boolean): Promise<void> {
	await request.post('/ocs/v2.php/cloud/users?format=json', {
		failOnStatusCode: false,
		headers: OCS,
		form: { userid: userId, password: PASSWORD },
	})
	if (inGroup === true) {
		const added = await request.post(`/ocs/v2.php/cloud/users/${userId}/groups?format=json`, {
			failOnStatusCode: false,
			headers: OCS,
			form: { groupid: DEV_GROUP },
		})
		expect(added.ok(), `adding ${userId} to ${DEV_GROUP} must succeed`).toBeTruthy()
	}
}

/**
 * A request context for one account: no cookies from the project config, its own credentials.
 *
 * @param playwright The `playwright` fixture.
 * @param userId     The account id.
 * @return A context the caller disposes.
 */
async function asAccount(playwright: any, userId: string): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: OCS,
	})
}

/**
 * A browser context logged in through the real login form as one account.
 *
 * @param browser The `browser` fixture.
 * @param userId  The account id.
 * @return The logged-in context; the caller closes it.
 */
async function browserAs(browser: Browser, userId: string): Promise<BrowserContext> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	// A fresh account meets the first-visit support dialog, which covers the
	// portal; global-setup seeds the "seen" flag for the admin only.
	await seedFirstVisitOverlaysSeen(context, 'integriq')
	const page = await context.newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(userId)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
	await page.close()
	return context
}

/**
 * The developer's application with this name, created when it is not there yet.
 *
 * @param dev  The developer's request context.
 * @param name The application name.
 * @return The application id.
 */
async function applicationOf(dev: APIRequestContext, name: string): Promise<string> {
	const list = await dev.get(`${PORTAL_API}/applications`)
	expect(list.ok(), 'a developer can list their applications').toBeTruthy()
	const found = ((await list.json()).results ?? []).find((app: any) => app.name === name)
	if (found) {
		return String(found.id)
	}
	const created = await dev.post(`${PORTAL_API}/applications`, { data: { name, description: 'E2E application' } })
	expect(created.status(), 'creating an application answers 201').toBe(201)
	return String((await created.json()).id)
}

/**
 * The id of the public product with this name, as the portal lists it.
 *
 * @param dev  The developer's request context.
 * @param name The product name.
 * @return The product id.
 */
async function productId(dev: APIRequestContext, name: string): Promise<string> {
	const resp = await dev.get(`${PORTAL_API}/products`)
	expect(resp.ok()).toBeTruthy()
	const product = ((await resp.json()).results ?? []).find((p: any) => p.name === name)
	expect(product, `the portal lists "${name}"`).toBeTruthy()
	return String(product.id)
}

test.describe('developer portal', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await request.post('/ocs/v2.php/cloud/groups?format=json', {
			failOnStatusCode: false,
			headers: OCS,
			form: { groupid: DEV_GROUP },
		})
		await ensureAccount(request, DEV_A, true)
		await ensureAccount(request, DEV_B, true)
		await ensureAccount(request, OUTSIDER, false)
	})

	// @e2e access-developer-portal-and-subscriptions::a-developer-browses-the-portal
	test('a developer sees the public products with their tiers, and no private product', async ({ browser, playwright }) => {
		const dev = await asAccount(playwright, DEV_A)
		try {
			const resp = await dev.get(`${PORTAL_API}/products`)
			expect(resp.status()).toBe(200)
			const products = (await resp.json()).results ?? []
			const names = products.map((p: any) => p.name)
			expect(names, 'the seeded public products are listed').toEqual(expect.arrayContaining(['Zaken API', 'Besluiten API']))
			expect(products.every((p: any) => p.visibility === 'public'), 'no private product is listed').toBe(true)
			const zaken = products.find((p: any) => p.name === 'Zaken API')
			expect(zaken.tiers.map((tier: any) => tier.name)).toEqual(expect.arrayContaining(['basic', 'premium']))
		} finally {
			await dev.dispose()
		}

		const context = await browserAs(browser, DEV_A)
		try {
			const page = await context.newPage()
			await page.goto(`${APP_BASE}/developer-portal`, { waitUntil: 'domcontentloaded' })
			const list = page.getByTestId('developer-portal-products')
			await expect(list).toBeVisible({ timeout: 30_000 })
			await expect(list.getByTestId('developer-portal-product').filter({ hasText: 'Zaken API' })).toBeVisible()
			await expect(list.getByTestId('developer-portal-product').filter({ hasText: 'Besluiten API' })).toContainText(/2027-01-01/)
			await expect(page.getByTestId('developer-portal-refused')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('an account outside the developer group is refused', async ({ browser, playwright }) => {
		const outsider = await asAccount(playwright, OUTSIDER)
		try {
			expect((await outsider.get(`${PORTAL_API}/products`)).status()).toBe(403)
			expect((await outsider.get(`${PORTAL_API}/applications`)).status()).toBe(403)
		} finally {
			await outsider.dispose()
		}

		const context = await browserAs(browser, OUTSIDER)
		try {
			const page = await context.newPage()
			await page.goto(`${APP_BASE}/developer-portal`, { waitUntil: 'domcontentloaded' })
			await expect(page.getByTestId('developer-portal-refused')).toBeVisible({ timeout: 30_000 })
			await expect(page.getByTestId('developer-portal-product')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	// @e2e access-developer-portal-and-subscriptions::a-request-waits-for-the-approver
	test('a developer creates an application and a request on an approval tier waits for the approver', async ({ browser, playwright, request }) => {
		const name = `E2E portal app ${Date.now()}`
		const context = await browserAs(browser, DEV_A)
		try {
			const page = await context.newPage()
			await page.goto(`${APP_BASE}/developer-portal`, { waitUntil: 'domcontentloaded' })
			await expect(page.getByTestId('developer-portal-applications')).toBeVisible({ timeout: 30_000 })
			await page.getByLabel('Application name').fill(name)
			await page.getByTestId('developer-portal-create-application').click()
			await expect(page.getByTestId('developer-portal-application').filter({ hasText: name })).toBeVisible({ timeout: 20_000 })
		} finally {
			await context.close()
		}

		const dev = await asAccount(playwright, DEV_A)
		try {
			const appId = await applicationOf(dev, name)
			const zaken = await productId(dev, 'Zaken API')
			const asked = await dev.post(`${PORTAL_API}/applications/${appId}/subscriptions`, {
				data: { productId: zaken, tier: 'premium' },
			})
			expect(asked.status(), 'a tier that needs approval answers 202').toBe(202)
			const subscription = await asked.json()
			expect(subscription.status).toBe('pending_approval')

			const mine = ((await (await dev.get(`${PORTAL_API}/applications`)).json()).results ?? [])
				.find((app: any) => app.id === appId)
			expect(mine.subscriptions.map((s: any) => s.id)).toContain(subscription.id)
		} finally {
			await dev.dispose()
		}

		// The approver (the admin here) sees the request in the approval queue.
		const queued = await request.get(
			`/index.php/apps/openregister/api/objects/integriq/api_product_subscription?status=pending_approval&_limit=200`,
			{ failOnStatusCode: false },
		)
		expect(queued.ok()).toBeTruthy()
		expect(JSON.stringify(await queued.json())).toContain('pending_approval')
	})

	// @e2e access-developer-portal-and-subscriptions::another-developers-application-is-out-of-reach
	test("another developer's application is out of reach", async ({ playwright }) => {
		const devB = await asAccount(playwright, DEV_B)
		const devA = await asAccount(playwright, DEV_A)
		try {
			const appOfB = await applicationOf(devB, 'E2E application of developer B')
			const zaken = await productId(devA, 'Zaken API')
			const before = ((await (await devB.get(`${PORTAL_API}/applications`)).json()).results ?? [])
				.find((app: any) => app.id === appOfB).subscriptions.length

			const refused = await devA.post(`${PORTAL_API}/applications/${appOfB}/subscriptions`, {
				data: { productId: zaken, tier: 'basic' },
			})
			expect(refused.status(), "a developer asking with another developer's application gets 404").toBe(404)

			const after = ((await (await devB.get(`${PORTAL_API}/applications`)).json()).results ?? [])
				.find((app: any) => app.id === appOfB).subscriptions.length
			expect(after, 'nothing is written').toBe(before)
			const listedForA = ((await (await devA.get(`${PORTAL_API}/applications`)).json()).results ?? []).map((app: any) => app.id)
			expect(listedForA).not.toContain(appOfB)
		} finally {
			await devA.dispose()
			await devB.dispose()
		}
	})

	// @e2e access-developer-portal-and-subscriptions::a-developer-replaces-a-leaked-key
	test('a developer replaces a key of their own application, and another developer cannot reach it', async ({ browser, playwright }) => {
		const name = `E2E keys app ${Date.now()}`
		const devA = await asAccount(playwright, DEV_A)
		const devB = await asAccount(playwright, DEV_B)
		try {
			const appId = await applicationOf(devA, name)
			const keys = `${PORTAL_API}/applications/${appId}/keys`

			const leaked = await devA.post(keys, { data: { label: 'leaked' } })
			expect(leaked.status(), 'generating a key answers 201').toBe(201)
			const leakedBody = await leaked.json()
			expect(leakedBody.key, 'the key is in the create response').toBeTruthy()

			const fresh = await devA.post(keys, { data: { label: 'fresh' } })
			expect(fresh.status()).toBe(201)
			const freshBody = await fresh.json()

			const revoked = await devA.delete(`${keys}/${leakedBody.credential.id}`)
			expect(revoked.status(), 'revoking the leaked key answers 200').toBe(200)

			const listed = await (await devA.get(keys)).json()
			const ids = (listed.results ?? []).map((credential: any) => credential.id)
			expect(ids).toContain(freshBody.credential.id)
			expect(ids).not.toContain(leakedBody.credential.id)
			expect(JSON.stringify(listed), 'a key is never listed again').not.toContain(freshBody.key)

			expect((await devB.get(keys)).status(), "another developer's application answers 404").toBe(404)
			expect((await devB.post(keys, { data: { label: 'mine' } })).status()).toBe(404)
			expect((await devB.delete(`${keys}/${freshBody.credential.id}`)).status()).toBe(404)
		} finally {
			await devA.dispose()
			await devB.dispose()
		}

		const context = await browserAs(browser, DEV_A)
		try {
			const page = await context.newPage()
			await page.goto(`${APP_BASE}/developer-portal`, { waitUntil: 'domcontentloaded' })
			const card = page.getByTestId('developer-portal-application').filter({ hasText: name })
			await expect(card.getByTestId('credential-key').filter({ hasText: 'fresh' })).toBeVisible({ timeout: 30_000 })
			await card.getByLabel('Key label').fill('from the page')
			await card.getByTestId('credential-generate-key').click()
			await expect(card.getByTestId('credential-new-key')).toContainText('only time the key is shown')
		} finally {
			await context.close()
		}
	})
})
