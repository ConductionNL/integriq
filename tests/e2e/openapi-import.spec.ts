/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md
 * (REQ-OAPI-001, an OpenAPI document is imported as a source and endpoints;
 * the Playwright half of Task 3 in that change's tasks.md).
 *
 * What an administrator does here: on the Sources page choose "Import an
 * OpenAPI description" from the actions bar, paste a vendor's document, give
 * a gateway prefix and press Preview. The preview lists every operation and
 * writes nothing. They pick listZaken and getZaak, import them, read which
 * credential the new source still asks for, and open the new source and its
 * two endpoints. In a second test an endpoint already serves the route of one
 * operation, and the preview marks that operation as colliding, so it cannot
 * be picked, and nothing is written.
 *
 * The document is pasted rather than fetched by its address: fetching goes
 * through the egress guard, which refuses plain http to a host that is not on
 * the allowlist, and a CI instance has no https host serving a vendor
 * document. The endpoint that causes the collision is staged through
 * OpenRegister's object API (`/index.php/apps/openregister/api/objects/integriq/endpoint`
 * with `OCS-APIRequest: true`), the same row the Endpoints page writes, so
 * the test does not depend on another create flow. Everything a run creates
 * carries this run's marker and is deleted afterwards.
 *
 * Left to other suites: fetching a document by address (PHPUnit,
 * OpenApiImportControllerTest), Swagger 2.0 conversion and the refusal to
 * read a secret from the document (PHPUnit on OpenApiImportService and
 * OpenApiCredentialShapes), and the call through an imported endpoint
 * (Newman, tests/postman). That the endpoints appear in buildiq's connector
 * picker is not shown here: that picker lives in the buildiq app, which a run
 * of this suite does not install.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-oapi-${Date.now().toString(36)}`

/** The gateway prefix of the import test, and of the collision test. */
const IMPORT_PREFIX = `${RUN}/connect`
const COLLIDE_PREFIX = `${RUN}/preview`

/** The name the administrator gives the imported source. */
const SOURCE_NAME = `E2E Zaken vendor ${RUN}`

/** The name of the endpoint that already serves one route. */
const EXISTING_ENDPOINT = `E2E existing zaken ${RUN}`

/**
 * A vendor document: three operations behind an API key.
 *
 * @param server The server address, unique per test so the created source is easy to find.
 * @return The document.
 */
function vendorDocument(server: string): Record<string, unknown> {
	return {
		openapi: '3.1.0',
		info: { title: 'E2E Zaken vendor', version: '1.0.0' },
		servers: [{ url: server }],
		components: { securitySchemes: { api_key: { type: 'apiKey', in: 'header', name: 'X-API-Key' } } },
		security: [{ api_key: [] }],
		paths: {
			'/vendor/zaken': {
				get: { operationId: 'listZaken', summary: 'List cases', responses: { 200: { description: 'Cases' } } },
				post: { operationId: 'createZaak', summary: 'Create a case', responses: { 201: { description: 'Created' } } },
			},
			'/vendor/zaken/{uuid}': {
				get: {
					operationId: 'getZaak',
					summary: 'One case',
					parameters: [{ name: 'uuid', in: 'path', required: true, schema: { type: 'string' } }],
					responses: { 200: { description: 'One case' } },
				},
			},
		},
	}
}

const IMPORT_SERVER = `https://${RUN}-import.example.test/api`
const COLLIDE_SERVER = `https://${RUN}-preview.example.test/api`

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
 * Every object of a schema, read through OpenRegister's object API.
 *
 * @param request The request fixture.
 * @param schema  The schema slug.
 * @return The objects.
 */
async function listObjects(request: APIRequestContext, schema: string): Promise<Array<Record<string, unknown>>> {
	const resp = await request.get(`${OR_BASE}/${schema}?_limit=500`, { headers: HEADERS })
	expect(resp.status(), `listing ${schema}`).toBe(200)
	const body = await resp.json()

	return (body.results ?? []) as Array<Record<string, unknown>>
}

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
 * Open the Sources page's actions bar and choose the import.
 *
 * @param page The page.
 * @return The import dialog.
 */
async function openImportDialog(page: Page): Promise<Locator> {
	await gotoAppRoute(page, '/sources')
	await expectRouteMatched(page, '/sources')
	const actions = page.getByTestId('cn-actions')
	await expect(actions, 'the Sources page renders its actions bar').toBeVisible({ timeout: 15_000 })
	await actions.getByRole('button').first().click()
	await page.getByRole('menuitem', { name: 'Import an OpenAPI description' }).click()

	const dialog = appDialog(page).filter({ has: page.getByTestId('openapi-import-url') })
	await expect(dialog, 'the import dialog opens').toBeVisible({ timeout: 10_000 })

	return dialog
}

/**
 * Paste a document, give a prefix and preview it.
 *
 * @param dialog   The import dialog.
 * @param document The vendor document.
 * @param prefix   The gateway prefix.
 * @return The operations table of the preview.
 */
async function previewDocument(dialog: Locator, document: Record<string, unknown>, prefix: string): Promise<Locator> {
	await (await editable(dialog.getByTestId('openapi-import-document'))).fill(JSON.stringify(document, null, 2))
	await (await editable(dialog.getByTestId('openapi-import-prefix'))).fill(prefix)
	await dialog.getByTestId('openapi-import-preview').click()

	const operations = dialog.getByTestId('openapi-import-operations')
	await expect(operations, 'the preview lists the operations').toBeVisible({ timeout: 15_000 })
	for (const key of ['listZaken', 'createZaak', 'getZaak']) {
		await expect(operations.locator(`tr[data-operation="${key}"]`)).toBeVisible()
	}

	return operations
}

test.describe('gateway-openapi-import-and-publish: importing a vendor document', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		// Endpoints first, then sources.
		const order = (schema: string) => (schema === 'endpoint' ? 0 : 1)
		const rows = cleanup.splice(0).sort((a, b) => order(a.schema) - order(b.schema))
		for (const { schema, id } of rows) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e openapi-import-and-publish::an-administrator-connects-a-vendor-api-from-its-document
	test('an administrator imports two operations and finds the source and its endpoints', async ({ page }) => {
		const dialog = await openImportDialog(page)
		const operations = await previewDocument(dialog, vendorDocument(IMPORT_SERVER), IMPORT_PREFIX)

		// The preview serves every operation under the prefix and names the server.
		await expect(operations.locator('tr[data-operation="listZaken"]')).toContainText(`/${IMPORT_PREFIX}/vendor/zaken`)
		await expect(dialog.getByTestId('openapi-import-location')).toContainText(IMPORT_SERVER)

		await (await editable(dialog.getByTestId('openapi-import-name'))).fill(SOURCE_NAME)

		const pick = async (key: string, on: boolean) => {
			const box = operations.locator(`tr[data-operation="${key}"] input[type="checkbox"]`)
			await box.setChecked(on)
			await expect(box).toBeChecked({ checked: on })
		}
		await pick('listZaken', true)
		await pick('getZaak', true)
		await pick('createZaak', false)

		const confirm = dialog.getByTestId('openapi-import-confirm')
		await expect(confirm).toHaveText(/Import 2 operations/)
		const answered = page.waitForResponse(
			(res) => res.url().endsWith('/api/openapi/import') && res.request().method() === 'POST',
		)
		await confirm.click()

		const response = await answered
		expect(response.status(), 'something was created').toBe(201)
		const body = await response.json()
		const sourceId = String(body.source?.uuid ?? '')
		const endpointIds = (body.endpoints ?? [])
			.filter((outcome: { outcome?: string }) => outcome.outcome === 'created')
			.map((outcome: { uuid?: string }) => String(outcome.uuid ?? ''))
			.filter((uuid: string) => uuid !== '')
		for (const uuid of endpointIds) {
			cleanup.push({ schema: 'endpoint', id: uuid })
		}
		if (sourceId !== '') {
			cleanup.push({ schema: 'source', id: sourceId })
		}
		expect(body.created).toBe(2)
		expect(sourceId).not.toBe('')
		expect(endpointIds).toHaveLength(2)

		// The outcome, and the credential the new source still asks for.
		const result = dialog.getByTestId('openapi-import-result')
		await expect(result).toBeVisible()
		await expect(result).toContainText(`Source ${SOURCE_NAME} created.`)
		await expect(result).toContainText('2 endpoints created.')
		await expect(result).toContainText('The source calls nothing until you add its credential (api_key) on the source.')

		// The new source opens on its page.
		await gotoAppRoute(page, `/sources/${sourceId}`)
		await expectRouteMatched(page, `/sources/${sourceId}`)
		await expect(page.getByText(SOURCE_NAME).first()).toBeVisible({ timeout: 20_000 })

		// Its two endpoints open on their pages, under the prefix and pointing at the source.
		for (const uuid of endpointIds) {
			await gotoAppRoute(page, `/endpoints/${uuid}`)
			await expectRouteMatched(page, `/endpoints/${uuid}`)
			await expect(page.getByText(new RegExp(`${IMPORT_PREFIX}/vendor/zaken`)).first()).toBeVisible({ timeout: 20_000 })
			const stored = await page.request.get(`${OR_BASE}/endpoint/${uuid}`, { headers: HEADERS })
			expect(stored.status()).toBe(200)
			const data = await stored.json()
			expect(data.targetType).toBe('api')
			expect(data.targetId).toBe(sourceId)
			expect(String(data.endpoint)).toMatch(new RegExp(`^${IMPORT_PREFIX}/vendor/zaken`))
		}
	})

	// @e2e openapi-import-and-publish::a-colliding-path-is-flagged-before-anything-is-written
	test('the preview marks an operation whose route is already served and writes nothing', async ({ page, request }) => {
		await seedObject(request, 'endpoint', {
			name: EXISTING_ENDPOINT,
			endpoint: `${COLLIDE_PREFIX}/vendor/zaken`,
			method: 'GET',
			targetType: 'api',
		})

		// Any import call during this test would be a write.
		const imports: string[] = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().endsWith('/api/openapi/import')) {
				imports.push(req.url())
			}
		})

		const dialog = await openImportDialog(page)
		const operations = await previewDocument(dialog, vendorDocument(COLLIDE_SERVER), COLLIDE_PREFIX)

		// listZaken is GET on the served route: marked, named, and cannot be picked.
		const colliding = operations.locator('tr[data-operation="listZaken"]')
		await expect(colliding).toHaveClass(/openapi-import__row--collides/)
		await expect(colliding).toContainText(`Already served by ${EXISTING_ENDPOINT}`)
		await expect(colliding.locator('input[type="checkbox"]')).toBeDisabled()

		// The POST on the same path and the GET on the item path are free.
		for (const key of ['createZaak', 'getZaak']) {
			const row = operations.locator(`tr[data-operation="${key}"]`)
			await expect(row).not.toHaveClass(/openapi-import__row--collides/)
			await expect(row.locator('input[type="checkbox"]')).toBeEnabled()
		}

		// Nothing was written: no import call, no endpoint under the prefix but
		// the staged one, and no source on the document's server.
		expect(imports, 'the preview made no import call').toEqual([])
		const underPrefix = (await listObjects(request, 'endpoint'))
			.filter((endpoint) => String(endpoint.endpoint ?? '').startsWith(COLLIDE_PREFIX))
		expect(underPrefix.map((endpoint) => endpoint.name)).toEqual([EXISTING_ENDPOINT])
		const sources = await listObjects(request, 'source')
		expect(sources.some((source) => String(source.location ?? '') === COLLIDE_SERVER)).toBe(false)
	})
})
