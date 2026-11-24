/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage:
 * openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md
 * (REQ-IRT-001, REQ-IRT-002, REQ-IRT-003, REQ-IRT-005), and the Verification
 * line of that change's tasks.md, which names this file.
 *
 * - REQ-IRT-001: the Test cases page (src/manifest.d/integration-regression-tests.json)
 *   lists the two seeded cases of `lowercase-keys` (Task 1).
 * - REQ-IRT-002: on a trace's detail page, "Save as test case"
 *   (SaveTestCaseModal) copies a mapping step's snapshot into a case, and a
 *   redacted `authorization` field is named and replaced before saving (Task 4).
 * - REQ-IRT-003: "Run test cases" (RegressionSuitePanel) on the mapping detail
 *   page shows one pass, one fail and the path whose value changed after a rule
 *   was edited (Task 5).
 * - REQ-IRT-005: the promotion preview (PromotePreviewModal, the Sources
 *   page's "Promote configuration" header action) lists the failing case and
 *   holds Confirm back until "Promote anyway" is ticked (Task 6). The target
 *   environment is this instance itself, reached over HTTP Basic as the admin,
 *   so the preview needs no second Nextcloud; which origin the server reaches
 *   itself on differs between the dev container (:80) and CI (:8080), so both
 *   are tried and the one that answers is kept. Confirm is never pressed: it
 *   would import the configuration into this same instance.
 *
 * "An engineer adds a case by hand" is `test.fixme`: its body is not written
 * yet. The add dialog edits `input` and `expectedOutput` as JSON (the page
 * gives both `widget: 'json'`, pinned by
 * tests/vitest/manifestObjectFieldsInDialog.spec.js); driving that JSON editor
 * has no precedent in this suite to copy.
 *
 * Not here, and why: the synchronization suite leaving the target untouched,
 * the ignored path, the 403 for a user without `regression-suite.run`, and
 * the promotion refused without the override carry `@e2e exclude` in the spec
 * and are PHPUnit's (RegressionSuiteServiceTest, OutputComparatorTest,
 * RegressionSuiteControllerTest, PromotionRegressionCheckTest).
 *
 * Runs as the admin the global setup logs in. The mappings, cases, runs,
 * trace, source, environment and configuration group this file creates are
 * removed at the end; the call log lines the preview writes stay, as every
 * outbound call's do.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomUUID } from 'crypto'
import { APP_BASE, navTo } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const CONFIGURATIONS_API = '/index.php/apps/openregister/api/configurations'
const API = '/index.php/apps/integriq/api'
const HEADERS = { 'OCS-APIRequest': 'true' }

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-irt-${Date.now().toString(36)}`

/** The redaction marker the trace writes in place of a sensitive value. */
const REDACTED = '***REDACTED***'

/** The two cases the register seeds for the seeded `lowercase-keys` mapping. */
const SEEDED_CASES = ['Lowercase keys gives no output without rules', 'Lowercase keys ignores dateModified']

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
 * Read one integriq object back through OpenRegister's object API.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param id The object's id.
 * @return The object.
 */
async function readObject(request: APIRequestContext, schema: string, id: string): Promise<Record<string, any>> {
	const resp = await request.get(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
	expect(resp.status(), await resp.text()).toBe(200)

	return resp.json()
}

/**
 * A mapping object with the given rules.
 *
 * @param slug Its slug, which its cases name it by.
 * @param rules Its mapping rules.
 * @param extra Any further field, such as its configuration group.
 * @return The mapping.
 */
function mapping(slug: string, rules: Record<string, string>, extra: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		name: `${slug} mapping`,
		slug,
		description: 'Seeded by tests/e2e/integration-regression-tests.spec.ts',
		passThrough: false,
		mapping: rules,
		unset: [],
		cast: {},
		...extra,
	}
}

/**
 * A test case of a mapping.
 *
 * @param name Its name.
 * @param subjectId The mapping's slug.
 * @param input Its input.
 * @param expectedOutput Its expected output.
 * @return The case.
 */
function mappingCase(name: string, subjectId: string, input: Record<string, unknown>, expectedOutput: Record<string, unknown>): Record<string, unknown> {
	return {
		name,
		description: 'Seeded by tests/e2e/integration-regression-tests.spec.ts',
		subjectType: 'mapping',
		subjectId,
		input,
		expectedOutput,
		ignoredPaths: [],
	}
}

/**
 * Seed a finished synchronization trace with a call step and a mapping step.
 *
 * @param request The request fixture.
 * @param mappingSlug The mapping the mapping step ran.
 * @param input The mapping step's input snapshot.
 * @param output The mapping step's output snapshot.
 * @return The trace's id, the key of its detail page.
 */
async function seedTrace(
	request: APIRequestContext,
	mappingSlug: string,
	input: Record<string, unknown>,
	output: Record<string, unknown>,
): Promise<string> {
	const now = new Date().toISOString()
	return createObject(request, 'execution_trace', {
		traceId: randomUUID(),
		entryPoint: 'sync',
		entryPointId: RUN,
		status: 'success',
		triggeredBy: 'manual',
		startedAt: now,
		finishedAt: now,
		durationMs: 42,
		steps: [
			{ order: 1, type: 'call', name: 'Fetch zaak', status: 'success', durationMs: 30, startedAt: now, input: {}, output: {} },
			{ order: 2, type: 'mapping', name: 'Map zaak', mappingId: mappingSlug, status: 'success', durationMs: 2, startedAt: now, input, output },
		],
		created: now,
	})
}

/**
 * Open a trace's detail page and its "Save as test case" dialog.
 *
 * @param page The page.
 * @param traceId The trace's id.
 * @return The dialog.
 */
async function openSaveDialog(page: Page, traceId: string): Promise<Locator> {
	await page.goto(`${APP_BASE}/traces/${traceId}`, { waitUntil: 'domcontentloaded' })
	await expect(page.getByRole('heading', { name: 'Execution trace' })).toBeVisible({ timeout: 20_000 })
	await page.getByTestId('save-as-test-case').click()

	// NcModal is not known to pass its data-testid through, so the dialog is
	// found by its heading inside the app's own dialog.
	const dialog = appDialog(page)
	await expect(dialog.getByRole('heading', { name: 'Save as test case' })).toBeVisible({ timeout: 15_000 })

	return dialog
}

/**
 * Press "Save test case" and return the stored case the route answers.
 *
 * @param page The page.
 * @param dialog The open dialog.
 * @param traceId The trace's id.
 * @return The case as stored, with its id and the paths still redacted.
 */
async function saveCase(page: Page, dialog: Locator, traceId: string): Promise<Record<string, any>> {
	const saved = page.waitForResponse((resp) => resp.url().includes(`/api/execution-traces/${traceId}/test-case`))
	await dialog.getByTestId('save-test-case-confirm').click()
	const resp = await saved
	expect(resp.status(), await resp.text()).toBe(200)

	return resp.json()
}

test.describe('integration-regression-tests', () => {
	test.describe.configure({ mode: 'serial' })

	const cleanup: Array<[string, string]> = []

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of cleanup.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS, failOnStatusCode: false })
		}
	})

	test.describe('REQ-IRT-001: the Test cases page', () => {
		test('Test cases is in the Automation menu and lists the two seeded cases of lowercase-keys', async ({ page }) => {
			await navTo(page, 'Test cases', '/test-cases')
			await expect(page.getByRole('heading', { name: 'Test cases' }).first()).toBeVisible({ timeout: 15_000 })

			for (const name of SEEDED_CASES) {
				const row = page.getByTestId('cn-object-row').filter({ hasText: name }).first()
				await expect(row, `the seeded case "${name}" is listed`).toBeVisible({ timeout: 20_000 })
				await expect(row).toContainText('mapping')
				await expect(row).toContainText('lowercase-keys')
			}
		})

		// @e2e integration-regression-tests::an-engineer-adds-a-case-by-hand
		test('an engineer adds a case by hand', () => {
			test.fixme(
				true,
				'the body is not written yet; the JSON editor for input and expected output has no driven precedent here, see the header',
			)
		})
	})

	test.describe('REQ-IRT-002: Save as test case on a trace', () => {
		const slug = `${RUN}-trace`

		test.beforeAll(async ({ request }) => {
			cleanup.push(['mapping', await createObject(request, 'mapping', mapping(slug, { name: '{{ Name }}' }))])
		})

		// @e2e integration-regression-tests::a-real-run-becomes-a-case
		test('saving the mapping step stores a case whose input and expected output are the step snapshot', async ({ page, request }) => {
			const input = { Name: 'Zaak 1', Status: 'open' }
			const output = { name: 'Zaak 1' }
			const traceId = await seedTrace(request, slug, input, output)
			cleanup.push(['execution_trace', traceId])

			const dialog = await openSaveDialog(page, traceId)
			await expect(dialog, 'the mapping step is chosen').toContainText('2. Map zaak (mapping)')
			await expect(dialog.getByTestId('save-test-case-redacted')).toHaveCount(0)
			// NcTextField puts data-testid on the input itself.
			await dialog.getByTestId('save-test-case-name').fill(`${RUN} real run`)

			const stored = await saveCase(page, dialog, traceId)
			cleanup.push(['integration_test_case', String(stored.id)])
			expect(stored).toMatchObject({ name: `${RUN} real run`, subjectType: 'mapping', subjectId: slug, input, expectedOutput: output })
			expect(stored.redactedPaths).toEqual([])
			await expect(appDialog(page).getByRole('heading', { name: 'Save as test case' }), 'the dialog closes on save').toHaveCount(0)

			const readBack = await readObject(request, 'integration_test_case', String(stored.id))
			expect(readBack.input).toEqual(input)
			expect(readBack.expectedOutput).toEqual(output)
		})

		// @e2e integration-regression-tests::redacted-fields-are-shown-before-save
		test('a redacted authorization field is named and replaced before saving', async ({ page, request }) => {
			const traceId = await seedTrace(request, slug, { Name: 'Zaak 2', authorization: REDACTED }, { name: 'Zaak 2' })
			cleanup.push(['execution_trace', traceId])

			const dialog = await openSaveDialog(page, traceId)
			await expect(dialog.getByTestId('save-test-case-redacted')).toContainText('These fields were redacted when the trace was stored.')
			// The field is labelled by its path; NcTextField puts data-testid on the input.
			const field = dialog.getByTestId('save-test-case-redacted-input.authorization')
			await expect(field).toBeVisible()
			await expect(dialog.getByLabel('input.authorization')).toBeVisible()
			await field.fill('Bearer e2e-test-token')
			await dialog.getByTestId('save-test-case-name').fill(`${RUN} redacted`)

			const stored = await saveCase(page, dialog, traceId)
			cleanup.push(['integration_test_case', String(stored.id)])
			expect(stored.input).toEqual({ Name: 'Zaak 2', authorization: 'Bearer e2e-test-token' })
			expect(stored.redactedPaths, 'no field is left redacted').toEqual([])
		})
	})

	test.describe('REQ-IRT-003: Run test cases on the mapping detail page', () => {
		const slug = `${RUN}-panel`
		let mappingId = ''

		test.beforeAll(async ({ request }) => {
			mappingId = await createObject(request, 'mapping', mapping(slug, { name: '{{ Name }}', status: '{{ Status }}' }))
			cleanup.push(['mapping', mappingId])
			cleanup.push(['integration_test_case', await createObject(request, 'integration_test_case', mappingCase(
				`${RUN} open zaak`,
				slug,
				{ Name: 'Zaak 1', Status: 'open' },
				{ name: 'Zaak 1', status: 'open' },
			))])
			cleanup.push(['integration_test_case', await createObject(request, 'integration_test_case', mappingCase(
				`${RUN} closed zaak`,
				slug,
				{ Name: 'Zaak 2', Status: 'closed' },
				{ name: 'Zaak 2', status: 'closed' },
			))])

			// Yesterday's run: both cases pass against the rules as they were.
			const before = await request.post(`${API}/regression-suites/mapping/${slug}/run`, { headers: HEADERS, failOnStatusCode: false })
			expect(before.status(), await before.text()).toBe(200)
			const run = await before.json()
			cleanup.push(['integration_test_run', String(run.id)])
			expect(run).toMatchObject({ total: 2, passed: 2, failed: 0 })
		})

		// @e2e integration-regression-tests::an-edited-mapping-breaks-a-case
		test('after a rule is edited, the run shows one pass, one fail and the path whose value changed', async ({ page, request }) => {
			const edited = await request.patch(`${OR_BASE}/mapping/${mappingId}`, {
				headers: HEADERS,
				data: { mapping: { name: '{{ Name }}', status: 'closed' } },
				failOnStatusCode: false,
			})
			expect(edited.status(), await edited.text()).toBeLessThan(300)

			await page.goto(`${APP_BASE}/mappings/${mappingId}`, { waitUntil: 'domcontentloaded' })
			const panel = page.getByTestId('regression-suite-panel')
			await expect(panel).toBeVisible({ timeout: 20_000 })

			const ran = page.waitForResponse((resp) => resp.url().includes(`/api/regression-suites/mapping/${slug}/run`))
			await panel.getByTestId('regression-suite-run').click()
			const resp = await ran
			expect(resp.status(), 'the panel runs the cases by the mapping slug').toBe(200)
			cleanup.push(['integration_test_run', String((await resp.json()).id)])

			const result = panel.getByTestId('regression-suite-result')
			await expect(result).toContainText('Passed: 1', { timeout: 15_000 })
			await expect(result).toContainText('Failed: 1')

			const failures = panel.getByTestId('regression-suite-failure')
			await expect(failures).toHaveCount(1)
			await expect(failures.first()).toContainText(`${RUN} open zaak`)
			const row = failures.first().locator('tbody tr')
			await expect(row).toHaveCount(1)
			await expect(row.locator('td').nth(0)).toHaveText('status')
			await expect(row.locator('td').nth(1)).toHaveText('"open"')
			await expect(row.locator('td').nth(2)).toHaveText('"closed"')
		})
	})

	test.describe('REQ-IRT-005: the promotion preview', () => {
		const slug = `${RUN}-promote`
		const caseName = `${RUN} failing on promotion`
		const environmentSlug = `${RUN}-acceptance`
		const environmentName = `Acceptance ${RUN}`
		const configurationTitle = `E2E promotion ${RUN}`
		let configurationId = ''
		let configurationRowId = ''
		let sourceId = ''
		let caseId = ''

		/**
		 * Run the preview the modal runs, through the route.
		 *
		 * @param request The request fixture.
		 * @return The status and the answer.
		 */
		async function preview(request: APIRequestContext): Promise<{ status: number, body: Record<string, any> }> {
			const resp = await request.post(`${API}/promotions/preview`, {
				headers: HEADERS,
				data: { configurationId, targetEnvironmentSlug: environmentSlug, credentialBindings: [] },
				failOnStatusCode: false,
			})
			const text = await resp.text()
			let body: Record<string, any>
			try {
				body = JSON.parse(text)
			} catch {
				body = { raw: text }
			}

			return { status: resp.status(), body }
		}

		test.beforeAll(async ({ request }) => {
			// A configuration group is a native OpenRegister table, not an object schema.
			const group = await request.post(CONFIGURATIONS_API, {
				headers: { ...HEADERS, Accept: 'application/json' },
				data: {
					title: configurationTitle,
					description: 'Seeded by tests/e2e/integration-regression-tests.spec.ts',
					type: 'configuration',
					app: 'integriq',
					version: '1.0.0',
				},
				failOnStatusCode: false,
			})
			expect(group.status(), await group.text()).toBeLessThan(300)
			const created = await group.json()
			// The modal sends `uuid || id`, and the export matches it as a string.
			configurationId = String(created.uuid ?? created.id ?? '')
			configurationRowId = String(created.id ?? created.uuid ?? '')
			expect(configurationId).not.toBe('')

			cleanup.push(['mapping', await createObject(request, 'mapping', mapping(slug, { name: '{{ Name }}' }, { configurations: [configurationId] }))])
			caseId = await createObject(request, 'integration_test_case', mappingCase(caseName, slug, { Name: 'Zaak 9' }, { name: 'Zaak 10' }))
			cleanup.push(['integration_test_case', caseId])

			sourceId = await createObject(request, 'source', {
				name: `${RUN} acceptance instance`,
				description: 'Seeded by tests/e2e/integration-regression-tests.spec.ts: this instance, as the promotion target',
				location: 'http://localhost',
				type: 'api',
				isEnabled: true,
				auth: 'basic',
				username: ADMIN_USER,
				password: ADMIN_PASS,
			})
			cleanup.push(['source', sourceId])

			const environment = await request.post(`${API}/environments`, {
				headers: HEADERS,
				data: { name: environmentName, slug: environmentSlug, role: 'acceptance', sourceRef: sourceId },
				failOnStatusCode: false,
			})
			expect(environment.status(), await environment.text()).toBe(201)
			cleanup.push(['environment', String((await environment.json()).uuid)])

			// Keep whichever origin the server reaches itself on.
			let last = { status: 0, body: {} as Record<string, any> }
			for (const origin of ['http://localhost', 'http://localhost:8080']) {
				const patched = await request.patch(`${OR_BASE}/source/${sourceId}`, {
					headers: HEADERS,
					data: { location: origin },
					failOnStatusCode: false,
				})
				expect(patched.status(), await patched.text()).toBeLessThan(300)
				last = await preview(request)
				if (last.status === 200) {
					break
				}
			}
			expect(last.status, `the preview reaches this instance as its own target: ${JSON.stringify(last.body)}`).toBe(200)
		})

		test.afterAll(async ({ request }) => {
			if (configurationRowId !== '') {
				await request.delete(`${CONFIGURATIONS_API}/${configurationRowId}`, { headers: HEADERS, failOnStatusCode: false })
			}
		})

		// @e2e integration-regression-tests::a-failing-case-is-visible-on-the-preview
		test('the preview lists the failing case next to the create and update counts, and Confirm waits for the override', async ({ page, request }) => {
			const answered = await preview(request)
			expect(answered.body.failingTestCases).toEqual(
				expect.arrayContaining([expect.objectContaining({ caseId, caseName, subjectType: 'mapping' })]),
			)

			await page.goto(`${APP_BASE}/sources`, { waitUntil: 'domcontentloaded' })
			const actions = page.getByTestId('cn-actions')
			await expect(actions, 'the Sources page renders its actions bar').toBeVisible({ timeout: 20_000 })
			await actions.getByRole('button').first().click()
			await page.getByRole('menuitem', { name: 'Promote configuration' }).click()

			const modal = appDialog(page)
			await expect(modal.getByRole('heading', { name: 'Promote configuration' })).toBeVisible({ timeout: 15_000 })

			// NcSelect carries its data-testid on its wrapper.
			await modal.getByTestId('promote-configuration-select').locator('input').first().click()
			await page.getByRole('option', { name: configurationTitle }).first().click()
			await modal.getByTestId('promote-target-environment-select').locator('input').first().click()
			await page.getByRole('option', { name: environmentName }).first().click()

			const previewed = page.waitForResponse((resp) => resp.url().includes('/api/promotions/preview'))
			await modal.getByTestId('promote-run-preview').click()
			expect((await previewed).status()).toBe(200)

			await expect(modal.getByTestId('promote-preview-creates')).toBeVisible({ timeout: 20_000 })
			await expect(modal.getByTestId('promote-preview-updates')).toBeVisible()

			const failing = modal.getByTestId('promote-preview-failing-tests')
			await expect(failing).toBeVisible()
			await expect(failing).toContainText('Failing test cases in this configuration:')
			const entry = modal.getByTestId(`promote-failing-test-${caseId}`)
			await expect(entry).toContainText(caseName)
			await expect(entry).toContainText(`mapping ${slug}`)

			const confirm = modal.getByTestId('promote-confirm')
			await expect(confirm, 'Confirm waits for the override').toBeDisabled()
			// NcCheckboxRadioSwitch puts data-testid on the input itself.
			await modal.getByTestId('promote-override-failing-tests').check({ force: true })
			await expect(confirm, 'the override releases Confirm').toBeEnabled()
			// Confirm is not pressed: the target is this instance itself.
		})
	})
})
