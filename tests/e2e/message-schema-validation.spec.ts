/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md
 *
 * The message schemas page under Automation: both seeds are listed, a
 * partner's XSD is stored once and listed with its kind and version, and a
 * document that does not parse is refused with the parser's message and not
 * stored. Documents are written through OpenRegister's objects API, the same
 * save path the page's form uses, because the refusal lives on that path
 * (MessageSchemaDocumentListener). The inbound and outbound refusals are
 * covered by Newman (tests/postman, folder 18) and PHPUnit.
 *
 * REQ-MSV-003, one bad object does not stop the run. Ten people, one without
 * a bsn, are put in the admin's Files as one JSON page and shared by public
 * link, so the SERVER can fetch them from its own origin without a session
 * (the same route mapping-formats-and-lookups.spec.ts uses for its CSV). A
 * synchronization reads that page with `sourceConfig.validation` in mode
 * refuse against the seeded "Example person (JSON Schema)" and writes into a
 * scratch register schema. The run writes nine; the tenth is on the Dead
 * letters page (sync queue) with the validation error, and nothing else this
 * run made is. Everything it creates is removed at the end.
 *
 * Base URL and container come from the environment (tests/e2e/playwright.config.ts).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { navTo } from './spec-coverage/_helpers.ts'
import { gotoAppRoute } from './support/appRoot.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_BASE = `${OR_API}/objects/integriq`
const API_BASE = '/index.php/apps/integriq/api'
const SHARES_URL = '/ocs/v2.php/apps/files_sharing/api/v1/shares'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** The administrator the global setup logs in. */
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-msv-${Date.now().toString(36)}`

const PARTNER_XSD = `<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="https://partner.e2e.invalid/zaak" elementFormDefault="qualified">
  <xs:element name="zaak">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="identificatie" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
`

/**
 * The message schemas whose name is the given one.
 *
 * @param request The Playwright request context.
 * @param name The name.
 * @return The stored rows.
 */
async function messageSchemasNamed(request: APIRequestContext, name: string): Promise<Record<string, unknown>[]> {
	const resp = await request.get(`${OR_BASE}/message_schema`, { headers: HEADERS, params: { name } })
	expect(resp.status()).toBe(200)
	const body = (await resp.json()) as { results?: Record<string, unknown>[] }

	return (body.results ?? []).filter((row) => row.name === name)
}

/**
 * The id of a stored object, whichever key carries it.
 *
 * @param row The object as the API returned it.
 * @return Its id.
 */
function idOf(row: Record<string, any>): string {
	return String(row.id ?? row.uuid ?? row['@self']?.id ?? '')
}

/**
 * Headers for a call outside the app's own axios: the OCS marker and the
 * session's CSRF token.
 *
 * @param request The request fixture.
 * @return The headers.
 */
async function adminHeaders(request: APIRequestContext): Promise<Record<string, string>> {
	return { ...HEADERS, ...(await withRequestToken(request)) }
}

/**
 * Ten people for the source page: nine valid against the seeded example
 * person, and one (`<RUN>-bad`) without the required bsn.
 *
 * @return The page body.
 */
function tenPeople(): Array<Record<string, string>> {
	const people: Array<Record<string, string>> = []
	for (let i = 1; i <= 9; i++) {
		people.push({ id: `${RUN}-p${i}`, bsn: `99999000${i}`, geslachtsnaam: `Persoon ${i}` })
	}
	people.push({ id: `${RUN}-bad`, geslachtsnaam: 'Zonder bsn' })

	return people
}

test.describe('message schema validation', () => {
	test('the page lists both seeded message schemas', async ({ page }) => {
		await navTo(page, 'Message schemas', '/message-schemas')

		await expect(page.getByText('Example person (JSON Schema)').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('Example person (XSD)').first()).toBeVisible()
	})

	test("an administrator stores a partner's XSD", async ({ page }) => {
		// e2e: an-administrator-stores-a-partners-xsd
		const name = `E2E partner zaak ${Date.now()}`
		const resp = await page.request.post(`${OR_BASE}/message_schema`, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { name, kind: 'xsd', document: PARTNER_XSD, version: '2.1.0' },
		})
		expect(resp.status(), await resp.text()).toBeLessThan(300)

		await navTo(page, 'Message schemas', '/message-schemas')
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('xsd')
		await expect(row).toContainText('2.1.0')
	})

	test('a broken document is refused', async ({ request }) => {
		// e2e: a-broken-document-is-refused
		const name = `E2E broken xsd ${Date.now()}`
		const resp = await request.post(`${OR_BASE}/message_schema`, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { name, kind: 'xsd', document: '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="a">' },
		})

		expect(resp.status()).toBe(400)
		expect(await resp.text(), "the parser's message reaches the caller").not.toBe('')
		expect(await messageSchemasNamed(request, name)).toHaveLength(0)
	})

	test.describe('a synchronization in mode refuse', () => {
		test.describe.configure({ mode: 'serial' })

		const fileName = `${RUN}-people.json`
		const cleanup: Array<[string, string]> = []
		let shareId = ''
		let registerId = ''
		let schemaId = ''

		test.afterAll(async ({ request }) => {
			const headers = await adminHeaders(request)
			if (registerId !== '' && schemaId !== '') {
				const written = await request.get(`${OR_API}/objects/${registerId}/${schemaId}`, {
					headers,
					params: { _limit: 50 },
					failOnStatusCode: false,
				})
				if (written.ok()) {
					for (const row of ((await written.json()).results ?? []) as Array<Record<string, any>>) {
						await request.delete(`${OR_API}/objects/${registerId}/${schemaId}/${idOf(row)}`, { headers, failOnStatusCode: false })
					}
				}
			}
			const letters = await request.get(`${API_BASE}/sync-dead-letter`, { headers, failOnStatusCode: false })
			if (letters.ok()) {
				const mine = ((await letters.json()).results ?? []).filter((row: Record<string, any>) => String(row.originId ?? '').startsWith(RUN))
				const uuids = mine.map((row: Record<string, any>) => String(row.uuid ?? row.id))
				if (uuids.length > 0) {
					await request.post(`${API_BASE}/sync-dead-letter/discard`, { headers, data: { uuids, ids: uuids }, failOnStatusCode: false })
				}
			}
			for (const [schema, id] of cleanup.reverse()) {
				await request.delete(`${OR_BASE}/${schema}/${id}`, { headers, failOnStatusCode: false })
			}
			if (registerId !== '') {
				await request.delete(`${OR_API}/registers/${registerId}`, { headers, failOnStatusCode: false })
			}
			if (schemaId !== '') {
				await request.delete(`${OR_API}/schemas/${schemaId}`, { headers, failOnStatusCode: false })
			}
			if (shareId !== '') {
				await request.delete(`${SHARES_URL}/${shareId}?format=json`, { headers, failOnStatusCode: false })
			}
			await request.delete(`/remote.php/dav/files/${ADMIN_USER}/${fileName}`, { headers, failOnStatusCode: false })
		})

		test('one bad object does not stop the run', async ({ page, request }) => {
			// e2e: one-bad-object-does-not-stop-the-run
			const headers = await adminHeaders(request)

			// The seeded JSON Schema example: bsn (nine digits) and geslachtsnaam required.
			const schemas = await request.get(`${OR_BASE}/message_schema`, { headers, params: { _limit: 100 } })
			expect(schemas.status()).toBe(200)
			const seeded = ((await schemas.json()).results ?? []).find(
				(row: Record<string, any>) => row['@self']?.slug === 'example-person-json' || row.slug === 'example-person-json',
			)
			expect(seeded, 'the example person JSON Schema is seeded').toBeTruthy()
			const messageSchema = String(seeded['@self']?.id ?? seeded.uuid ?? seeded.id)

			// The page of ten, in the admin's Files, behind a public link.
			const put = await request.put(`/remote.php/dav/files/${ADMIN_USER}/${fileName}`, {
				headers: { ...headers, 'Content-Type': 'application/json' },
				data: JSON.stringify(tenPeople()),
				failOnStatusCode: false,
			})
			expect(put.status(), await put.text()).toBeLessThan(300)
			const shared = await request.post(`${SHARES_URL}?format=json`, {
				headers,
				data: { path: `/${fileName}`, shareType: 3, permissions: 1 },
				failOnStatusCode: false,
			})
			expect(shared.status(), await shared.text()).toBe(200)
			const share = (await shared.json()).ocs?.data ?? {}
			shareId = String(share.id ?? '')
			const token = String(share.token ?? '')
			expect(token, 'the public link has a token').not.toBe('')

			// A scratch register schema to write the nine into.
			const schema = await request.post(`${OR_API}/schemas`, {
				headers,
				data: {
					title: `${RUN} people`,
					description: 'Seeded by tests/e2e/message-schema-validation.spec.ts',
					properties: { bsn: { type: 'string' }, geslachtsnaam: { type: 'string' } },
				},
				failOnStatusCode: false,
			})
			expect(schema.status(), await schema.text()).toBeLessThan(300)
			schemaId = idOf(await schema.json())
			const register = await request.post(`${OR_API}/registers`, {
				headers,
				data: {
					title: `${RUN} people`,
					description: 'Seeded by tests/e2e/message-schema-validation.spec.ts',
					schemas: [Number(schemaId)],
				},
				failOnStatusCode: false,
			})
			expect(register.status(), await register.text()).toBeLessThan(300)
			registerId = idOf(await register.json())

			const source = await request.post(`${OR_BASE}/source`, {
				headers,
				data: {
					name: `${RUN} people page`,
					description: 'Seeded by tests/e2e/message-schema-validation.spec.ts',
					location: 'http://localhost',
					type: 'api',
					isEnabled: true,
				},
				failOnStatusCode: false,
			})
			expect(source.status(), await source.text()).toBeLessThan(300)
			const sourceId = idOf(await source.json())
			cleanup.push(['source', sourceId])

			const sync = await request.post(`${OR_BASE}/synchronization`, {
				headers,
				data: {
					name: `${RUN} people refuse`,
					description: 'Seeded by tests/e2e/message-schema-validation.spec.ts',
					sourceId,
					sourceType: 'api',
					sourceConfig: {
						endpoint: `/index.php/s/${token}/download`,
						format: 'json',
						resultsPosition: '_root',
						idPosition: 'id',
						usesPagination: false,
						validation: { mode: 'refuse', messageSchema },
					},
					targetType: 'register/schema',
					targetId: `${registerId}/${schemaId}`,
					conditions: [],
					followUps: [],
					actions: [],
					configurations: [],
				},
				failOnStatusCode: false,
			})
			expect(sync.status(), await sync.text()).toBeLessThan(300)
			const syncId = idOf(await sync.json())
			cleanup.push(['synchronization', syncId])

			// Which origin the server reaches itself on differs between the dev
			// container (:80) and CI (:8080); keep the one that reads the page.
			let objects: Record<string, number> = {}
			for (const origin of ['http://localhost', 'http://localhost:8080']) {
				const patched = await request.patch(`${OR_BASE}/source/${sourceId}`, {
					headers,
					data: { location: origin },
					failOnStatusCode: false,
				})
				expect(patched.status(), await patched.text()).toBeLessThan(300)

				const run = await request.post(`${API_BASE}/synchronizations/${syncId}/run`, {
					headers,
					data: { force: true },
					failOnStatusCode: false,
				})
				if (run.status() !== 200) {
					continue
				}
				const body = await run.json()
				objects = (body.result?.objects ?? body.objects ?? {}) as Record<string, number>
				if (Number(objects.found ?? 0) > 0) {
					break
				}
			}

			expect(Number(objects.found ?? 0), 'the server read the page of ten').toBe(10)
			expect(Number(objects.created ?? 0), 'nine are written').toBe(9)

			const written = await request.get(`${OR_API}/objects/${registerId}/${schemaId}`, { headers, params: { _limit: 50 } })
			expect(written.status()).toBe(200)
			const rows = ((await written.json()).results ?? []) as Array<Record<string, any>>
			expect(rows, 'nine objects are in the target schema').toHaveLength(9)
			expect(rows.map((row) => row.geslachtsnaam), 'the one without a bsn is not written').not.toContain('Zonder bsn')

			const letters = await request.get(`${API_BASE}/sync-dead-letter`, { headers, params: { synchronizationId: syncId } })
			expect(letters.status()).toBe(200)
			const mine = ((await letters.json()).results ?? []).filter((row: Record<string, any>) => String(row.originId ?? '').startsWith(RUN))
			expect(mine, 'exactly the one bad object is dead-lettered').toHaveLength(1)
			expect(String(mine[0].originId)).toBe(`${RUN}-bad`)

			// The dead-letter list shows it, with the validation error.
			await gotoAppRoute(page, '/dead-letters')
			await page.getByTestId('dead-letters-queue-sync').click()
			const table = page.getByTestId('dead-letters-table')
			await expect(table, 'the sync dead-letter queue lists entries').toBeVisible({ timeout: 20_000 })
			const row = table.getByRole('row').filter({ hasText: `${RUN}-bad` })
			await expect(row).toBeVisible()
			await expect(row, 'the row carries the validation error').toContainText('bsn')
			await expect(table.getByRole('row').filter({ hasText: `${RUN}-p1` })).toHaveCount(0)
		})
	})
})
