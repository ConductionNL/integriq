/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md
 * (REQ-FEDG-001, APIs on other gateways are listed; REQ-FEDG-003, a discovered
 * API can be brought behind integriq; the Playwright halves of tasks 1 and 4
 * in that change's tasks.md).
 *
 * What an administrator does here: open the API inventory, narrow it to the
 * Azure API Management APIs, and on an API with a stored OpenAPI description
 * choose "Bring behind integriq", which opens the OpenAPI import with that
 * description and previews its operations at once. Nothing is imported.
 *
 * The discovered APIs are staged as the external_api rows the discovery
 * synchronizations write, because a run needs a reachable Kong or Azure
 * gateway. What a run writes, and an API missing on the next run being
 * marked no longer seen, are covered by PHPUnit on the discovery fragments.
 *
 * Runs as the admin the global setup logs in. Everything is seeded through
 * OpenRegister's object API under this run's marker and deleted afterwards.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-inv-${Date.now().toString(36)}`

const AZURE_API = `Orders ${RUN}`
const KONG_API = `Invoices ${RUN}`
/** One of integriq's own API products, listed on the gateway `integriq` by its save. */
const OWN_PRODUCT = `Payments ${RUN}`

/** The seeded product's id, whose inventory row is removed with it. */
let ownProductId = ''

/** The description Azure API Management exported for the Orders API. */
const ORDERS_DOCUMENT = {
	openapi: '3.0.1',
	info: { title: AZURE_API, version: '2' },
	servers: [{ url: 'https://orders.invalid/v2' }],
	paths: {
		'/orders': { get: { operationId: 'listOrders', summary: 'List orders' } },
		'/orders/{id}': { get: { operationId: 'getOrder', summary: 'Read one order' } },
	},
}

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

test.describe('gateway-federated-api-discovery: the API inventory', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await seedObject(request, 'external_api', {
			gateway: `Azure APIM ${RUN}`,
			vendor: 'azure-apim',
			name: AZURE_API,
			version: '2',
			baseUrl: 'https://orders.invalid/v2',
			gatewayApiId: `orders-${RUN}`,
			openApiDocument: ORDERS_DOCUMENT,
			lastSeen: new Date().toISOString(),
		})
		await seedObject(request, 'external_api', {
			gateway: `Kong ${RUN}`,
			vendor: 'kong',
			name: KONG_API,
			version: '1',
			baseUrl: 'https://invoices.invalid',
			gatewayApiId: `invoices-${RUN}`,
			lastSeen: new Date().toISOString(),
		})
		ownProductId = await seedObject(request, 'api_product', {
			name: OWN_PRODUCT,
			productSlug: `payments-${RUN}`,
			version: '1.0.0',
			visibility: 'private',
			status: 'active',
		})
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}

		// The product's inventory row outlives it, marked no longer seen; this run removes it.
		const rows = await request.get(`${OR_BASE}/external_api?gatewayApiId=${encodeURIComponent(ownProductId)}`, { failOnStatusCode: false, headers: HEADERS })
		for (const row of ((await rows.json().catch(() => ({})))?.results ?? [])) {
			await request.delete(`${OR_BASE}/external_api/${row.id ?? row['@self']?.id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e federated-api-inventory::an-architect-finds-every-api-in-one-place
	test('the inventory lists both APIs and integriq\'s own product with their gateway, and the Azure filter keeps only the Azure one', async ({ page }) => {
		await gotoAppRoute(page, '/inventory')
		await expectRouteMatched(page, '/inventory')
		const azureRow = page.getByRole('row').filter({ hasText: AZURE_API })
		const kongRow = page.getByRole('row').filter({ hasText: KONG_API })
		const ownRow = page.getByRole('row').filter({ hasText: OWN_PRODUCT })
		await expect(azureRow).toBeVisible({ timeout: 30_000 })
		await expect(azureRow).toContainText(`Azure APIM ${RUN}`)
		await expect(kongRow).toContainText(`Kong ${RUN}`)
		await expect(ownRow, 'integriq\'s own API product is listed with the discovered APIs').toBeVisible()
		await expect(ownRow).toContainText('integriq')
		await expect(ownRow).toContainText('1.0.0')

		// The vendor facet of the index page's filter sidebar (external_api.vendor is facetable).
		await page.getByRole('checkbox', { name: /azure-apim/i }).first().check({ force: true })
		await expect(azureRow).toBeVisible({ timeout: 15_000 })
		await expect(azureRow).toContainText('2')
		await expect(kongRow).toHaveCount(0)
		await expect(ownRow).toHaveCount(0)
	})

	// @e2e federated-api-inventory::an-azure-api-moves-behind-integriq
	test('"Bring behind integriq" opens the import preview with the API\'s operations', async ({ page }) => {
		await gotoAppRoute(page, '/inventory')
		const kongRow = page.getByRole('row').filter({ hasText: KONG_API })
		await expect(kongRow).toBeVisible({ timeout: 30_000 })
		await kongRow.getByRole('button', { name: /actions/i }).click()
		await expect(page.getByRole('menuitem', { name: 'Bring behind integriq' }), 'an API without a stored description offers no import').toHaveCount(0)
		await page.keyboard.press('Escape')

		const azureRow = page.getByRole('row').filter({ hasText: AZURE_API })
		await azureRow.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: 'Bring behind integriq' }).click()

		const dialog = appDialog(page)
		await expect(dialog.getByTestId('openapi-import-dialog').or(dialog)).toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByTestId('openapi-import-prefix').locator('input')).toHaveValue(`orders-${RUN}`)
		await expect(dialog.locator('[data-operation="listOrders"]')).toBeVisible({ timeout: 15_000 })
		await expect(dialog.locator('[data-operation="getOrder"]')).toBeVisible()
	})
})
