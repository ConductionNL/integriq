/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md
 * (REQ-WSO-001, a shop platform is a source template; the browser half of
 * task 1 in that change's tasks.md).
 *
 * What an administrator does here: find the "WooCommerce shop" template in
 * the Store under the category Commerce, marked dormant, open it and press
 * Instantiate (CatalogItemCard.vue, CatalogItemDetailDialog.vue). The shop
 * source then exists, and on the Sources page they fill in the shop's
 * address in the source's edit dialog. The secret stays a broker reference:
 * the template's consumer secret is `configuration.authentication.password =
 * {credentialRef: {credentialName}}`, and no key or secret is stored on the
 * source in plain text.
 *
 * Nothing is staged for the template itself: it ships in
 * lib/Settings/register.d/woocommerce-shop-source.json and the Store lists it
 * from there. The source it creates is read back through OpenRegister's
 * object API (`/index.php/apps/openregister/api/objects/integriq/source`,
 * with `OCS-APIRequest: true`), which is also how the run puts the instance
 * back as it found it.
 *
 * Left to PHPUnit: that every shop template is dormant, has no address and
 * keeps its secret behind a credentialRef (WebshopOrderSetsTest::
 * testEveryShopTemplateIsDormantBrokeredAndListedUnderCommerce). Not shown
 * here: the reference itself, because `configuration.authentication.password`
 * is a write-only path (register.d/99-source-nested-auth-writeonly.json) and
 * is stripped from every read; and the shillinq channel id, which as built
 * lives on the `woocommerce-shop-to-webshop-order` mapping rather than on the
 * source (task 1's note), and whose mapping editor this file does not drive.
 *
 * Runs as the admin the global setup logs in. The template's source is
 * instance-wide: if it existed before the run, its enabled flag and address
 * are put back afterwards; if the run created it, it is deleted.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'
import { searchStore } from './support/store.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, carried in the shop address this run writes. */
const RUN = `e2e-wso-${Date.now().toString(36)}`

/** The template's source slug and the name the Store shows for it. */
const TEMPLATE_SLUG = 'woocommerce-shop'
const TEMPLATE_NAME = 'WooCommerce shop'

/** The shop the scenario connects. The run marker keeps reruns apart. */
const SHOP_URL = `https://theehandelvandijk.nl/wp-json/wc/v3?run=${RUN}`

/** The source's fields that would hold a secret in plain text. */
const PLAIN_SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt', 'authenticationConfig']

/** What the template's source looked like before the run, or null when it did not exist. */
let before: { id: string, isEnabled: boolean, location: string } | null = null

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
 * The sources made from the WooCommerce template.
 *
 * @param request The request fixture.
 * @return The rows.
 */
async function templateSources(request: APIRequestContext): Promise<Array<Record<string, any>>> {
	const resp = await request.get(`${OR_BASE}/source?_limit=500`, { headers: HEADERS, failOnStatusCode: false })
	expect(resp.status(), await resp.text()).toBe(200)
	const rows = ((await resp.json()).results ?? []) as Array<Record<string, any>>

	return rows.filter((row) => (row.slug ?? row['@self']?.slug) === TEMPLATE_SLUG)
}

/**
 * Change one source through OpenRegister's object API.
 *
 * @param request The request fixture.
 * @param id      The source's id.
 * @param data    The fields to change.
 * @return Nothing.
 */
async function patchSource(request: APIRequestContext, id: string, data: Record<string, unknown>): Promise<void> {
	const resp = await request.patch(`${OR_BASE}/source/${id}`, { headers: HEADERS, data, failOnStatusCode: false })
	expect(resp.status(), await resp.text()).toBeLessThan(300)
}

/**
 * Find the template's source on the Sources page, walking the pager.
 *
 * @param page The page, on the Sources route.
 * @return The row.
 */
async function sourceRow(page: Page): Promise<Locator> {
	const row = page.getByRole('row').filter({ hasText: TEMPLATE_NAME }).first()
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
	await expect(row, 'the shop source is listed on the Sources page').toBeVisible({ timeout: 20_000 })

	return row
}

test.describe('connectors-webshop-orders: the WooCommerce shop template', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		const existing = await templateSources(request)
		expect(existing.length, 'at most one source is made from the template').toBeLessThanOrEqual(1)
		if (existing.length === 1) {
			before = {
				id: idOf(existing[0]),
				isEnabled: existing[0].isEnabled === true,
				location: String(existing[0].location ?? ''),
			}
			// The Store offers Instantiate only while the item is dormant.
			if (before.isEnabled) {
				await patchSource(request, before.id, { isEnabled: false })
			}
		}
	})

	test.afterAll(async ({ request }) => {
		if (before !== null) {
			await request.patch(`${OR_BASE}/source/${before.id}`, {
				headers: HEADERS,
				data: { isEnabled: before.isEnabled, location: before.location },
				failOnStatusCode: false,
			})
			return
		}
		for (const row of await templateSources(request)) {
			await request.delete(`${OR_BASE}/source/${idOf(row)}`, { headers: HEADERS, failOnStatusCode: false })
		}
	})

	// @e2e webshop-order-connectors::a-shop-owner-connects-a-woocommerce-shop
	test('an administrator instantiates the WooCommerce template and gives it the shop address, with no secret on the source', async ({ page, request }) => {
		// The template is in the Store, under Commerce, dormant.
		await searchStore(page, 'WooCommerce')
		const card = page.getByTestId('catalog-item-card').filter({ hasText: TEMPLATE_NAME }).first()
		await expect(card).toBeVisible({ timeout: 15_000 })
		await expect(card).toContainText('Commerce')
		await expect(card.getByTestId('catalog-status-badge')).toHaveText('dormant')

		await page.getByRole('button', { name: `Open catalog item ${TEMPLATE_NAME}` }).click()
		const detail = page.getByTestId('catalog-item-detail-dialog')
		const instantiate = detail.getByTestId('catalog-detail-primary-action')
		await expect(instantiate).toHaveText('Instantiate', { timeout: 15_000 })
		await expect(instantiate).toBeEnabled({ timeout: 15_000 })
		await instantiate.click()
		await expect(detail.getByText('Source instantiated — find it on the Sources page')).toBeVisible({ timeout: 15_000 })

		// The click returns before the source is written, so read until it lands.
		await expect.poll(async () => (await templateSources(request)).filter((row) => row.isEnabled === true).length, {
			message: 'one enabled source is made from the template',
			timeout: 15_000,
		}).toBe(1)
		const [created] = await templateSources(request)
		const createdId = idOf(created)

		// The administrator fills in the shop's address in the source's edit dialog.
		await gotoAppRoute(page, '/sources')
		await expectRouteMatched(page, '/sources')
		const row = await sourceRow(page)
		await row.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		const dialog = appDialog(page)
		await expect(dialog, 'the edit dialog opens').toBeVisible({ timeout: 15_000 })
		const location = dialog.getByRole('textbox', { name: 'Location', exact: true })
		await expect(location).toBeVisible({ timeout: 15_000 })
		await location.fill(SHOP_URL)
		// The dialog's save button; its exact name is unconfirmed.
		await dialog.getByRole('button', { name: /save|update/i }).first().click()

		await expect.poll(async () => {
			const resp = await request.get(`${OR_BASE}/source/${createdId}`, { headers: HEADERS })
			return (await resp.json()).location ?? ''
		}, { message: 'the source holds the shop address', timeout: 15_000 }).toBe(SHOP_URL)

		// No key or secret is stored on the source in plain text.
		const stored = await (await request.get(`${OR_BASE}/source/${createdId}`, { headers: HEADERS })).json()
		for (const field of PLAIN_SECRET_FIELDS) {
			const value = stored[field]
			expect(value === undefined || value === null || value === '' || (typeof value === 'object' && Object.keys(value).length === 0), `${field} holds no value`).toBe(true)
		}
		expect(stored.auth, 'the template authenticates with HTTP Basic').toBe('basic')
		const authentication = (stored.configuration?.authentication ?? {}) as Record<string, unknown>
		expect(typeof authentication.password === 'string' && authentication.password !== '', 'the consumer secret is not written out under configuration').toBe(false)
	})
})
