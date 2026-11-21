/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage:
 * openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md
 * (REQ-SREG-001), and tasks.md Task 8, which names this file.
 *
 * An administrator finds Registration targets in the Connections menu
 * (src/manifest.d/submission-registration-targets.json), the target they set
 * up is listed by title, slug and kind with none of its sources or settings in
 * a column, and the "Test" row action (RegistrationTargetTestModal) runs the
 * dry run of design D6: the mapped body of the server's sample hand-over, or
 * the refusal. GET /api/registration-targets?app= answers a signed-in
 * non-admin with slug, title and kind only, for the enabled targets the app
 * may use; POST /api/registration-targets/{slug}/test is admin-only and writes
 * no registration record.
 *
 * Both REQ-SREG-001 scenarios carry `@e2e exclude` lines that defer the admin
 * page to "the integriq admin e2e"; this is that file, so its tests carry the
 * scenario tags. The dry run has no scenario of its own (it is design D6), so
 * those tests carry none.
 *
 * The dialog test is `test.fixme`: the manifest gives the four kind settings
 * (`zgw`, `objectsApi`, `stufZds`, `json`) a `visibleWhen` but no `widget`,
 * and they are `type: object` in the schema. `fieldsFromSchema` in
 * @conduction/nextcloud-vue drops an object property without a widget, so the
 * dialog renders none of them for any kind: the "only the StUF-ZDS settings
 * show" acceptance of Task 8 has nothing to show yet. The body states what
 * the test asserts once the fragment gives those fields a widget.
 *
 * Not here, and why: every REQ-SREG-002 and REQ-SREG-003 scenario (the ZGW,
 * Objects API, StUF-ZDS and JSON legs, the 503 refusal, resume and the repeat
 * after success) is a backend command with no browser or HTTP surface and
 * carries `@e2e exclude`; PHPUnit covers them (RegistrationServiceTest,
 * ZgwRegistrationLegTest, StufZdsRegistrationLegTest, JsonRegistrationLegTest,
 * RegistrationResumeTest). Portaliq's "Doorsturen" picker lives in portaliq.
 *
 * Runs as the admin the global setup logs in; a plain user is created for
 * the non-admin calls. Every object and the user are removed at the end.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { APP_BASE, navTo } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API = '/index.php/apps/integriq/api'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-sreg-${Date.now().toString(36)}`

/** The ZGW target of the scenario, as an administrator stores it. */
const ZGW_SLUG = `${RUN}-zaaksysteem-milieu`
const ZGW_TITLE = `Zaaksysteem milieu ${RUN}`
const ZAAKTYPE_URL = 'https://zaken.example.nl/catalogi/api/v1/zaaktypen/0f1e2d3c-milieu'
const RSIN = '002220647'

/** A JSON target whose mapping maps the sample hand-over cleanly. */
const JSON_SLUG = `${RUN}-json-intake`
const JSON_TITLE = `JSON intake ${RUN}`

/** A JSON target whose JSON Schema the mapped body does not meet. */
const STRICT_SLUG = `${RUN}-json-strict`

/** A disabled target and one for another app: neither reaches portaliq's list. */
const DISABLED_SLUG = `${RUN}-disabled`
const OTHER_APP_SLUG = `${RUN}-other-app`

/** A user outside the admin group. */
const PLAIN = { username: `sreg-plain-${Date.now().toString(36)}`, password: `Sreg-plain-${Date.now().toString(36)}-pw!` }

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
async function createObject(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { headers: HEADERS, data, failOnStatusCode: false })
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const id = idOf(await resp.json())
	expect(id, `the stored ${schema} has an id`).not.toBe('')

	return id
}

/**
 * A registration target with the fields every kind shares.
 *
 * @param slug Its slug.
 * @param title Its title.
 * @param kind Its kind.
 * @param extra The kind's sources and settings, and any override.
 * @return The target object.
 */
function target(slug: string, title: string, kind: string, extra: Record<string, unknown>): Record<string, unknown> {
	return { slug, title, kind, allowedApps: ['portaliq'], enabled: true, ...extra }
}

/**
 * Open Registration targets and wait for the row of a target.
 *
 * @param page The page.
 * @param slug The target's slug.
 * @return The row.
 */
async function targetRow(page: Page, slug: string): Promise<Locator> {
	await page.goto(`${APP_BASE}/registration-targets`, { waitUntil: 'domcontentloaded' })
	const row = page.getByTestId('cn-object-row').filter({ hasText: slug }).first()
	await expect(row, `${slug} is listed on Registration targets`).toBeVisible({ timeout: 20_000 })

	return row
}

/**
 * Open the "Test" row action of a target.
 *
 * @param page The page.
 * @param slug The target's slug.
 * @return The test modal.
 */
async function openTestModal(page: Page, slug: string): Promise<Locator> {
	const row = await targetRow(page, slug)
	await row.getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'Test' }).click()

	// NcModal is not known to pass a data-testid through, so the modal is found
	// by its heading inside the app's own dialog.
	const modal = appDialog(page)
	await expect(modal.getByRole('heading', { name: 'Test registration target' })).toBeVisible({ timeout: 10_000 })

	return modal
}

/**
 * Run the dry run from the open test modal.
 *
 * @param page The page.
 * @param modal The test modal.
 * @param slug The target's slug.
 */
async function runTest(page: Page, modal: Locator, slug: string): Promise<void> {
	const tested = page.waitForResponse((resp) => resp.url().includes(`/api/registration-targets/${slug}/test`))
	await modal.getByRole('button', { name: 'Run test' }).click()
	expect((await tested).status()).toBe(200)
}

test.describe('submission-registration: the Registration targets admin page', () => {
	test.describe.configure({ mode: 'serial' })

	const cleanup: Array<[string, string]> = []
	let plain: APIRequestContext

	test.beforeAll(async ({ request, baseURL }) => {
		const mappingId = await createObject(request, 'mapping', {
			name: `${RUN} sample hand-over`,
			description: 'Seeded by tests/e2e/registration-targets.spec.ts',
			passThrough: false,
			mapping: { subject: '{{ answers.subject }}', reference: '{{ reference }}' },
			unset: [],
			cast: {},
		})
		cleanup.push(['mapping', mappingId])

		const zaken = await createObject(request, 'source', {
			name: `${RUN} Zaken API`,
			description: 'Seeded by tests/e2e/registration-targets.spec.ts',
			location: 'https://zaken.example.nl/zaken/api/v1',
			type: 'api',
			isEnabled: true,
		})
		cleanup.push(['source', zaken])
		const documenten = await createObject(request, 'source', {
			name: `${RUN} Documenten API`,
			description: 'Seeded by tests/e2e/registration-targets.spec.ts',
			location: 'https://zaken.example.nl/documenten/api/v1',
			type: 'api',
			isEnabled: true,
		})
		cleanup.push(['source', documenten])

		const targets = [
			target(ZGW_SLUG, ZGW_TITLE, 'zgw', {
				sources: { zaken, documenten },
				mapping: mappingId,
				zgw: { zaaktype: ZAAKTYPE_URL, bronorganisatie: RSIN, verantwoordelijkeOrganisatie: RSIN },
			}),
			target(JSON_SLUG, JSON_TITLE, 'json', { mapping: mappingId, json: { path: '/intake' } }),
			target(STRICT_SLUG, `JSON strict ${RUN}`, 'json', {
				mapping: mappingId,
				json: {
					path: '/intake',
					schema: { type: 'object', required: ['caseNumber'], properties: { caseNumber: { type: 'string' } } },
				},
			}),
			target(DISABLED_SLUG, `Disabled ${RUN}`, 'json', { mapping: mappingId, enabled: false }),
			target(OTHER_APP_SLUG, `Other app ${RUN}`, 'json', { mapping: mappingId, allowedApps: ['dossiq'] }),
		]
		for (const data of targets) {
			cleanup.push(['registrationTarget', await createObject(request, 'registrationTarget', data)])
		}

		const created = await request.post('/ocs/v2.php/cloud/users', {
			headers: HEADERS,
			form: { userid: PLAIN.username, password: PLAIN.password },
			failOnStatusCode: false,
		})
		expect(created.status(), `user ${PLAIN.username} is created`).toBe(200)
		plain = await pwRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: PLAIN,
			extraHTTPHeaders: { ...HEADERS, Accept: 'application/json' },
		})
	})

	test.afterAll(async ({ request }) => {
		await plain?.dispose()
		await request.delete(`/ocs/v2.php/cloud/users/${PLAIN.username}`, { headers: HEADERS, failOnStatusCode: false })
		for (const [schema, id] of cleanup.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS, failOnStatusCode: false })
		}
	})

	// @e2e submission-registration::an-administrator-adds-the-municipalitys-case-system
	test('Registration targets is in the Connections menu and lists the target by title, slug and kind only', async ({ page }) => {
		await navTo(page, 'Registration targets', '/registration-targets')
		await expect(page.getByRole('heading', { name: 'Registration targets' }).first()).toBeVisible({ timeout: 15_000 })

		const row = page.getByTestId('cn-object-row').filter({ hasText: ZGW_SLUG }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText(ZGW_TITLE)
		await expect(row).toContainText('zgw')
		await expect(row, 'the case type url is not a column').not.toContainText(ZAAKTYPE_URL)
		await expect(row, 'the RSIN is not a column').not.toContainText(RSIN)
	})

	// @e2e submission-registration::an-administrator-adds-the-municipalitys-case-system
	test('the dialog shows only the settings of the chosen kind', async ({ page }) => {
		test.fixme(
			true,
			'the four kind settings are type object with no widget in the manifest fragment, so CnFormDialog renders none of them; see the header',
		)

		await targetRow(page, ZGW_SLUG)
		await page.getByRole('button', { name: 'Add registration target' }).first().click()
		const dialog = appDialog(page)
		await expect(dialog.locator('[data-cn-field="kind"]')).toBeVisible({ timeout: 20_000 })

		const settings = { zgw: 'zgw', 'objects-api': 'objectsApi', 'stuf-zds': 'stufZds', json: 'json' }
		for (const field of Object.values(settings)) {
			await expect(dialog.locator(`[data-cn-field="${field}"]`), `${field} waits for a kind`).toHaveCount(0)
		}

		for (const [kind, shown] of Object.entries(settings)) {
			await dialog.locator('[data-cn-field="kind"]').locator('input').first().click()
			// Unconfirmed: the enum option is assumed to be labelled by its value.
			await page.getByRole('option', { name: kind, exact: true }).first().click()
			await expect(dialog.locator(`[data-cn-field="${shown}"]`), `${kind} shows its own settings`).toBeVisible()
			for (const hidden of Object.values(settings).filter((field) => field !== shown)) {
				await expect(dialog.locator(`[data-cn-field="${hidden}"]`), `${kind} hides ${hidden}`).toHaveCount(0)
			}
		}
	})

	// @e2e submission-registration::the-list-hides-how-a-target-is-reached
	test('a signed-in non-admin gets slug, title and kind only, for the enabled targets the app may use', async () => {
		const resp = await plain.get(`${API}/registration-targets`, { params: { app: 'portaliq' }, failOnStatusCode: false })
		expect(resp.status(), await resp.text()).toBe(200)
		const results: Array<Record<string, unknown>> = (await resp.json()).results
		const ours = results.filter((row) => String(row.slug).startsWith(RUN))

		expect(ours.map((row) => row.slug).sort()).toEqual([JSON_SLUG, STRICT_SLUG, ZGW_SLUG].sort())
		for (const row of ours) {
			expect(Object.keys(row).sort(), `${row.slug} carries nothing but slug, title and kind`).toEqual(['kind', 'slug', 'title'])
		}
		expect(ours.find((row) => row.slug === ZGW_SLUG)).toEqual({ slug: ZGW_SLUG, title: ZGW_TITLE, kind: 'zgw' })
		expect(JSON.stringify(ours), 'no source or setting leaks').not.toContain(RSIN)

		const otherApp = await (await plain.get(`${API}/registration-targets`, { params: { app: 'dossiq' } })).json()
		expect(otherApp.results.map((row: Record<string, unknown>) => row.slug)).toContain(OTHER_APP_SLUG)
		expect(otherApp.results.map((row: Record<string, unknown>) => row.slug)).not.toContain(ZGW_SLUG)

		const noApp = await (await plain.get(`${API}/registration-targets`)).json()
		expect(noApp.results, 'no app, no targets').toEqual([])
	})

	test('the dry run is for administrators only and writes no registration record', async ({ request }) => {
		const refused = await plain.post(`${API}/registration-targets/${JSON_SLUG}/test`, { data: { handover: {} }, failOnStatusCode: false })
		expect(refused.status(), 'a non-admin may not run the dry run').toBe(403)

		const resp = await request.post(`${API}/registration-targets/${JSON_SLUG}/test`, {
			headers: HEADERS,
			data: { handover: {} },
			failOnStatusCode: false,
		})
		expect(resp.status(), await resp.text()).toBe(200)
		expect(await resp.json()).toMatchObject({
			ok: true,
			dry: true,
			kind: 'json',
			mapped: { subject: 'Sample answer', reference: 'OF-TEST-0000' },
		})

		const strict = await request.post(`${API}/registration-targets/${STRICT_SLUG}/test`, {
			headers: HEADERS,
			data: { handover: {} },
			failOnStatusCode: false,
		})
		expect(strict.status(), 'a refusal is answered with 200 and its code').toBe(200)
		expect(await strict.json()).toMatchObject({ ok: false, dry: true, code: 'mapping-failed', retryable: false })

		for (const slug of [JSON_SLUG, STRICT_SLUG]) {
			const records = await request.get(`${OR_BASE}/registrationRecord`, { headers: HEADERS, params: { target: slug } })
			expect(records.status()).toBe(200)
			expect((await records.json()).results ?? [], `the dry run of ${slug} stored no record`).toEqual([])
		}
	})

	test('Test on a row shows the mapped body of the sample hand-over', async ({ page }) => {
		const modal = await openTestModal(page, JSON_SLUG)
		await expect(modal).toContainText(JSON_TITLE)
		await expect(modal).toContainText('The test maps the hand-over and stops before anything is sent or saved.')

		await runTest(page, modal, JSON_SLUG)
		const body = modal.locator('pre')
		await expect(body).toBeVisible({ timeout: 15_000 })
		await expect(body).toContainText('"subject": "Sample answer"')
		await expect(body).toContainText('"reference": "OF-TEST-0000"')
		await expect(modal).not.toContainText('Refused')
	})

	test('Test with a pasted hand-over maps that hand-over, and a body the schema refuses shows the refusal', async ({ page }) => {
		const modal = await openTestModal(page, JSON_SLUG)
		await modal.getByLabel('Hand-over (JSON, leave empty for the sample)').fill(JSON.stringify({
			reference: `OF-${RUN}`,
			form: { slug: 'woo-verzoek', title: 'Woo-verzoek' },
			submittedAt: '2026-11-01T09:00:00+01:00',
			language: 'nl',
			applicant: { kind: 'bsn', value: '999990627', filledBy: null },
			answers: { subject: 'Geluidsoverlast' },
			fields: { subject: { label: 'Onderwerp', type: 'string', computed: false } },
			attachments: [],
			cosign: null,
		}))
		await runTest(page, modal, JSON_SLUG)
		await expect(modal.locator('pre')).toContainText('"subject": "Geluidsoverlast"', { timeout: 15_000 })
		await expect(modal.locator('pre')).toContainText(`"reference": "OF-${RUN}"`)

		// NcModal's own close icon may also be named "Close"; the modal's footer button comes last.
		await modal.getByRole('button', { name: 'Close', exact: true }).last().click()
		const strict = await openTestModal(page, STRICT_SLUG)
		await runTest(page, strict, STRICT_SLUG)
		await expect(strict).toContainText('Refused (mapping-failed)', { timeout: 15_000 })
		await expect(strict.locator('pre'), 'a refused run shows no body').toHaveCount(0)
	})
})
