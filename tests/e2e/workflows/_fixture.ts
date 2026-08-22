/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helper for the DEEP, DATA-DEPENDENT openconnector e2e layer
 * (tests/e2e/workflows/).
 *
 * WHY THIS EXISTS
 * ---------------
 * The high-value openconnector checks are not "does the page render" but
 * "does the integration actually MOVE DATA end-to-end": create a Source +
 * Mapping, link them in a Synchronization, RUN it, and assert the target
 * register gains the synced objects with the mapped values, and that a
 * run-log records a success.
 *
 * To drive that deterministically we need to create/clean up Sources,
 * Mappings, Synchronizations and seed source data through the REST API.
 * openconnector resolves all of its core entities through OpenRegister
 * (register `openconnector`, schema {source,mapping,synchronization,...}):
 *
 *     /index.php/apps/openregister/api/objects/openconnector/<schema>
 *
 * The real OpenRegister/openconnector verbs are find / findAll /
 * searchObjects / saveObject / createObject / updateObject / deleteObject.
 * The REST surface maps to:
 *   - createObject  -> POST   .../objects/openconnector/<schema>
 *   - find          -> GET    .../objects/openconnector/<schema>/<id>
 *   - findAll       -> GET    .../objects/openconnector/<schema>?...
 *   - updateObject  -> PUT    .../objects/openconnector/<schema>/<id>
 *   - deleteObject  -> DELETE .../objects/openconnector/<schema>/<id>
 *
 * CSRF: Nextcloud rejects state-changing requests (POST/PUT/DELETE) that
 * lack a valid `requesttoken`. The Playwright storageState carries the
 * browser cookies but NOT the rotating CSRF token, so we mint one by
 * loading a logged-in page in a browser context and scraping
 * `OC.requestToken`. Every mutating request then carries it as the
 * `requesttoken` header (+ OCS-APIRequest for good measure).
 *
 * Every object this helper creates is tagged with a unique
 * `e2e-<runId>` prefix in its name/title so afterAll cleanup can find and
 * delete exactly the rows this run created and nothing else.
 */
import {
	type APIRequestContext,
	type Browser,
	request as pwRequest,
} from '@playwright/test'
import * as path from 'path'

export const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
export const OC_API = '/index.php/apps/integriq/api'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** Unique per-run prefix so cleanup only touches this run's rows. */
export function makeRunId(): string {
	return `e2e-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`
}

/**
 * Mint a Nextcloud CSRF `requesttoken` for the authenticated session.
 *
 * storageState gives us the session cookies but not the rotating token,
 * which NC embeds as `OC.requestToken` (window global) on every logged-in
 * page. We load a cheap logged-in page and read it back.
 */
async function fetchRequestToken(browser: Browser): Promise<string> {
	const context = await browser.newContext({ storageState: STORAGE_STATE })
	try {
		const page = await context.newPage()
		await page.goto('/index.php/apps/dashboard/', {
			waitUntil: 'domcontentloaded',
		})
		// OC.requestToken is the canonical source; fall back to the <head> meta.
		const token = await page.evaluate(() => {
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			const oc = (window as any).OC
			if (
				oc
				&& typeof oc.requestToken === 'string'
				&& oc.requestToken.length > 0
			) {
				return oc.requestToken as string
			}
			const meta = document.querySelector('head[data-requesttoken]')
			return meta?.getAttribute('data-requesttoken') ?? ''
		})
		await page.close()
		if (!token) {
			throw new Error(
				'Could not mint a Nextcloud requesttoken (OC.requestToken empty). The session may be stale; re-run global-setup.',
			)
		}
		return token
	} finally {
		await context.close()
	}
}

export interface ApiClient {
	request: APIRequestContext
	token: string
	dispose: () => Promise<void>
}

/**
 * Build an APIRequestContext that shares the stored browser session AND
 * carries a freshly-minted requesttoken on every request, so POST/PUT/DELETE
 * to the OR object API are accepted.
 */
export async function makeApiClient(
	browser: Browser,
	baseURL: string,
): Promise<ApiClient> {
	const token = await fetchRequestToken(browser)
	const ctx = await pwRequest.newContext({
		baseURL,
		storageState: STORAGE_STATE,
		extraHTTPHeaders: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
	})
	return {
		request: ctx,
		token,
		dispose: async () => {
			await ctx.dispose()
		},
	}
}

/** Unwrap an OR object-create/get response into the bare object record. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function unwrap(body: any): any {
	if (body == null) return body
	// create/update return the object directly or under a key; get returns the object.
	if (body['@self'] && body.id) return body
	if (body.object) return body.object
	if (body.results && Array.isArray(body.results)) return body.results
	return body
}

/**
 * createObject — POST an object into register `openconnector`, schema `<schema>`.
 * Returns the persisted record (with its generated id/uuid).
 */
export async function createObject(
	api: ApiClient,
	schema: string,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	data: Record<string, any>,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
): Promise<any> {
	const resp = await api.request.post(`${OR_BASE}/${schema}`, {
		data,
		failOnStatusCode: false,
	})
	if (!resp.ok()) {
		throw new Error(
			`createObject(${schema}) failed: ${resp.status()} ${await resp.text()}`,
		)
	}
	return unwrap(await resp.json())
}

/** find — GET a single object by id/uuid. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export async function find(
	api: ApiClient,
	schema: string,
	id: string,
): Promise<any> {
	const resp = await api.request.get(`${OR_BASE}/${schema}/${id}`, {
		failOnStatusCode: false,
	})
	if (!resp.ok()) {
		throw new Error(
			`find(${schema}/${id}) failed: ${resp.status()} ${await resp.text()}`,
		)
	}
	return unwrap(await resp.json())
}

/** findAll — GET the list (optionally filtered via query params). */
export async function findAll(
	api: ApiClient,
	schema: string,
	query: Record<string, string | number> = {},
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
): Promise<any[]> {
	const qs = new URLSearchParams({
		_limit: '200',
		...Object.fromEntries(Object.entries(query).map(([k, v]) => [k, String(v)])),
	})
	const resp = await api.request.get(`${OR_BASE}/${schema}?${qs.toString()}`, {
		failOnStatusCode: false,
	})
	if (!resp.ok()) {
		throw new Error(
			`findAll(${schema}) failed: ${resp.status()} ${await resp.text()}`,
		)
	}
	const body = await resp.json()
	return Array.isArray(body.results)
		? body.results
		: Array.isArray(body)
			? body
			: []
}

/** updateObject — PUT a full object by id. */
export async function updateObject(
	api: ApiClient,
	schema: string,
	id: string,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	data: Record<string, any>,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
): Promise<any> {
	const resp = await api.request.put(`${OR_BASE}/${schema}/${id}`, {
		data,
		failOnStatusCode: false,
	})
	if (!resp.ok()) {
		throw new Error(
			`updateObject(${schema}/${id}) failed: ${resp.status()} ${await resp.text()}`,
		)
	}
	return unwrap(await resp.json())
}

/** deleteObject — DELETE an object by id. Swallows 404 (already gone). */
export async function deleteObject(
	api: ApiClient,
	schema: string,
	id: string,
): Promise<void> {
	const resp = await api.request.delete(`${OR_BASE}/${schema}/${id}`, {
		failOnStatusCode: false,
	})
	if (!resp.ok() && resp.status() !== 404) {
		// Cleanup must be best-effort; log but don't throw so afterAll keeps going.
		// eslint-disable-next-line no-console
		console.warn(
			`deleteObject(${schema}/${id}) returned ${resp.status()}: ${await resp.text()}`,
		)
	}
}

/**
 * Best-effort cleanup: delete every object of `schema` whose name/title/slug
 * carries the run prefix. Used in afterAll for each seeded schema.
 */
export async function cleanupByPrefix(
	api: ApiClient,
	schema: string,
	prefix: string,
): Promise<void> {
	let rows: unknown[] = []
	try {
		rows = await findAll(api, schema, { _search: prefix })
	} catch {
		try {
			rows = await findAll(api, schema)
		} catch {
			rows = []
		}
	}
	for (const row of rows as Array<Record<string, unknown>>) {
		const hay = `${row.name ?? ''} ${row.title ?? ''} ${row.slug ?? ''} ${row.description ?? ''}`
		if (!hay.includes(prefix)) continue
		const id = String(row.id ?? row.uuid ?? '')
		if (id) await deleteObject(api, schema, id)
	}
}

/** Extract the stable id (uuid or id) from a persisted OR record. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function idOf(obj: any): string {
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? '')
}
