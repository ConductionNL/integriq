/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md
 * (REQ-ZCS-003, the Woo starter set; the Playwright half of task 5 in that
 * change's tasks.md).
 *
 * What an administrator does here: open the integriq admin settings, press
 * "Create the Woo starter set" in the Woo starter set section
 * (WooStarterSetSettings) and read how many document types it created and how
 * many categories already had one. A Documenten API client then lists the
 * informatieobjecttypen over the Catalogi API with nothing but its ZGW JWT
 * and finds one type per Woo information category.
 *
 * Everything integriq reads is staged through OpenRegister's APIs with
 * `OCS-APIRequest: true`: a register and schema of document types of this
 * run's own (in the field names CatalogiBinding::DEFAULT_FIELD_MAP reads, so
 * no field map is needed), the `catalogi_binding` and the JWT `consumer`
 * through `/index.php/apps/openregister/api/objects/integriq/<schema>`. The
 * TOOI informatiecategorie scheme is not staged: it lives in OpenRegister's
 * concept register, which has no write route this run can use, so the test
 * skips when the instance does not hold it. To make "one type already in a
 * Woo category" true without guessing a TOOI label, the set is run once
 * through its route on the empty schema and every type but one is deleted
 * again; the administrator's run in the browser is then the scenario's run.
 *
 * Left to PHPUnit and Newman, as the spec's `@e2e exclude` lines say: a type
 * read by its own url, the 405 on a write, the unconfigured 404, the 403
 * without `catalogi.lezen`, hiding concept types, and the refusal when the
 * TOOI scheme is missing (WooStarterSetTest, InformatieobjecttypeEndpointHandlerTest,
 * CatalogiTokenServiceTest; tests/postman/zgw-catalogi-served.postman_collection.json).
 *
 * Runs as the admin the global setup logs in. The binding resolves to the
 * first readable `catalogi_binding`, so the test skips on an instance that
 * already has one rather than seed into somebody else's catalogue. Everything
 * staged, and every type the runs created, is deleted at the end.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac, randomUUID } from 'node:crypto'
import { anonymousRequest } from './support/anonymous.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_BASE = `${OR_API}/objects/integriq`
const API_BASE = '/index.php/apps/integriq/api'
const CATALOGI = '/index.php/apps/integriq/catalogi/api/v1'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-zcs-${Date.now().toString(36)}`

const REGISTER = `${RUN}-documenten`
const SCHEMA = `${RUN}-documenttype`

/** The ZGW client: its consumer name is the JWT issuer and its client_id. */
const CLIENT = `${RUN}-documenten-api`

/** The HS256 secret; the credential bridge refuses one under 32 bytes. */
const SECRET = `${RUN}-${randomUUID()}-${randomUUID()}`

/** The number of Woo information categories the spec names. */
const WOO_CATEGORIES = 17

/** Why the scenario cannot run on this instance, or '' when it can. */
let skipReason = ''

/** Every Woo category label the TOOI scheme holds, read from the staging run. */
let categories: string[] = []

/** The one type kept before the administrator's run. */
let kept = { id: '', name: '', category: '' }

const cleanup: string[] = []

/**
 * Headers for a call outside the app's own axios.
 *
 * @param request The request fixture.
 * @return The headers.
 */
async function adminHeaders(
	request: APIRequestContext,
): Promise<Record<string, string>> {
	return { 'OCS-APIRequest': 'true', ...(await withRequestToken(request)) }
}

/**
 * The numeric id of something stored in OpenRegister.
 *
 * @param request The request context.
 * @param path The stored item's path.
 * @return The numeric id.
 */
async function numericId(request: APIRequestContext, path: string): Promise<number> {
	const resp = await request.get(path, {
		failOnStatusCode: false,
		headers: await adminHeaders(request),
	})
	expect(resp.status(), `read ${path}`).toBe(200)
	const id = Number((await resp.json()).id)
	expect(Number.isInteger(id), `${path} has a numeric id`).toBe(true)

	return id
}

/**
 * Create something through OpenRegister and remember its path for cleanup.
 *
 * @param request The request fixture.
 * @param path    The collection path.
 * @param data    The body.
 * @return The stored id.
 */
async function create(
	request: APIRequestContext,
	path: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(path, {
		failOnStatusCode: false,
		headers: await adminHeaders(request),
		data,
	})
	expect(
		[200, 201],
		`create ${path}: ${(await resp.text()).slice(0, 300)}`,
	).toContain(resp.status())
	const body = await resp.json()
	const id = String(body.uuid ?? body.id ?? body['@self']?.id ?? '')
	expect(id, `the created ${path} has an id`).not.toBe('')
	cleanup.unshift(`${path}/${id}`)

	return id
}

/**
 * The document types stored in this run's schema.
 *
 * @param request The request fixture.
 * @return The stored objects.
 */
async function storedTypes(
	request: APIRequestContext,
): Promise<Array<Record<string, any>>> {
	const resp = await request.get(`${OR_API}/objects/${REGISTER}/${SCHEMA}`, {
		headers: await adminHeaders(request),
		params: { _limit: 200 },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	const body = await resp.json()

	return (body.results ?? body) as Array<Record<string, any>>
}

/**
 * The id of a stored OpenRegister object, whichever key carries it.
 *
 * @param row The object as the API returned it.
 * @return Its id.
 */
function idOf(row: Record<string, any>): string {
	return String(row['@self']?.id ?? row.id ?? row.uuid ?? '')
}

/**
 * A short-lived HS256 ZGW JWT for this run's client.
 *
 * @return The token.
 */
function zgwJwt(): string {
	const b64url = (value: string): string =>
		Buffer.from(value).toString('base64url')
	const now = Math.floor(Date.now() / 1000)
	const head = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
	const body = b64url(
		JSON.stringify({
			iss: CLIENT,
			client_id: CLIENT,
			sub: CLIENT,
			iat: now,
			nbf: now - 5,
			exp: now + 300,
			jti: `${RUN}-${randomUUID()}`,
		}),
	)
	const signature = createHmac('sha256', SECRET)
		.update(`${head}.${body}`)
		.digest('base64url')

	return `${head}.${body}.${signature}`
}

test.describe('zgw-catalogi-served: the Woo starter set', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		const existing = await request.get(`${OR_BASE}/catalogi_binding`, {
			headers: await adminHeaders(request),
		})
		expect(existing.status(), await existing.text()).toBe(200)
		const rows = ((await existing.json()).results ?? []) as unknown[]
		if (rows.length > 0) {
			skipReason =
				'this instance already has a catalogi_binding; the starter set would write into that catalogue'
			return
		}

		const schemaId = await create(request, `${OR_API}/schemas`, {
			title: `E2E document type ${RUN}`,
			slug: SCHEMA,
			properties: {
				name: { type: 'string' },
				confidentiality: { type: 'string' },
				category: { type: 'string' },
				categoryUri: { type: 'string' },
				active: { type: 'boolean' },
			},
		})
		await create(request, `${OR_API}/registers`, {
			title: `E2E documenten ${RUN}`,
			slug: REGISTER,
			// A register lists its schemas by numeric id; a uuid there is never resolved.
			schemas: [await numericId(request, `${OR_API}/schemas/${schemaId}`)],
		})
		await create(request, `${OR_BASE}/consumer`, {
			name: CLIENT,
			description: 'Seeded by tests/e2e/zgw-catalogi-served.spec.ts',
			authorizationType: 'jwt',
			authorizationConfiguration: { algorithm: 'HS256', publicKey: SECRET },
			userId: process.env.NC_USER || 'admin',
		})
		await create(request, `${OR_BASE}/catalogi_binding`, {
			domein: 'WOO',
			rsin: '123456782',
			naam: `E2E catalogus ${RUN}`,
			publishedUuid: randomUUID(),
			typesRegister: REGISTER,
			typesSchema: SCHEMA,
			clients: [
				{ clientId: CLIENT, scopes: ['catalogi.lezen'], concepts: false },
			],
		})

		// The staging run: one type per Woo category in the empty schema.
		const staged = await request.post(`${API_BASE}/catalogi/woo-starter-set`, {
			failOnStatusCode: false,
			headers: await adminHeaders(request),
		})
		if (
			staged.status() === 409
			&& (await staged.json()).code === 'tooi-scheme-missing'
		) {
			skipReason =
				'the TOOI informatiecategorie scheme is not in the concept register of this instance'
			return
		}
		expect(staged.status(), await staged.text()).toBe(200)
		expect(await staged.json(), 'nothing was there yet').toMatchObject({
			skipped: 0,
		})

		const types = await storedTypes(request)
		categories = types.map((row) => String(row.category ?? ''))

		// Keep one type, a "besluiten" one when the scheme has a label like it.
		const keep =
			types.find((row) => /besluit/i.test(String(row.category ?? '')))
			?? types[0]
		kept = {
			id: idOf(keep),
			name: String(keep.name ?? ''),
			category: String(keep.category ?? ''),
		}
		const headers = await adminHeaders(request)
		for (const row of types) {
			if (idOf(row) !== kept.id) {
				const gone = await request.delete(
					`${OR_API}/objects/${REGISTER}/${SCHEMA}/${idOf(row)}`,
					{ headers, failOnStatusCode: false },
				)
				expect(gone.status(), 'a staged type is deleted again').toBeLessThan(
					300,
				)
			}
		}
		expect(
			await storedTypes(request),
			'one type is left, in a Woo category',
		).toHaveLength(1)
	})

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		if (cleanup.some((path) => path.startsWith(`${OR_API}/registers/`))) {
			for (const row of await storedTypes(request).catch(() => [])) {
				await request.delete(
					`${OR_API}/objects/${REGISTER}/${SCHEMA}/${idOf(row)}`,
					{ headers, failOnStatusCode: false },
				)
			}
		}
		for (const path of cleanup) {
			await request.delete(path, { headers, failOnStatusCode: false })
		}
	})

	// @e2e zgw-catalogi-facade::the-woo-categories-become-document-types
	test('the administrator runs the starter set and every Woo category gets a document type', async ({
		page,
		request,
	}) => {
		test.skip(skipReason !== '', skipReason)
		expect(
			categories,
			'the TOOI scheme holds the Woo information categories',
		).toHaveLength(WOO_CATEGORIES)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-woo-starter-set')
		await expect(
			section,
			'the Woo starter set section is on the admin page',
		).toBeVisible({ timeout: 30_000 })

		const ran = page.waitForResponse(
			(resp) =>
				resp.url().includes('/api/catalogi/woo-starter-set')
				&& resp.request().method() === 'POST',
		)
		await section.getByTestId('admin-woo-starter-set-run').click()
		expect(
			(await ran).status(),
			'the administrator may run the starter set',
		).toBe(200)

		await expect(
			section.getByTestId('admin-woo-starter-set-result'),
			'the page says how many were created and how many already had a type',
		).toHaveText(`Created: ${WOO_CATEGORIES - 1}. Already present: 1.`)

		// The type that was there is left as it was.
		const stored = await storedTypes(request)
		const before = stored.find((row) => idOf(row) === kept.id)
		expect(before, 'the existing type is still there').toBeDefined()
		expect(before?.name).toBe(kept.name)
		expect(before?.category).toBe(kept.category)

		// A Documenten API client lists them over the Catalogi API.
		const client = await anonymousRequest({
			Authorization: `Bearer ${zgwJwt()}`,
		})
		try {
			const resp = await client.get(`${CATALOGI}/informatieobjecttypen`, {
				failOnStatusCode: false,
			})
			expect(resp.status(), await resp.text()).toBe(200)
			const page1 = await resp.json()
			expect(page1.count, 'one type per Woo category').toBe(WOO_CATEGORIES)
			expect(page1.results).toHaveLength(WOO_CATEGORIES)
			const listed = (
				page1.results as Array<{ informatieobjectcategorie: string }>
			).map((type) => type.informatieobjectcategorie)
			expect(
				[...listed].sort(),
				'each type carries its own Woo category',
			).toEqual([...categories].sort())
		} finally {
			await client.dispose()
		}
	})
})
