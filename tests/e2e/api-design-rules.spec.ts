/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md
 * (REQ-ADRC-001, an API description is checked against rule sets; REQ-ADRC-003,
 * a product with an open error is not published).
 *
 * What an administrator does here: on an API product's page press "Check
 * design" and read the findings, errors first, each with its path in the
 * description and a link to the rule; try to make the product active, which is
 * refused while the error is open; waive the error with a reason, after which
 * the product becomes active and its page shows the waiver.
 *
 * The product is this run's own, with one endpoint whose path ends in a
 * slash, so the built-in rule `/core/no-trailing-slash` finds an error. The
 * description the gateway writes also fails rules that hold for every product
 * on the instance (contact, URI version, API-Version header, http); the waiver
 * test waives those first, so the product carries the one error it is about. The
 * seeded `besluiten-api` is left alone: other specs read its seeded findings.
 * Making the product active goes through OpenRegister's object API, where
 * ApiProductPublishGuardListener refuses it; the rule evaluation itself
 * carries `@e2e exclude` and is covered by PHPUnit with fixture documents.
 *
 * Everything is seeded under this run's marker and deleted afterwards.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-adr-${Date.now().toString(36)}`

const RULE = '/core/no-trailing-slash'
const REASON = `partner requires this path until 2027 (${RUN})`

const ids: Record<string, string> = {}
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
 * Ask OpenRegister to make the product active.
 *
 * @param request The request fixture.
 * @return The answer.
 */
async function makeActive(request: APIRequestContext) {
	return request.patch(`${OR_BASE}/api_product/${ids.product}`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: { status: 'active' },
	})
}

/** A design finding, as the check and the refusal return it. */
type Finding = { id: string, ruleId: string, severity: string, status: string }

/**
 * Run the design check on the product through the app's API.
 *
 * @param request The request fixture.
 * @return The findings.
 */
async function checkDesign(request: APIRequestContext): Promise<Finding[]> {
	const resp = await request.post(`${APP_BASE}/api/products/${ids.product}/design-check`, { failOnStatusCode: false, headers: HEADERS })
	expect(resp.status(), `the design check: ${await resp.text()}`).toBe(200)
	const body = await resp.json()

	return (Array.isArray(body) ? body : (body.findings ?? [])) as Finding[]
}

/**
 * The rule ids of the open errors a refusal lists.
 *
 * @param body The refusal's JSON body.
 * @return The rule ids.
 */
function openErrorRuleIds(body: { errors?: { findings?: Finding[] } }): string[] {
	return (body.errors?.findings ?? []).filter((f) => f.severity === 'error' && f.status === 'open').map((f) => f.ruleId)
}

/**
 * Open the product page and wait for its design rules panel.
 *
 * @param page The page.
 * @return The panel.
 */
async function openPanel(page: Page) {
	await page.goto(`${APP_BASE}/products/${ids.product}`, { waitUntil: 'domcontentloaded' })
	const panel = page.getByTestId('api-design-findings')
	await expect(panel).toBeVisible({ timeout: 30_000 })

	return panel
}

test.describe('gateway-api-design-rules-check: checking a product before it is published', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		ids.endpoint = await seedObject(request, 'endpoint', {
			name: `${RUN} besluiten`,
			endpoint: `${RUN}/besluiten/`,
			method: 'GET',
		})
		ids.product = await seedObject(request, 'api_product', {
			name: `${RUN} Besluiten API`,
			productSlug: RUN,
			version: '1.0.0',
			visibility: 'public',
			status: 'deprecated',
			endpoints: [ids.endpoint],
			tiers: {},
		})
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e api-design-rules::an-administrator-checks-a-product-before-publishing
	test('Check design lists the findings by severity, each with its path and a link to the rule', async ({ page }) => {
		const panel = await openPanel(page)
		await panel.getByTestId('api-design-check').click()

		const errors = panel.getByTestId('api-design-group-error')
		await expect(errors).toBeVisible({ timeout: 30_000 })
		const finding = errors.getByRole('listitem').filter({ hasText: RULE })
		await expect(finding).toBeVisible()
		// The path is a JSON Pointer into the description, as the seeded finding stores it (`~1` is `/`).
		await expect(finding.locator('code'), 'the path in the description').toHaveText(`/paths/~1${RUN}~1besluiten~1`)
		await expect(finding.getByRole('link', { name: RULE }), 'a link to the rule').toHaveAttribute('href', /^https?:\/\//)

		const groups = panel.locator('[data-testid^="api-design-group-"]')
		await expect(groups.first(), 'errors come first').toHaveAttribute('data-testid', 'api-design-group-error')
	})

	// @e2e api-design-rules::a-waiver-lets-a-known-exception-through
	test('an open error refuses activation until it is waived, and the page shows the waiver', async ({ page, request }) => {
		// The scenario's GIVEN is one open error. A description the gateway writes also fails
		// rules that hold for every product on this instance (no info.contact, no /vN in the
		// server URL, no API-Version header, http in the sandbox); those are waived first so the
		// product carries exactly the one error this scenario is about.
		for (const other of (await checkDesign(request)).filter((f) => f.severity === 'error' && f.status === 'open' && f.ruleId !== RULE)) {
			const waived = await request.post(`${APP_BASE}/api/products/design-findings/${other.id}/waive`, {
				failOnStatusCode: false,
				headers: HEADERS,
				data: { reason: `instance-wide, not this scenario's finding (${RUN})` },
			})
			expect(waived.status(), `waiving ${other.ruleId}: ${await waived.text()}`).toBe(200)
		}

		const refused = await makeActive(request)
		expect(refused.status(), 'activation with an open error is refused').toBeGreaterThanOrEqual(400)
		expect(openErrorRuleIds(await refused.json()), 'the refusal lists the one open finding').toEqual([RULE])

		const panel = await openPanel(page)
		const finding = panel.getByTestId('api-design-group-error').getByRole('listitem').filter({ hasText: RULE })
		await expect(finding).toBeVisible({ timeout: 30_000 })
		await finding.getByRole('button', { name: 'Waive' }).click()

		const dialog = page.getByTestId('waive-finding-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		// NcTextField puts the test id on the input itself.
		await dialog.getByRole('textbox', { name: 'Reason' }).fill(REASON)
		await dialog.getByTestId('waive-finding-submit').click()
		await expect(panel.getByText(REASON)).toBeVisible({ timeout: 15_000 })

		const accepted = await makeActive(request)
		expect(accepted.status(), `activation after the waiver: ${await accepted.text()}`).toBeLessThan(300)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('api-design-findings').getByText(REASON), 'the product page shows the waiver').toBeVisible({ timeout: 30_000 })
	})
})
