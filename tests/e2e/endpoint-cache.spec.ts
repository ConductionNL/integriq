/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md
 * (REQ-RCPE-001; the Playwright half of task 2 in that change's tasks.md).
 *
 * What an administrator does here: switch the response cache on for a source
 * endpoint in its edit dialog (the Response cache section of
 * EndpointFormFields, on the Endpoints page), and press Clear cache there
 * when the source's data changed. The effect is read over HTTP on the gateway
 * route `/api/endpoint/{path}`: a first call answers `X-Cache: MISS`, the
 * same call again `X-Cache: HIT`, and after the clear the next call is a MISS
 * again because it reached the source.
 *
 * The source is the instance itself, so the test needs no outside service.
 * The upstream is a static file, `/core/img/logo/logo.svg`, rather than
 * `status.php`: a PHP page can carry the session's `Cache-Control: no-store`,
 * which the cache rightly never keeps. Which origin the server reaches itself
 * on differs between the dev container (:80) and CI (:8080), so both are
 * tried and the one whose answer is kept is used. The gateway is called
 * without a session, as an outside client would; an endpoint without an
 * authentication rule keys its cache on the anonymous caller.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: that the source is
 * called once for two asks and the call log marks the hit, an upstream
 * `private` answer reaching the source twice, and the generation bump behind
 * a clear (EndpointResponseCacheTest, EndpointServiceResponseCacheTest);
 * the clear route's 401, 403 and 404 (EndpointResponseCacheControllerTest);
 * and the problem documents of REQ-RCPE-002, whose scenario is excluded as a
 * response format covered by PHPUnit and Newman.
 *
 * Runs as the admin the global setup logs in. The endpoint and the source are
 * removed at the end; the call log lines the calls write stay, as every
 * inbound call's do.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { anonymousRequest } from './support/anonymous.ts'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-rc-${Date.now().toString(36)}`

/** The endpoint's name, shown on the Endpoints page. */
const ENDPOINT_NAME = `E2E cached logo ${RUN}`

/** The gateway path, below `/api/endpoint/`. */
const ENDPOINT_PATH = `${RUN}/logo`

/** The settings a cached endpoint is given through the API. */
const CACHE_ON = { enabled: true, ttlSeconds: 300, ignoreUpstreamMaxAge: true }

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
 * Headers for a call outside the app's own axios.
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
 * Change one integriq object through OpenRegister's object API.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param id The object's id.
 * @param data The fields to change.
 * @return Nothing.
 */
async function patchObject(
	request: APIRequestContext,
	schema: string,
	id: string,
	data: Record<string, unknown>,
): Promise<void> {
	const resp = await request.patch(`${OR_BASE}/${schema}/${id}`, {
		headers: await adminHeaders(request),
		data,
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
}

/**
 * Call the endpoint through the gateway without a session, as a client does.
 *
 * @param query A query value; the cache keys on it, so each value is its own question.
 * @return The status and the X-Cache header ('' when absent).
 */
async function callEndpoint(query: string): Promise<{ status: number, cache: string }> {
	const client = await anonymousRequest()
	try {
		const resp = await client.get(`${API_BASE}/endpoint/${ENDPOINT_PATH}`, {
			params: { q: query },
			failOnStatusCode: false,
		})
		return { status: resp.status(), cache: resp.headers()['x-cache'] ?? '' }
	} finally {
		await client.dispose()
	}
}

/**
 * Ask one fresh question twice and report what the second answer said, once
 * the first was a miss: 'HIT' when the cache kept the answer.
 *
 * @param query A query value not asked before.
 * @return The X-Cache of the second call, or what went wrong on the first.
 */
async function secondCallOf(query: string): Promise<string> {
	const first = await callEndpoint(query)
	if (first.status !== 200 || first.cache !== 'MISS') {
		return `first call ${first.status} ${first.cache || 'no X-Cache'}`
	}

	return (await callEndpoint(query)).cache
}

/**
 * Find this file's endpoint on the Endpoints page, walking the pager.
 *
 * @param page The page, on the Endpoints route.
 * @return The row.
 */
async function endpointRow(page: Page): Promise<Locator> {
	const row = page.getByRole('row').filter({ hasText: ENDPOINT_NAME }).first()
	for (let hop = 0; hop < 20; hop++) {
		if (await row.isVisible({ timeout: 2_000 }).catch(() => false)) {
			break
		}
		// The pager's Next button, as tests/e2e/workflows/source-mapping-crud.spec.ts walks it.
		const next = page.getByRole('button', { name: 'Next', exact: true }).first()
		if ((await next.isEnabled({ timeout: 2_000 }).catch(() => false)) === false) {
			break
		}
		await next.click()
		await page.waitForTimeout(900)
	}
	await expect(row, 'the endpoint is listed on the Endpoints page').toBeVisible({ timeout: 20_000 })

	return row
}

/**
 * Open the endpoint's edit dialog from the Endpoints page.
 *
 * @param page The page.
 * @return The dialog, with its Response cache section shown.
 */
async function openEndpointEditor(page: Page): Promise<Locator> {
	await gotoAppRoute(page, '/endpoints')
	await expectRouteMatched(page, '/endpoints')
	const row = await endpointRow(page)
	await row.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()

	const dialog = appDialog(page)
	await expect(dialog, 'the edit dialog opens').toBeVisible({ timeout: 15_000 })
	await expect(dialog.getByText('Response cache', { exact: true })).toBeVisible({ timeout: 15_000 })

	return dialog
}

test.describe('gateway-response-cache-and-problem-errors: the endpoint response cache', () => {
	test.describe.configure({ mode: 'serial' })

	let sourceId = ''
	let endpointId = ''

	test.beforeAll(async ({ request }) => {
		sourceId = await createObject(request, 'source', {
			name: `${RUN} instance`,
			description: 'Seeded by tests/e2e/endpoint-cache.spec.ts',
			location: 'http://localhost',
			type: 'api',
			isEnabled: true,
		})
		endpointId = await createObject(request, 'endpoint', {
			name: ENDPOINT_NAME,
			description: 'Seeded by tests/e2e/endpoint-cache.spec.ts',
			endpoint: ENDPOINT_PATH,
			upstreamPath: '/core/img/logo/logo.svg',
			method: 'GET',
			targetType: 'api',
			targetId: sourceId,
			rules: [],
			conditions: [],
			cache: CACHE_ON,
		})

		// Keep the origin whose answer the cache keeps. Each probe asks its
		// own question, so none of them is cached for the tests below.
		let kept = ''
		for (const origin of ['http://localhost', 'http://localhost:8080']) {
			await patchObject(request, 'source', sourceId, { location: origin })
			const outcome = await secondCallOf(`probe-${origin}`)
			if (outcome === 'HIT') {
				kept = origin
				break
			}
		}
		expect(kept, 'the server reaches itself on one of the candidate origins and the cache keeps its answer').not.toBe('')

		// The first test switches the cache on itself.
		await patchObject(request, 'endpoint', endpointId, { cache: { enabled: false } })
	})

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		if (endpointId !== '') {
			await request.delete(`${OR_BASE}/endpoint/${endpointId}`, { headers, failOnStatusCode: false })
		}
		if (sourceId !== '') {
			await request.delete(`${OR_BASE}/source/${sourceId}`, { headers, failOnStatusCode: false })
		}
	})

	// REQ-RCPE-001's settings: no scenario of their own, so no @e2e tag. The
	// spec's "a busy lookup" scenario is excluded; the HIT read here is only
	// the observable effect of the settings saved.
	test('an administrator switches the response cache on in the endpoint dialog', async ({ page, request }) => {
		const off = await callEndpoint('before-switch')
		expect(off.status, 'the endpoint answers').toBe(200)
		expect(off.cache, 'an endpoint without a cache says nothing about one').toBe('')

		const dialog = await openEndpointEditor(page)

		// NcCheckboxRadioSwitch labels its input with the slot text; the exact
		// accessible role (checkbox or switch) is unconfirmed, the label is not.
		await dialog.getByLabel('Cache answers').check({ force: true })
		const lifetime = dialog.getByLabel('Lifetime in seconds')
		await expect(lifetime, 'the cache options appear once it is on').toBeVisible()
		await lifetime.fill('300')
		await dialog.getByLabel('Ignore the source\'s max-age').check({ force: true })

		// The dialog's save button; its exact name is unconfirmed.
		await dialog.getByRole('button', { name: /save|update/i }).first().click()

		await expect
			.poll(
				async () => {
					const stored = await request.get(`${OR_BASE}/endpoint/${endpointId}`, {
						headers: await adminHeaders(request),
					})
					return (await stored.json()).cache ?? null
				},
				{ timeout: 15_000 },
			)
			.toMatchObject({ enabled: true, ttlSeconds: 300, ignoreUpstreamMaxAge: true })

		let attempt = 0
		await expect
			.poll(async () => secondCallOf(`after-switch-${attempt++}`), {
				message: 'the same question asked twice is answered from the cache the second time',
				timeout: 20_000,
			})
			.toBe('HIT')
	})

	// @e2e endpoint-runtime::an-administrator-clears-a-stale-answer
	test('Clear cache on the endpoint makes the next call reach the source', async ({ page, request }) => {
		await patchObject(request, 'endpoint', endpointId, { cache: CACHE_ON })

		// A cached answer to one question.
		let attempt = 0
		let question = ''
		await expect
			.poll(
				async () => {
					question = `stale-${attempt++}`
					return secondCallOf(question)
				},
				{ timeout: 20_000 },
			)
			.toBe('HIT')
		expect((await callEndpoint(question)).cache, 'it stays cached').toBe('HIT')

		const dialog = await openEndpointEditor(page)
		const cleared = page.waitForResponse(
			(resp) => resp.url().includes(`/api/endpoints/${endpointId}/cache/clear`) && resp.request().method() === 'POST',
		)
		await dialog.getByRole('button', { name: 'Clear cache' }).click()
		expect((await cleared).status(), 'the administrator may clear the cache').toBe(200)
		await expect(dialog.getByRole('status').filter({ hasText: 'The cache of this endpoint is cleared.' })).toBeVisible()

		const next = await callEndpoint(question)
		expect(next.status).toBe(200)
		expect(next.cache, 'the next call reaches the source').toBe('MISS')
		expect((await callEndpoint(question)).cache, 'and its fresh answer is kept again').toBe('HIT')
	})
})
