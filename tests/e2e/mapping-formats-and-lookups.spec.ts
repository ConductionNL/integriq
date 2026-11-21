/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md
 * (the Playwright halves of tasks 2, 3 and 5 in that change's tasks.md).
 *
 * REQ-MFL-001, a municipal code list served as CSV. The synchronization
 * editor's File format field is a select of JSON, JSON Lines, XML and CSV,
 * and CSV shows the delimiter, enclosure and header options
 * (SyncConfigWidget, rendered for a `file` source on the synchronization
 * detail page). The fetch path is driven over HTTP: a two-row `code;naam`
 * file is put in the admin's Files and shared by public link, so the server
 * can fetch it without a session, and the synchronization test route reads it
 * with `sourceConfig.format: csv`. A test run reads only the first object of
 * the first page and answers the run log's counts, not the mapped objects, so
 * this asserts that the CSV page parsed into a row; that both rows reach the
 * mapping with keys `code` and `naam` is SynchronizationFetchSinglePageTest's.
 *
 * REQ-MFL-002, an engineer previews XML output. The seeded `person-to-xml`
 * mapping (lib/Settings/register.d/mapping-formats-and-lookups.json) is opened
 * on its detail page, a sample input is typed into the live preview, and the
 * read-only `mapping-rendered-output` block shows an XML document with root
 * `persoon` next to the mapped array. A mapping without `outputFormat` gets no
 * rendered text from POST /api/mappings/test (task 3's third criterion).
 *
 * REQ-MFL-005, an administrator allows a code list. On integriq's admin
 * settings page the administrator adds a register schema to the lookup
 * allowlist (MappingLookupSettings / AllowlistEditor); it is listed with their
 * user id, and GET /api/admin/mapping-lookups records the same.
 *
 * REQ-MFL-004, a gemeentecode becomes a name. A register and schema holding
 * `0363 Amsterdam` are created in OpenRegister and put on the allowlist, and a
 * mapping rule `lookup('<register>/<schema>', {'code': gemeente}, 'naam', '')`
 * previewed on the mapping detail page shows `Amsterdam` for `gemeente: 0363`.
 * The schema is named by its numeric register and schema ids, which
 * LookupAllowlist::SCHEMA_PATTERN accepts, so no slug has to be guessed.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: a quoted line
 * break in a CSV field (CsvCodecTest), CSV output with a header row
 * (MappingOutputTest), XML or CSV sent to an API target
 * (SynchronizationTargetOutputFormatTest), a schema off the allowlist and two
 * matches returning the default (MappingLookupTest), and a non-administrator
 * refused with 403 (MappingLookupControllerTest).
 *
 * Runs as the admin the global setup logs in. Everything this file creates is
 * removed at the end: the allowlist entry, the register objects, register and
 * schema, the mappings, the synchronizations, the source, the share and the
 * file.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_BASE = `${OR_API}/objects/integriq`
const API_BASE = '/index.php/apps/integriq/api'
const LOOKUPS_URL = `${API_BASE}/admin/mapping-lookups`
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const SHARES_URL = '/ocs/v2.php/apps/files_sharing/api/v1/shares'

/** The administrator the global setup logs in. */
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-mfl-${Date.now().toString(36)}`

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
	return { 'OCS-APIRequest': 'true', ...(await withRequestToken(request)) }
}

/**
 * Create one integriq object through OpenRegister's object API.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param data The object.
 * @return The stored object's id.
 */
async function createObject(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, {
		headers: await adminHeaders(request),
		data,
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const id = idOf(await resp.json())
	expect(id, `the stored ${schema} has an id`).not.toBe('')

	return id
}

/**
 * Open a mapping's detail page and type a sample input into its live preview.
 *
 * @param page The page.
 * @param mappingId The mapping's id.
 * @param sample The sample input.
 * @param marker Text in the request body that tells this run from the mount run.
 * @return Nothing.
 */
async function previewMapping(
	page: Page,
	mappingId: string,
	sample: Record<string, unknown>,
	marker: string,
): Promise<void> {
	await gotoAppRoute(page, `/mappings/${mappingId}`)
	await expectRouteMatched(page, `/mappings/${mappingId}`)

	// The <label for> of the preview textarea in MappingDetailPage.vue.
	const input = page.getByLabel('Sample input (JSON)')
	await expect(input, 'the live preview is on the mapping detail page').toBeVisible({ timeout: 20_000 })

	// The preview also runs once on mount with `{}`; wait for the run that
	// carries this sample.
	const tested = page.waitForResponse(
		(resp) => resp.url().includes('/api/mappings/test') && (resp.request().postData() ?? '').includes(marker),
		{ timeout: 20_000 },
	)
	await input.fill(JSON.stringify(sample))
	expect((await tested).status(), 'the mapping test answers').toBe(200)
}

test.describe('mapping-formats-and-lookups: a synchronization reads CSV', () => {
	const cleanup: Array<[string, string]> = []
	let shareId = ''
	const fileName = `${RUN}-gemeenten.csv`

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		for (const [schema, id] of cleanup.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { headers, failOnStatusCode: false })
		}
		if (shareId !== '') {
			await request.delete(`${SHARES_URL}/${shareId}?format=json`, { headers, failOnStatusCode: false })
		}
		await request.delete(`/remote.php/dav/files/${ADMIN_USER}/${fileName}`, { headers, failOnStatusCode: false })
	})

	// @e2e mapping-and-search::a-municipal-code-list-served-as-csv
	test('the file format is a select, and CSV shows its delimiter, enclosure and header options', async ({ page, request }) => {
		const syncId = await createObject(request, 'synchronization', {
			name: `${RUN} CSV file format`,
			description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
			sourceType: 'file',
			sourceId: `/${RUN}/gemeenten.csv`,
			sourceConfig: { format: 'csv', csvDelimiter: ';' },
			targetType: 'register/schema',
			targetId: 'integriq/event',
			conditions: [],
			followUps: [],
			actions: [],
			configurations: [],
		})
		cleanup.push(['synchronization', syncId])

		await gotoAppRoute(page, `/synchronizations/${syncId}`)
		await expectRouteMatched(page, `/synchronizations/${syncId}`)

		// NcSelect's `inputLabel` labels the combobox input; matching it by
		// label rather than role is unconfirmed against a live render.
		const format = page.getByLabel('File format').first()
		await expect(format, 'the file format field is shown for a file source').toBeVisible({ timeout: 20_000 })
		// `.sync-config__field` is the wrapper SyncConfigWidget.vue puts around the select.
		const formatField = page.locator('.sync-config__field').filter({ has: format })
		await expect(formatField, 'the stored format is selected').toContainText('CSV')

		await expect(page.getByLabel('CSV delimiter')).toHaveValue(';')
		await expect(page.getByLabel('CSV enclosure')).toBeVisible()
		await expect(page.getByText('The first row names the columns')).toBeVisible()

		await format.click()
		for (const label of ['JSON', 'JSON Lines', 'XML', 'CSV']) {
			await expect(page.getByRole('option', { name: label, exact: true }), `${label} is offered`).toBeVisible()
		}
		await page.keyboard.press('Escape')
	})

	// @e2e mapping-and-search::a-municipal-code-list-served-as-csv
	test('a synchronization test reads a semicolon CSV source', async ({ request }) => {
		const headers = await adminHeaders(request)

		// The file, in the admin's Files.
		const put = await request.put(`/remote.php/dav/files/${ADMIN_USER}/${fileName}`, {
			headers: { ...headers, 'Content-Type': 'text/csv' },
			data: 'code;naam\n0363;Amsterdam\n0344;Utrecht\n',
			failOnStatusCode: false,
		})
		expect(put.status(), await put.text()).toBeLessThan(300)

		// A public link, so the server fetches it without a session. The
		// `/index.php/s/{token}/download` address is Nextcloud's public
		// download route; unconfirmed against the CI instance.
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

		const sourceId = await createObject(request, 'source', {
			name: `${RUN} gemeenten csv`,
			description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
			location: 'http://localhost',
			type: 'api',
			isEnabled: true,
		})
		cleanup.push(['source', sourceId])

		const mappingId = await createObject(request, 'mapping', {
			name: `${RUN} gemeente row`,
			description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
			passThrough: false,
			mapping: { gemeentecode: '{{ code }}', gemeentenaam: '{{ naam }}' },
			unset: [],
			cast: {},
		})
		cleanup.push(['mapping', mappingId])

		const syncId = await createObject(request, 'synchronization', {
			name: `${RUN} gemeenten csv sync`,
			description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
			sourceId,
			sourceType: 'api',
			sourceConfig: {
				endpoint: `/index.php/s/${token}/download`,
				format: 'csv',
				csvDelimiter: ';',
				resultsPosition: '_root',
				idPosition: 'code',
				usesPagination: false,
			},
			sourceTargetMapping: mappingId,
			targetType: 'register/schema',
			targetId: 'integriq/event',
			conditions: [],
			followUps: [],
			actions: [],
			configurations: [],
		})
		cleanup.push(['synchronization', syncId])

		// Which origin the server reaches itself on differs between the dev
		// container (:80) and CI (:8080); keep the one that reads the file.
		let found = 0
		for (const origin of ['http://localhost', 'http://localhost:8080']) {
			const patched = await request.patch(`${OR_BASE}/source/${sourceId}`, {
				headers,
				data: { location: origin },
				failOnStatusCode: false,
			})
			expect(patched.status(), await patched.text()).toBeLessThan(300)

			const run = await request.post(`${API_BASE}/synchronizations/${syncId}/test`, {
				headers,
				failOnStatusCode: false,
			})
			if (run.status() !== 200) {
				continue
			}
			found = Number((await run.json()).result?.objects?.found ?? 0)
			if (found > 0) {
				break
			}
		}

		expect(found, 'the CSV page parsed into rows; a test run reads only the first').toBe(1)
	})
})

test.describe('mapping-formats-and-lookups: a mapping declares its output format', () => {
	// @e2e mapping-and-search::an-engineer-previews-xml-output
	test('the preview of person-to-xml shows an XML document with root persoon next to the array', async ({ page, request }) => {
		const list = await request.get(`${OR_BASE}/mapping`, {
			headers: await adminHeaders(request),
			params: { _limit: 500 },
		})
		expect(list.status()).toBe(200)
		const rows = ((await list.json()).results ?? []) as Array<Record<string, any>>
		const seeded = rows.find((row) => row['@self']?.slug === 'person-to-xml' || row.slug === 'person-to-xml')
		expect(seeded, 'the person-to-xml example is seeded by the register fragment').toBeTruthy()

		const person = { name: `Jan ${RUN}`, birthDate: '1980-04-27', city: 'Utrecht' }
		await previewMapping(page, idOf(seeded!), person, RUN)

		const rendered = page.getByTestId('mapping-rendered-output')
		await expect(rendered, 'the rendered text is shown for an XML mapping').toBeVisible({ timeout: 15_000 })
		await expect(rendered).toContainText('<persoon>')
		await expect(rendered).toContainText('</persoon>')
		await expect(rendered).toContainText(`<naam>Jan ${RUN}</naam>`)
		await expect(page.getByText('Written as XML')).toBeVisible()

		const mapped = page.locator('pre').filter({ hasText: '"woonplaats"' }).first()
		await expect(mapped, 'the mapped array is shown too').toBeVisible()
		await expect(mapped).toContainText('Utrecht')
	})

	test('a mapping without outputFormat gets no rendered text', async ({ request }) => {
		const resp = await request.post(`${API_BASE}/mappings/test`, {
			headers: await adminHeaders(request),
			data: {
				inputObject: { name: 'Jan' },
				mapping: { mapping: { naam: '{{ name }}' }, passThrough: false },
			},
			failOnStatusCode: false,
		})
		expect(resp.status(), await resp.text()).toBe(200)
		const body = await resp.json()
		expect(body.resultObject).toMatchObject({ naam: 'Jan' })
		expect(body, 'JSON behaviour is unchanged').not.toHaveProperty('renderedOutput')
	})
})

test.describe('mapping-formats-and-lookups: lookups in an allowed register schema', () => {
	test.describe.configure({ mode: 'serial' })

	let registerId = ''
	let schemaId = ''
	let allowed = ''
	const objectIds: string[] = []
	const mappingIds: string[] = []

	test.beforeAll(async ({ request }) => {
		const headers = await adminHeaders(request)

		const schema = await request.post(`${OR_API}/schemas`, {
			headers,
			data: {
				title: `${RUN} gemeenten`,
				description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
				properties: { code: { type: 'string' }, naam: { type: 'string' } },
			},
			failOnStatusCode: false,
		})
		expect(schema.status(), await schema.text()).toBeLessThan(300)
		schemaId = idOf(await schema.json())

		const register = await request.post(`${OR_API}/registers`, {
			headers,
			data: {
				title: `${RUN} codelijsten`,
				description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
				schemas: [Number(schemaId)],
			},
			failOnStatusCode: false,
		})
		expect(register.status(), await register.text()).toBeLessThan(300)
		registerId = idOf(await register.json())
		allowed = `${registerId}/${schemaId}`

		for (const row of [{ code: '0363', naam: 'Amsterdam' }, { code: '0344', naam: 'Utrecht' }]) {
			const created = await request.post(`${OR_API}/objects/${registerId}/${schemaId}`, {
				headers,
				data: row,
				failOnStatusCode: false,
			})
			expect(created.status(), await created.text()).toBeLessThan(300)
			objectIds.push(idOf(await created.json()))
		}
	})

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		if (allowed !== '') {
			await request.delete(`${LOOKUPS_URL}/${registerId}/${schemaId}`, { headers, failOnStatusCode: false })
		}
		for (const id of mappingIds) {
			await request.delete(`${OR_BASE}/mapping/${id}`, { headers, failOnStatusCode: false })
		}
		for (const id of objectIds) {
			await request.delete(`${OR_API}/objects/${registerId}/${schemaId}/${id}`, { headers, failOnStatusCode: false })
		}
		if (registerId !== '') {
			await request.delete(`${OR_API}/registers/${registerId}`, { headers, failOnStatusCode: false })
		}
		if (schemaId !== '') {
			await request.delete(`${OR_API}/schemas/${schemaId}`, { headers, failOnStatusCode: false })
		}
	})

	// @e2e mapping-and-search::an-administrator-allows-a-code-list
	test('an administrator adds a schema to the lookup allowlist and it is listed with their user id', async ({ page, request }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-mapping-lookups-section')
		await expect(section).toBeVisible({ timeout: 20_000 })

		// NcTextField puts data-testid on the input itself.
		await section.getByTestId('admin-mapping-lookups-schema').fill(allowed)
		const added = page.waitForResponse(
			(resp) => resp.url().includes('/api/admin/mapping-lookups') && resp.request().method() === 'POST',
		)
		await section.getByTestId('admin-mapping-lookups-add').click()
		expect((await added).status(), 'the allowlist accepts the schema').toBe(201)

		const row = section.getByTestId('admin-mapping-lookups-row').filter({ hasText: allowed })
		await expect(row, 'the schema is listed').toBeVisible({ timeout: 10_000 })
		await expect(row, 'with who added it').toContainText(ADMIN_USER)
		await expect(section.getByRole('alert')).toHaveCount(0)

		const stored = await request.get(LOOKUPS_URL, { headers: await adminHeaders(request) })
		expect(stored.status()).toBe(200)
		const entry = ((await stored.json()).schemas ?? []).find((item: Record<string, string>) => item.schema === allowed)
		expect(entry, 'the change is recorded').toBeTruthy()
		expect(entry.addedBy).toBe(ADMIN_USER)
		expect(entry.addedAt, 'with the time').not.toBe('')
	})

	// @e2e mapping-and-search::a-gemeentecode-becomes-a-name
	test('a mapping preview using lookup turns 0363 into Amsterdam', async ({ page, request }) => {
		const headers = await adminHeaders(request)

		// The previous test adds it; on its own this one adds it here. 422 is
		// "already on the list".
		const ensured = await request.post(LOOKUPS_URL, { headers, data: { schema: allowed }, failOnStatusCode: false })
		expect([201, 422], await ensured.text()).toContain(ensured.status())

		const mappingId = await createObject(request, 'mapping', {
			name: `${RUN} gemeentecode to naam`,
			description: 'Seeded by tests/e2e/mapping-formats-and-lookups.spec.ts',
			passThrough: false,
			mapping: { gemeentenaam: `{{ lookup('${allowed}', {'code': gemeente}, 'naam', '') }}` },
			unset: [],
			cast: {},
		})
		mappingIds.push(mappingId)

		await previewMapping(page, mappingId, { gemeente: '0363' }, '0363')

		const mapped = page.locator('pre').filter({ hasText: '"gemeentenaam"' }).first()
		await expect(mapped, 'the preview shows the mapped value').toBeVisible({ timeout: 15_000 })
		await expect(mapped).toContainText('Amsterdam')
		await expect(mapped, 'only the matching object is read').not.toContainText('Utrecht')
	})
})
