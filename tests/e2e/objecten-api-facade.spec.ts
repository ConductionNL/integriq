/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e for openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 *
 * A counterparty reads and writes through the VNG Objecten and Objecttypen
 * APIs with nothing but a token: every call below runs in a fresh request
 * context with an EMPTY storage state, so no Nextcloud session can stand in
 * for the token. The administrator's signed-in context only sets the stage:
 * two schemas in a register of their own, two published objecttypes, the
 * credentials in OpenRegister's broker and two tokens. All of it is removed in
 * afterAll.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { anonymousRequest } from './support/anonymous.ts'
import { absoluteUrl } from './support/baseUrl.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_OBJECTS = `${OR_API}/objects/integriq`
const V2 = '/index.php/apps/integriq/api/v2'
const STAMP = Date.now()
const REGISTER = `e2e-objecten-${STAMP}`
const SCHEMA_A = `e2e-melding-${STAMP}`
const SCHEMA_B = `e2e-boom-${STAMP}`
const READ_KEY = `e2e-read-${STAMP}-${Math.random().toString(36).slice(2)}`
const WRITE_KEY = `e2e-write-${STAMP}-${Math.random().toString(36).slice(2)}`

/** Created ids, removed in afterAll, newest first. */
const cleanup: string[] = []

let typeA = ''
let typeB = ''

/**
 * JSON headers with the admin session's request token.
 *
 * @param request The admin request context.
 * @return The headers.
 */
async function adminHeaders(
	request: APIRequestContext,
): Promise<Record<string, string>> {
	return {
		...(await withRequestToken(request)),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Create something as the admin and remember its path for cleanup.
 *
 * @param request The admin request context.
 * @param path    The collection path.
 * @param data    The body.
 * @return The created id.
 */
async function create(
	request: APIRequestContext,
	path: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(path, {
		headers: await adminHeaders(request),
		data,
	})
	const text = await res.text()
	expect([200, 201], `create ${path}: ${text.slice(0, 200)}`).toContain(
		res.status(),
	)
	const body = JSON.parse(text)
	const id = String(
		body.uuid ?? body.id ?? body['@self']?.uuid ?? body['@self']?.id ?? '',
	)
	expect(id, `created ${path} must carry an id`).not.toEqual('')
	cleanup.unshift(`${path}/${id}`)
	return id
}

/**
 * The numeric id of something stored in OpenRegister.
 *
 * @param request The admin request context.
 * @param path    The stored item's path.
 * @return The numeric id.
 */
async function numericId(request: APIRequestContext, path: string): Promise<number> {
	const res = await request.get(path, { headers: await adminHeaders(request) })
	expect(res.status(), `read ${path}`).toBe(200)
	const id = Number((await res.json()).id)
	expect(Number.isInteger(id), `${path} has a numeric id`).toBe(true)
	return id
}

/**
 * A request context that carries only an Objecten token.
 *
 * @param key The token's key, or '' for no Authorization header at all.
 * @return The context.
 */
async function counterparty(key: string): Promise<APIRequestContext> {
	return anonymousRequest(key === '' ? {} : { Authorization: `Token ${key}` })
}

test.describe('objecten-api-facade', () => {
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		const req = page.request
		typeA = crypto.randomUUID()
		typeB = crypto.randomUUID()

		const schemaA = await create(req, `${OR_API}/schemas`, {
			title: 'E2E melding openbare ruimte',
			slug: SCHEMA_A,
			required: ['straatnaam'],
			properties: {
				straatnaam: { type: 'string' },
				omschrijving: { type: 'string' },
			},
		})
		const schemaB = await create(req, `${OR_API}/schemas`, {
			title: 'E2E boom',
			slug: SCHEMA_B,
			properties: { soort: { type: 'string' } },
		})
		await create(req, `${OR_API}/registers`, {
			title: 'E2E objecten',
			slug: REGISTER,
			// A register lists its schemas by numeric id; a uuid there is never resolved.
			schemas: [
				await numericId(req, `${OR_API}/schemas/${schemaA}`),
				await numericId(req, `${OR_API}/schemas/${schemaB}`),
			],
		})
		await create(req, `${OR_OBJECTS}/objecttype`, {
			publishedUuid: typeA,
			name: 'e2e-melding',
			objectsRegister: REGISTER,
			objectsSchema: SCHEMA_A,
			versions: ['1', '2'],
		})
		await create(req, `${OR_OBJECTS}/objecttype`, {
			publishedUuid: typeB,
			name: 'e2e-boom',
			objectsRegister: REGISTER,
			objectsSchema: SCHEMA_B,
		})

		const readCredential = await create(req, `${OR_API}/credentials`, {
			name: `e2e-objecten-read-${STAMP}`,
			provider: 'generic-apikey',
			secret: READ_KEY,
			allowedApps: ['integriq'],
		})
		const writeCredential = await create(req, `${OR_API}/credentials`, {
			name: `e2e-objecten-write-${STAMP}`,
			provider: 'generic-apikey',
			secret: WRITE_KEY,
			allowedApps: ['integriq'],
		})
		const principal = process.env.NC_USER || 'admin'
		await create(req, `${OR_OBJECTS}/objecten_token`, {
			name: 'e2e read on A only',
			credential: readCredential,
			principal,
			permissions: { [typeA]: 'read' },
		})
		await create(req, `${OR_OBJECTS}/objecten_token`, {
			name: 'e2e write on A',
			credential: writeCredential,
			principal,
			permissions: { [typeA]: 'read_write' },
		})

		// Three objects of A and two of B, written through the Objecten API.
		const writer = await counterparty(WRITE_KEY)
		for (const straatnaam of ['Kerkstraat', 'Molenweg', 'Dorpsplein']) {
			const res = await writer.post(`${V2}/objects`, {
				data: {
					type: typeA,
					record: {
						typeVersion: 1,
						data: { straatnaam, omschrijving: 'Losliggende tegel' },
					},
				},
			})
			expect(res.status(), `create ${straatnaam}`).toBe(201)
			cleanup.unshift(`${V2}/objects/${(await res.json()).uuid}#token`)
		}
		await writer.dispose()
		for (const soort of ['eik', 'beuk']) {
			const id = await create(
				req,
				`${OR_API}/objects/${REGISTER}/${SCHEMA_B}`,
				{
					soort,
				},
			)
			expect(id).not.toEqual('')
		}
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const writer = await counterparty(WRITE_KEY)
		for (const path of cleanup) {
			if (path.endsWith('#token')) {
				await writer.delete(path.replace('#token', '')).catch(() => null)
				continue
			}
			await page.request
				.delete(path, { headers: await adminHeaders(page.request) })
				.catch(() => null)
		}
		await writer.dispose()
		await page.close()
	})

	// @e2e objecten-api-facade::a-consumer-reads-the-objecttype-it-registered-against
	test('an allowed version carries the jsonSchema, an unlisted one is a 404', async () => {
		const reader = await counterparty(READ_KEY)
		const res = await reader.get(`${V2}/objecttypes/${typeA}/versions/1`)
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(body.version).toBe('1')
		expect(JSON.stringify(body.jsonSchema)).toContain('straatnaam')

		const unlisted = await reader.get(`${V2}/objecttypes/${typeA}/versions/3`)
		expect(unlisted.status()).toBe(404)
		await reader.dispose()
	})

	// @e2e objecten-api-facade::a-consumer-lists-objects-of-one-objecttype
	test('a list of one objecttype returns its three records in the standard shape', async () => {
		const reader = await counterparty(READ_KEY)
		const res = await reader.get(`${V2}/objects?type=${typeA}`)
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(body.results).toHaveLength(3)
		for (const record of body.results) {
			expect(Object.keys(record).sort()).toEqual([
				'record',
				'type',
				'url',
				'uuid',
			])
			expect(record.type).toContain(typeA)
			expect(record.record.data.omschrijving).toBe('Losliggende tegel')
		}
		expect(
			body.results
				.map(
					(r: { record: { data: { straatnaam: string } } }) =>
						r.record.data.straatnaam,
				)
				.sort(),
		).toEqual(['Dorpsplein', 'Kerkstraat', 'Molenweg'])
		await reader.dispose()
	})

	// @e2e objecten-api-facade::a-consumer-names-the-objecttype-by-its-url
	test('the objecttype URL is read as its uuid, with or without a trailing slash', async () => {
		const reader = await counterparty(READ_KEY)
		const byUuid = await (await reader.get(`${V2}/objects?type=${typeA}`)).json()
		for (const url of [
			absoluteUrl(`${V2}/objecttypes/${typeA}`),
			absoluteUrl(`${V2}/objecttypes/${typeA}/`),
		]) {
			const res = await reader.get(
				`${V2}/objects?type=${encodeURIComponent(url)}`,
			)
			expect(res.status(), url).toBe(200)
			expect((await res.json()).results.length).toBe(byUuid.results.length)
		}

		// The URL of a type the token does not name is refused.
		const foreign = await reader.get(
			`${V2}/objects?type=${encodeURIComponent(absoluteUrl(`${V2}/objecttypes/${typeB}`))}`,
		)
		expect(foreign.status()).toBe(403)
		await reader.dispose()
	})

	// @e2e objecten-api-facade::a-create-is-validated-by-the-schema
	test('a record without a required field is refused and nothing is created', async () => {
		const writer = await counterparty(WRITE_KEY)
		const before = (
			await (await writer.get(`${V2}/objects?type=${typeA}`)).json()
		).results.length
		const res = await writer.post(`${V2}/objects`, {
			data: {
				type: typeA,
				record: { typeVersion: 1, data: { omschrijving: 'Zonder straat' } },
			},
		})
		expect(res.status()).toBeGreaterThanOrEqual(400)
		expect(res.status()).toBeLessThan(500)
		expect(await res.text()).toContain('straatnaam')
		const after = (
			await (await writer.get(`${V2}/objects?type=${typeA}`)).json()
		).results.length
		expect(after).toBe(before)
		await writer.dispose()
	})

	// @e2e objecten-api-facade::a-token-scoped-to-one-objecttype-is-refused-on-a-second
	test('a token with read on A only is refused on B and gets no object', async () => {
		const reader = await counterparty(READ_KEY)
		const res = await reader.get(`${V2}/objects?type=${typeB}`)
		expect(res.status()).toBe(403)
		const text = await res.text()
		expect(text).not.toContain('eik')
		expect(text).not.toContain('beuk')

		// Without any token nothing answers either.
		const nobody = await counterparty('')
		expect((await nobody.get(`${V2}/objects?type=${typeA}`)).status()).toBe(401)
		await nobody.dispose()
		await reader.dispose()
	})
})
