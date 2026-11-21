/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tests/e2e/idp-registration-settings.spec.ts
 *
 * Spec coverage: openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md
 * (REQ-IDPC-008, Beheer > Authenticatie configures the registrations; the
 * screen side of REQ-IDPC-007, readiness is one answer for the screen and
 * the runtime). Task 12 of openspec/changes/portal-idp-broker-config/tasks.md.
 *
 * What an administrator does here, on the integriq admin settings page
 * (IdpRegistrationSettings.vue, IdpRegistrationModal.vue,
 * IdpRegistrationDeleteDialog.vue):
 *   - adds a SAML registration through the modal, sees only the SAML fields,
 *     picks the signing and salt credentials by reference and never meets a
 *     secret value, and finds the row with its metadata URL and a status;
 *   - runs "Test" on a registration whose salt credential is gone and reads
 *     the salt credential as failed by name, with the status the runtime uses;
 *   - opens the section without Keepiq and reads the note above the table;
 *   - deletes a registration through the dialog, which names the
 *     organisation whose logins stop.
 *
 * The browser boundary, as in sharepoint-publications.spec.ts: OpenRegister's
 * organisation list and credential list are answered with canned bodies,
 * because the credentials live in Keepiq and the nightly instance has no
 * organisation of its own to pick. The save is NOT mocked: the registration
 * goes to OpenRegister's object store and is read back from it, and the
 * overview and the Test checks come from integriq's own
 * `GET /api/idp/registrations` and `POST /api/idp/registrations/{id}/test`.
 *
 * Deliberately not covered here:
 *   - the metadata check passing. The Test fetches the identity provider's
 *     metadata over https from the server, and the nightly job has no https
 *     SAML metadata document the instance can reach, so the check is only
 *     asserted to be listed by name; that it passes against real metadata is
 *     PHPUnit's (IdpRegistrationTesterTest).
 *   - the copy action (needs clipboard permission in the browser).
 *   - a duplicate registration, a non-administrator, the metadata endpoint,
 *     the secret seam, pseudonymisation and every login: their scenarios
 *     carry `@e2e exclude` in the spec and are PHPUnit's and Newman's. A login
 *     through a real external identity provider cannot run in the nightly job.
 *
 * Runs as the admin the global setup logs in. Every registration this file
 * creates names an organisation uuid made for this run, and is removed at
 * the end.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { withRequestToken } from './support/requestToken.ts'

const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const OVERVIEW_URL = '/index.php/apps/integriq/api/idp/registrations'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** The two OpenRegister lists the modal's pickers read; answered in the browser. */
const ORGANISATIONS_ROUTE = '**/apps/openregister/api/organisations'
const CREDENTIALS_ROUTE = '**/apps/openregister/api/credentials'

/** One run's marker, so reruns never meet each other's registrations. */
const RUN = Date.now().toString(16).padStart(12, '0').slice(-12)

/** The organisation the add scenario registers, made for this run. */
const ORG_X = { uuid: `e2e00000-0000-4000-8000-${RUN}`, name: `E2E gemeente-x ${RUN}` }

/** The organisation the Test and delete scenarios register, made for this run. */
const ORG_Y = { uuid: `e2e00001-0000-4000-8000-${RUN}`, name: `E2E gemeente-y ${RUN}` }

/** Credential references as OpenRegister's broker lists them; none of them exists in Keepiq. */
const SIGNING = { id: 'e2e0c0de-0000-4000-8000-00000000051a', name: 'E2E IdP signing key', allowedApps: ['integriq'] }
const SALT = { id: 'e2e0c0de-0000-4000-8000-0000000005a1', name: 'E2E IdP pseudonym salt', allowedApps: ['integriq'] }

/**
 * A marker riding on the canned credential rows. The picker must never show
 * it: the modal lists credentials by name and holds their ids only.
 */
const NOT_A_SECRET = 'e2e-placeholder-not-a-real-secret'

/** The identity provider metadata address: an obvious test value nobody serves. */
const IDP_METADATA_URL = 'https://idp.example.org/e2e/saml/metadata'

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
 * Integriq's overview of the registrations, as the section reads it.
 *
 * @param request The request fixture.
 * @return The overview: brokerEnabled, keepiqAvailable and the rows.
 */
async function overview(request: APIRequestContext): Promise<Record<string, any>> {
	const resp = await request.get(OVERVIEW_URL, {
		headers: { ...HEADERS, ...(await withRequestToken(request)) },
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBe(200)

	return await resp.json()
}

/**
 * The overview rows of one organisation.
 *
 * @param request The request fixture.
 * @param organisation The organisation uuid.
 * @return The rows.
 */
async function rowsOf(request: APIRequestContext, organisation: string): Promise<Array<Record<string, any>>> {
	const rows = ((await overview(request)).registrations ?? []) as Array<Record<string, any>>

	return rows.filter((row) => row.organisation === organisation)
}

/**
 * Delete every registration this run made for one organisation.
 *
 * @param request The request fixture.
 * @param organisation The organisation uuid.
 */
async function removeRegistrationsOf(request: APIRequestContext, organisation: string): Promise<void> {
	for (const row of await rowsOf(request, organisation)) {
		await request.delete(`${OR_BASE}/idpRegistration/${row.id}`, { failOnStatusCode: false })
	}
}

/**
 * Answer the modal's pickers: the run's organisations, and two credentials
 * whose rows carry a marker the screen must never show.
 *
 * @param page The page.
 */
async function answerPickers(page: Page): Promise<void> {
	await page.route(ORGANISATIONS_ROUTE, (route) => route.fulfill({
		json: { results: [ORG_X, ORG_Y] },
	}))
	await page.route(CREDENTIALS_ROUTE, (route) => route.fulfill({
		json: { results: [SIGNING, SALT].map((credential) => ({ ...credential, value: NOT_A_SECRET })) },
	}))
}

/**
 * Open the admin settings page on the Authenticatie section.
 *
 * @param page The page.
 * @return The section.
 */
async function openSection(page: Page): Promise<Locator> {
	await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
	const section = page.getByTestId('admin-idp-registrations')
	await expect(section).toBeVisible({ timeout: 20_000 })
	await expect(section.getByRole('heading', { name: 'Authenticatie' })).toBeVisible()
	await expect(section).not.toContainText('Loading the registrations…', { timeout: 20_000 })

	return section
}

/**
 * Pick one option of an NcSelect by its visible label.
 *
 * @param page The page.
 * @param select The NcSelect (its data-testid sits on the root).
 * @param name The option's label.
 */
async function pick(page: Page, select: Locator, name: string): Promise<void> {
	await select.locator('input').first().click()
	await page.getByRole('option', { name }).first().click()
}

/**
 * The section's row of one organisation.
 *
 * @param section The section.
 * @param organisationName The organisation's name as the table shows it.
 * @return The row.
 */
function rowOf(section: Locator, organisationName: string): Locator {
	return section.locator('[data-testid^="idp-registration-row-"]').filter({ hasText: organisationName })
}

test.describe('portal-idp-broker-config: Beheer > Authenticatie', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		await removeRegistrationsOf(request, ORG_X.uuid)
		await removeRegistrationsOf(request, ORG_Y.uuid)
	})

	// @e2e idp-broker-configuration::an-administrator-adds-a-saml-registration
	test('an administrator adds a SAML registration', async ({ page, request }) => {
		await answerPickers(page)
		const before = await overview(request)
		const section = await openSection(page)

		// The scenario starts from no registrations; a shared instance may
		// already hold some, and then the button under the table adds.
		if ((before.registrations ?? []).length === 0) {
			await expect(section.getByTestId('idp-registrations-empty')).toBeVisible()
			await section.getByTestId('idp-registrations-add-empty').click()
		} else {
			await section.getByTestId('idp-registrations-add').click()
		}

		const modal = page.getByTestId('idp-registration-modal')
		await expect(modal.getByRole('heading', { name: 'Add registration' })).toBeVisible({ timeout: 10_000 })

		await pick(page, modal.getByTestId('idp-registration-organisation'), ORG_X.name)
		await pick(page, modal.getByTestId('idp-registration-provider'), 'DigiD')
		await pick(page, modal.getByTestId('idp-registration-kind'), 'SAML Service Provider')

		// Only the chosen kind's fields show.
		await expect(modal.getByTestId('idp-registration-metadata-url')).toBeVisible()
		await expect(modal.getByTestId('idp-registration-entity-id')).toBeVisible()
		await expect(modal.getByTestId('idp-registration-issuer')).toHaveCount(0)
		await expect(modal.getByTestId('idp-registration-client-id')).toHaveCount(0)

		// NcTextField puts data-testid on the input itself.
		await modal.getByTestId('idp-registration-metadata-url').fill(IDP_METADATA_URL)
		await pick(page, modal.getByTestId('idp-registration-credential-signing'), SIGNING.name)
		await pick(page, modal.getByTestId('idp-registration-credential-salt'), SALT.name)

		// No field takes or shows a secret.
		await expect(modal.locator('input[type="password"]')).toHaveCount(0)
		await expect(modal).not.toContainText(NOT_A_SECRET)
		for (const input of await modal.locator('input, textarea').all()) {
			expect(await input.inputValue(), 'no field holds a secret value').not.toContain(NOT_A_SECRET)
		}

		const saved = page.waitForRequest((req) => req.method() === 'POST' && req.url().includes('/api/objects/integriq/idpRegistration'))
		await modal.getByTestId('idp-registration-save').click()
		const payload = (await saved).postDataJSON()
		expect(payload).toMatchObject({
			organisation: ORG_X.uuid,
			provider: 'digid',
			kind: 'saml-sp',
			idpMetadataUrl: IDP_METADATA_URL,
			credentials: { signing: SIGNING.id, salt: SALT.id },
		})
		expect(JSON.stringify(payload), 'the payload carries credential ids, never a value').not.toContain(NOT_A_SECRET)

		await expect(modal).toHaveCount(0, { timeout: 15_000 })
		const row = rowOf(section, ORG_X.name)
		await expect(row).toBeVisible({ timeout: 15_000 })
		await expect(row).toContainText('DigiD')
		await expect(row).toContainText('SAML Service Provider')
		await expect(row.locator('code')).toContainText(`/api/idp/metadata/${ORG_X.uuid}/digid`)
		await expect(row.getByTestId('idp-registration-status')).not.toBeEmpty()
		await expect(row.getByTestId('idp-registration-copy')).toBeVisible()

		const stored = await rowsOf(request, ORG_X.uuid)
		expect(stored, 'one registration is stored for the organisation').toHaveLength(1)
		expect(stored[0].metadataUrl).toContain(`/api/idp/metadata/${ORG_X.uuid}/digid`)
	})

	test.describe('a registration whose salt credential is gone', () => {
		let registrationId = ''

		test.beforeAll(async ({ request }) => {
			// Enabled, complete, and referencing a signing and a salt
			// credential Keepiq does not hold: what is left once the salt
			// credential is deleted there.
			const resp = await request.post(`${OR_BASE}/idpRegistration`, {
				data: {
					organisation: ORG_Y.uuid,
					provider: 'digid',
					kind: 'saml-sp',
					enabled: true,
					label: `Seeded by tests/e2e/idp-registration-settings.spec.ts ${RUN}`,
					idpMetadataUrl: IDP_METADATA_URL,
					pseudonymisation: 'hmac',
					subjectMapping: { bsn: 'urn:nl-eid-gdi:1.0:id:BSN' },
					assuranceMapping: {},
					credentials: { signing: SIGNING.id, salt: SALT.id },
				},
				failOnStatusCode: false,
			})
			expect(resp.status(), await resp.text()).toBeLessThan(300)
			registrationId = idOf(await resp.json())
			expect(registrationId, 'the stored registration has an id').not.toBe('')
		})

		test.afterAll(async ({ request }) => {
			await removeRegistrationsOf(request, ORG_Y.uuid)
		})

		// @e2e idp-broker-configuration::test-reports-a-missing-credential-by-role
		test('Test reports a missing credential by role', async ({ page, request }) => {
			const { keepiqAvailable } = await overview(request)

			// The route the button calls: every check by name, the salt one failed.
			const direct = await request.post(`${OVERVIEW_URL}/${registrationId}/test`, {
				headers: { ...HEADERS, ...(await withRequestToken(request)) },
				failOnStatusCode: false,
			})
			expect(direct.status(), await direct.text()).toBe(200)
			const checks = (await direct.json()).checks as Array<Record<string, any>>
			expect(checks.map((check) => check.check)).toEqual(['metadata', 'credential:signing', 'credential:salt'])
			expect(checks.find((check) => check.check === 'credential:salt')).toMatchObject({ passed: false })

			await answerPickers(page)
			const section = await openSection(page)
			const row = rowOf(section, ORG_Y.name)
			await expect(row).toBeVisible({ timeout: 15_000 })

			const tested = page.waitForResponse((resp) => resp.url().includes(`/api/idp/registrations/${registrationId}/test`))
			await row.getByTestId('idp-registration-test').click()
			expect((await tested).status()).toBe(200)

			const shown = row.getByTestId('idp-registration-checks')
			await expect(shown).toBeVisible({ timeout: 15_000 })
			await expect(shown).toContainText('Salt credential: failed')
			await expect(shown, 'the metadata check is listed by name').toContainText('Identity provider metadata:')
			await expect(shown).not.toContainText(NOT_A_SECRET)

			// The screen shows the status the runtime acts on. Without Keepiq
			// no credential can be read at all, so the status names Keepiq.
			const [stored] = await rowsOf(request, ORG_Y.uuid)
			const status = row.getByTestId('idp-registration-status')
			if (keepiqAvailable) {
				expect(stored.status).toBe('secret-missing')
				await expect(status).toContainText('Credential missing')
				await expect(status).toContainText('salt')
			} else {
				expect(stored.status).toBe('keepiq-missing')
				await expect(status).toContainText('Keepiq missing')
			}
		})

		// REQ-IDPC-008: "Delete" asks for confirmation naming the organisation
		// whose logins stop. No scenario of its own, so no @e2e tag.
		test('Delete names the organisation whose logins stop', async ({ page, request }) => {
			await answerPickers(page)
			const section = await openSection(page)
			const row = rowOf(section, ORG_Y.name)
			await expect(row).toBeVisible({ timeout: 15_000 })
			await row.getByTestId('idp-registration-delete').click()

			// NcDialog: whether data-testid reaches its root is unconfirmed, so
			// the dialog is found by its role and name.
			const dialog = page.getByRole('dialog', { name: 'Delete registration' })
			await expect(dialog).toBeVisible({ timeout: 10_000 })
			await expect(dialog.getByTestId('idp-registration-delete-text')).toContainText(ORG_Y.name)
			await expect(dialog.getByTestId('idp-registration-delete-text')).toContainText('DigiD')

			await dialog.getByTestId('idp-registration-delete-confirm').click()
			await expect(row).toHaveCount(0, { timeout: 15_000 })
			expect(await rowsOf(request, ORG_Y.uuid)).toHaveLength(0)
		})
	})

	// @e2e idp-broker-configuration::without-keepiq-the-screen-says-why-nothing-can-be-ready
	test('without Keepiq the screen says why nothing can be ready', async ({ page, request }) => {
		const { keepiqAvailable } = await overview(request)

		// The nightly instance may or may not ship Keepiq, and a spec cannot
		// disable an app without a password confirmation. Where Keepiq is
		// there, the overview is answered as it would be without it; where it
		// is not, the real overview already says so and nothing is replaced.
		if (keepiqAvailable) {
			await page.route('**/apps/integriq/api/idp/registrations', async (route) => {
				const real = await route.fetch()
				await route.fulfill({ response: real, json: { ...(await real.json()), keepiqAvailable: false } })
			})
		}

		const section = await openSection(page)
		const note = section.getByTestId('idp-registrations-keepiq-missing')
		await expect(note).toBeVisible({ timeout: 15_000 })
		await expect(note).toContainText('Identity provider keys live in Keepiq.')
		await expect(note).toContainText('No registration can log anyone in until Keepiq is enabled.')

		// The note stands above the table, or above the empty state.
		const below = section.locator('[data-testid="idp-registrations-table"], [data-testid="idp-registrations-empty"]').first()
		await expect(below).toBeVisible()
		const noteBox = await note.boundingBox()
		const belowBox = await below.boundingBox()
		expect(noteBox && belowBox, 'both are laid out').toBeTruthy()
		expect(noteBox!.y, 'the note is above the table').toBeLessThan(belowBox!.y)
	})
})
