/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-openapi-import-and-publish
 * (Task 3, the import dialog; REQ-OAPI-001).
 *
 * GIVEN an administrator on the sources page WHEN they import a vendor
 * document and pick two of its operations THEN the new source and its two
 * endpoints are listed, and the source says which credential it still needs.
 *
 * The document is pasted rather than fetched by address: fetching goes
 * through the egress guard, which refuses plain http to a host that is not on
 * the allowlist, and a CI instance has no https host serving a vendor
 * document. Fetching by address is covered by OpenApiImportControllerTest.
 * The call through an imported endpoint is Newman's (tests/postman, folder 18);
 * this file drives the UI only (gate-19).
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from '../support/appRoot.ts'
import { appDialog } from '../support/dialogs.ts'
import { withRequestToken } from '../support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/** Unique per run, so the gateway routes never collide with an earlier run. */
const PREFIX = `e2e-oapi-${Date.now()}`

/** The source name this run gives the import. */
const SOURCE_NAME = `E2E Petstore ${PREFIX}`

/** A vendor document: three operations behind an API key. */
const DOCUMENT = {
	openapi: '3.1.0',
	info: { title: 'E2E Petstore', version: '1.0.0' },
	servers: [{ url: 'https://petstore.example.test/v1' }],
	components: { securitySchemes: { api_key: { type: 'apiKey', in: 'header', name: 'X-API-Key' } } },
	security: [{ api_key: [] }],
	paths: {
		'/pets': {
			get: { operationId: 'listPets', summary: 'List all pets', responses: { 200: { description: 'A list of pets' } } },
			post: { operationId: 'createPet', summary: 'Create a pet', responses: { 201: { description: 'Created' } } },
		},
		'/pets/{petId}': {
			get: {
				operationId: 'showPetById',
				summary: 'Info for one pet',
				parameters: [{ name: 'petId', in: 'path', required: true, schema: { type: 'string' } }],
				responses: { 200: { description: 'One pet' } },
			},
		},
	},
}

/** What the import answered, for the assertions and the clean-up. */
let created: { source: string, endpoints: string[] } = { source: '', endpoints: [] }

/**
 * The editable element of a field: the element itself when it is one, else
 * the input or textarea inside the component root that carries the test id.
 *
 * @param field The located field.
 * @return The element to fill.
 */
async function editable(field: Locator): Promise<Locator> {
	const tag = await field.evaluate((element) => element.tagName)
	if (tag === 'INPUT' || tag === 'TEXTAREA') {
		return field
	}
	return field.locator('input, textarea').first()
}

/**
 * Open the sources page's Actions menu and pick the import.
 *
 * @param page The page.
 * @return The import dialog.
 */
async function openImportDialog(page: Page): Promise<Locator> {
	await gotoAppRoute(page, '/sources')
	const actions = page.getByTestId('cn-actions')
	await expect(actions, 'the sources page must render its actions bar').toBeVisible({ timeout: 15_000 })
	await actions.getByRole('button').first().click()
	await page.getByRole('menuitem', { name: 'Import an OpenAPI description' }).click()

	const dialog = appDialog(page).filter({ has: page.getByTestId('openapi-import-url') })
	await expect(dialog).toBeVisible()
	return dialog
}

test.describe('gateway-openapi-import-and-publish: the import dialog', () => {
	test.afterAll(async ({ request }) => {
		const headers = await withRequestToken(request)
		for (const uuid of created.endpoints) {
			await request.delete(`${OR_BASE}/endpoint/${uuid}`, { headers, failOnStatusCode: false })
		}
		if (created.source !== '') {
			await request.delete(`${OR_BASE}/source/${created.source}`, { headers, failOnStatusCode: false })
		}
	})

	test('an administrator imports two operations and finds the source and its endpoints', async ({ page }) => {
		const dialog = await openImportDialog(page)

		await (await editable(dialog.getByTestId('openapi-import-document'))).fill(JSON.stringify(DOCUMENT, null, 2))
		await (await editable(dialog.getByTestId('openapi-import-prefix'))).fill(PREFIX)
		await dialog.getByTestId('openapi-import-preview').click()

		// The preview lists every operation under the gateway prefix and writes nothing.
		const operations = dialog.getByTestId('openapi-import-operations')
		await expect(operations).toBeVisible()
		for (const key of ['listPets', 'createPet', 'showPetById']) {
			await expect(operations.locator(`tr[data-operation="${key}"]`)).toBeVisible()
		}
		await expect(operations.locator('tr[data-operation="showPetById"]')).toContainText(`/${PREFIX}/pets/`)
		await expect(dialog.getByTestId('openapi-import-location')).toContainText('https://petstore.example.test/v1')

		await (await editable(dialog.getByTestId('openapi-import-name'))).fill(SOURCE_NAME)

		// Pick two of the three.
		const pick = async (key: string, on: boolean) => {
			const box = operations.locator(`tr[data-operation="${key}"] input[type="checkbox"]`)
			await box.setChecked(on)
			await expect(box).toBeChecked({ checked: on })
		}
		await pick('listPets', true)
		await pick('showPetById', true)
		await pick('createPet', false)

		const answered = page.waitForResponse(
			(res) => res.url().endsWith('/api/openapi/import') && res.request().method() === 'POST',
		)
		const confirm = dialog.getByTestId('openapi-import-confirm')
		await expect(confirm).toHaveText(/Import 2 operations/)
		await confirm.click()

		const response = await answered
		expect(response.status(), 'two endpoints were created').toBe(201)
		const body = await response.json()
		created = {
			source: String(body.source?.uuid ?? ''),
			endpoints: (body.endpoints ?? []).map((e: { uuid?: string }) => String(e.uuid ?? '')).filter((u: string) => u !== ''),
		}
		expect(body.created).toBe(2)
		expect(created.source).not.toBe('')
		expect(created.endpoints).toHaveLength(2)

		// The outcome, and the credential the source still needs.
		const result = dialog.getByTestId('openapi-import-result')
		await expect(result).toBeVisible()
		await expect(result.locator('p')).toHaveCount(2)

		// The new source is listed, and opens.
		await gotoAppRoute(page, `/sources/${created.source}`)
		await expect(page.getByText(SOURCE_NAME).first()).toBeVisible({ timeout: 20_000 })

		// Its two endpoints are listed under the prefix, pointing at the source.
		for (const uuid of created.endpoints) {
			await gotoAppRoute(page, `/endpoints/${uuid}`)
			await expect(page.getByText(new RegExp(`${PREFIX}/pets`)).first()).toBeVisible({ timeout: 20_000 })
			const endpoint = await page.request.get(`${OR_BASE}/endpoint/${uuid}`, { headers: { Accept: 'application/json' } })
			expect(endpoint.status()).toBe(200)
			const data = await endpoint.json()
			expect(data.targetType).toBe('api')
			expect(data.targetId).toBe(created.source)
			expect(String(data.endpoint)).toMatch(new RegExp(`^${PREFIX}/pets`))
		}
	})
})
