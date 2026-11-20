/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * forms-service-fetch-for-apps, REQ-SF-001: an administrator finds Service
 * fetches in the Connections menu, the fetch they set up is listed there, and
 * the row action "Test" (TestServiceFetchModal) runs it with sample inputs and
 * shows the source's raw answer and the mapped outputs side by side. A
 * required input left empty is refused before the source is called.
 *
 * The fetch is written through OpenRegister's object API, the store the
 * page's own create form writes to: its source and output mapping are uuids
 * of objects this file creates first. The source is the instance itself,
 * Nextcloud's public `status.php`, so the test needs no outside service; which
 * origin the server reaches itself on differs between the dev container (:80)
 * and CI (:8080), so both are tried and the one that answers is kept.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: the
 * ServiceFetchRequestedEvent command and its refusals (REQ-SF-002,
 * ServiceFetchServiceTest), the redacted call log line
 * (ServiceFetchRedactorTest), the list endpoint for form builders (REQ-SF-003,
 * ServiceFetchControllerTest), and a non-admin reading nothing of
 * `serviceFetch` (the schema's lockdown authorization).
 *
 * Runs as the admin the global setup logs in. The fetch, the mapping and the
 * source are removed at the end; the call log lines the test runs write stay,
 * as every outbound call's do.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE, navTo } from './spec-coverage/_helpers.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-sf-${Date.now().toString(36)}`

/** The fetch's slug, what the test route is keyed by. */
const FETCH_SLUG = `${RUN}-status`

/** The fetch's title, shown on the page and in the modal. */
const FETCH_TITLE = `E2E status check ${RUN}`

/** The one declared input, required. */
const INPUT_NAME = 'postcode'

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
	const resp = await request.post(`${OR_BASE}/${schema}`, { data, failOnStatusCode: false })
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const id = idOf(await resp.json())
	expect(id, `the stored ${schema} has an id`).not.toBe('')

	return id
}

/**
 * Run the fetch through the admin test route, as the modal does.
 *
 * @param request The request fixture.
 * @param inputs The sample inputs.
 * @return The parsed answer: raw, outputs, refusal and detail.
 */
async function runTestRoute(
	request: APIRequestContext,
	inputs: Record<string, string>,
): Promise<Record<string, any>> {
	const resp = await request.post(`${API_BASE}/service-fetches/${FETCH_SLUG}/test`, {
		headers: await withRequestToken(request),
		data: { inputs },
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBe(200)

	return await resp.json()
}

/**
 * Point the source at whichever origin the server reaches itself on.
 *
 * @param request The request fixture.
 * @param sourceId The source's id.
 * @return The origin that answered.
 */
async function pointSourceAtReachableOrigin(request: APIRequestContext, sourceId: string): Promise<string> {
	for (const origin of ['http://localhost', 'http://localhost:8080']) {
		const patched = await request.patch(`${OR_BASE}/source/${sourceId}`, {
			data: { location: origin },
			failOnStatusCode: false,
		})
		expect(patched.status(), await patched.text()).toBeLessThan(300)
		const answer = await runTestRoute(request, { [INPUT_NAME]: '1234AB' })
		if (answer.raw?.status === 200 && JSON.stringify(answer.raw?.body ?? '').includes('installed')) {
			return origin
		}
	}
	throw new Error('no candidate origin made the server reach its own status.php')
}

/**
 * Open Service fetches and the "Test" row action of this file's fetch.
 *
 * @param page The page.
 * @return The test modal.
 */
async function openTestModal(page: Page): Promise<Locator> {
	await page.goto(`${APP_BASE}/service-fetches`, { waitUntil: 'domcontentloaded' })
	const row = page.getByTestId('cn-object-row').filter({ hasText: FETCH_SLUG }).first()
	await expect(row, 'the fetch is listed on Service fetches').toBeVisible({ timeout: 20_000 })
	await row.getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'Test' }).click()

	const modal = page.getByTestId('test-service-fetch-modal')
	await expect(modal).toBeVisible({ timeout: 10_000 })
	await expect(modal).toContainText(`Testing service fetch: ${FETCH_TITLE}`)
	return modal
}

test.describe('forms-service-fetch-for-apps: the Service fetches admin page', () => {
	test.describe.configure({ mode: 'serial' })

	const cleanup: Array<[string, string]> = []

	test.beforeAll(async ({ request }) => {
		const sourceId = await createObject(request, 'source', {
			name: `${RUN} instance status`,
			description: 'Seeded by tests/e2e/service-fetches.spec.ts',
			location: 'http://localhost',
			type: 'api',
			isEnabled: true,
		})
		cleanup.push(['source', sourceId])

		// The answer reaches the mapping as {status, body}. `productName` is
		// written but not declared, so it must not reach the outputs.
		const mappingId = await createObject(request, 'mapping', {
			name: `${RUN} status outputs`,
			description: 'Seeded by tests/e2e/service-fetches.spec.ts',
			passThrough: false,
			mapping: { httpStatus: '{{ status }}', productName: '{{ body.productname }}' },
			unset: [],
			cast: {},
		})
		cleanup.push(['mapping', mappingId])

		cleanup.push(['serviceFetch', await createObject(request, 'serviceFetch', {
			slug: FETCH_SLUG,
			title: FETCH_TITLE,
			description: 'Seeded by tests/e2e/service-fetches.spec.ts',
			source: sourceId,
			method: 'GET',
			path: '/status.php',
			query: { probe: `{${INPUT_NAME}}` },
			inputs: [{ name: INPUT_NAME, type: 'string', required: true }],
			outputMapping: mappingId,
			outputs: [{ name: 'httpStatus', type: 'string', label: 'HTTP status' }],
			allowedApps: ['portaliq'],
			timeoutMs: 5000,
			cacheSeconds: 0,
			enabled: true,
		})])

		await pointSourceAtReachableOrigin(request, sourceId)
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of cleanup.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e service-fetch::an-administrator-sets-up-the-kadaster-ownership-check
	test('Service fetches is in the Connections menu and lists the fetch', async ({ page }) => {
		await navTo(page, 'Service fetches', '/service-fetches')
		await expect(page.getByRole('heading', { name: 'Service fetches' }).first()).toBeVisible({ timeout: 15_000 })
		const row = page.getByTestId('cn-object-row').filter({ hasText: FETCH_SLUG }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText(FETCH_TITLE)
	})

	// @e2e service-fetch::an-administrator-sets-up-the-kadaster-ownership-check
	test('Test shows the raw answer and the mapped outputs side by side', async ({ page }) => {
		const modal = await openTestModal(page)
		await modal.getByLabel(`${INPUT_NAME} (required)`).fill('1234AB')

		const tested = page.waitForResponse((resp) => resp.url().includes(`/api/service-fetches/${FETCH_SLUG}/test`))
		await modal.getByTestId('test-service-fetch-run').click()
		expect((await tested).status()).toBe(200)

		const raw = modal.getByTestId('test-service-fetch-raw')
		const outputs = modal.getByTestId('test-service-fetch-outputs')
		await expect(raw).toBeVisible({ timeout: 15_000 })
		await expect(outputs).toBeVisible()
		await expect(modal.getByTestId('test-service-fetch-refusal')).toHaveCount(0)

		await expect(raw, 'the administrator sees what the source answered').toContainText('"installed"')
		await expect(outputs).toContainText('"httpStatus"')
		await expect(outputs).toContainText('200')
		await expect(outputs, 'only declared outputs are kept').not.toContainText('productName')
		await expect(outputs, 'the raw body is not among the outputs').not.toContainText('installed')

		const rawBox = await raw.boundingBox()
		const outputsBox = await outputs.boundingBox()
		expect(rawBox && outputsBox, 'both panes are laid out').toBeTruthy()
		expect(outputsBox!.x, 'the outputs stand to the right of the raw answer').toBeGreaterThan(rawBox!.x + rawBox!.width / 2)
		expect(Math.abs(outputsBox!.y - rawBox!.y), 'on the same row').toBeLessThan(40)
	})

	// @e2e service-fetch::an-administrator-sets-up-the-kadaster-ownership-check
	test('Test with the required input empty is refused without calling the source', async ({ page }) => {
		const modal = await openTestModal(page)

		const tested = page.waitForResponse((resp) => resp.url().includes(`/api/service-fetches/${FETCH_SLUG}/test`))
		await modal.getByTestId('test-service-fetch-run').click()
		expect((await tested).status()).toBe(200)

		await expect(modal.getByTestId('test-service-fetch-refusal')).toContainText('invalid-input', { timeout: 15_000 })
		await expect(modal.getByTestId('test-service-fetch-refusal')).toContainText(INPUT_NAME)
		await expect(modal.getByTestId('test-service-fetch-raw')).toHaveCount(0)
		await expect(modal).toContainText('The source was not called.')
	})
})
