/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md
 * (REQ-LOGF-001 every log page declares its filter controls, REQ-LOGF-002 a
 * filtered view is a link, REQ-LOGF-003 source and endpoint logs show only
 * their own direction).
 *
 * What an administrator does here: narrow the source logs by status, source
 * and a time window; land on the source logs from a source's "View logs" row
 * action and see the source picker set; narrow the traces by entry point and
 * status; filter the job logs and open the address in a second tab, where the
 * same controls and rows come back; and see that the source logs list no
 * inbound call and the endpoint logs no outbound one, whatever the address
 * asks for.
 *
 * The controls are drawn by @conduction/nextcloud-vue's CnLogsPage from the
 * `filterControls` declared on each page in src/manifest.json: a select keeps
 * its chosen option's index as `_f_<key>`, a reference picker keeps
 * `<key>=<id>`, a date range keeps `<key>[gte]` and `<key>[lte]`.
 *
 * Left to other suites: that every declared control names a schema property
 * and matches the row action keys (vitest tests/vitest/logFilterControls.spec.js),
 * and that every call log writer sets `direction` (PHPUnit CallServiceTest).
 *
 * Every row this file seeds carries this run's marker and is deleted
 * afterwards, together with the source and job it seeds.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomUUID } from 'crypto'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** This run's marker, so the rows are this run's and nothing else's. */
const RUN = `e2e-logf-${Date.now()}`

/** Every object this file stored, as `[schema, id]`, removed at the end. */
const seeded: Array<[string, string]> = []

/**
 * The id OpenRegister gives an object row.
 *
 * @param row The object as OpenRegister answers it.
 * @return The id, or an empty string.
 */
function idOf(row: Record<string, unknown>): string {
	const self = (row['@self'] ?? {}) as Record<string, unknown>
	return String(row.id ?? self.id ?? row.uuid ?? '')
}

/**
 * Store one object and remember it for removal.
 *
 * @param request The request context (logged in as the administrator).
 * @param schema The integriq schema slug.
 * @param data The object's properties.
 * @return The object's id.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data,
	})
	expect(resp.status(), `seeding a ${schema} must succeed`).toBeLessThan(300)
	const id = idOf(await resp.json())
	expect(id, `the seeded ${schema} must have an id`).not.toBe('')
	seeded.push([schema, id])

	return id
}

/**
 * Store an object a reference picker can offer.
 *
 * The pickers read the option value from the `uuid` property
 * (`optionsFrom.valueKey` in src/manifest.json), and OpenRegister keeps its
 * own id outside the object's properties, so the property is written to
 * match the id.
 *
 * @param request The request context (logged in as the administrator).
 * @param schema The integriq schema slug.
 * @param data The object's properties.
 * @return The object's id, which its `uuid` property now equals.
 */
async function seedPickable(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const wanted = randomUUID()
	const id = await seed(request, schema, { ...data, uuid: wanted })
	if (id !== wanted) {
		const resp = await request.put(`${OR_BASE}/${schema}/${id}`, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { ...data, uuid: id },
		})
		expect(resp.status(), `the ${schema}'s uuid must match its id`).toBeLessThan(300)
	}

	return id
}

/**
 * A log page's filter control.
 *
 * @param page The page.
 * @param key The control's key in src/manifest.json.
 * @return The control.
 */
function control(page: Page, key: string): Locator {
	return page.getByTestId(`cn-logs-page-filter-${key}`)
}

/**
 * Choose an option of a select or reference control.
 *
 * @param page The page.
 * @param key The control's key.
 * @param option The option's label.
 * @param search Text typed into the control first, to narrow a long list.
 * @return Nothing; the address holds the choice afterwards.
 */
async function choose(
	page: Page,
	key: string,
	option: string,
	search = '',
): Promise<void> {
	const input = control(page, key).locator('input').first()
	await expect(control(page, key), `the ${key} control must be drawn`).toBeVisible({ timeout: 30_000 })
	await input.click()
	if (search !== '') {
		await input.fill(search)
	}
	await page.getByRole('option', { name: option, exact: true }).first().click()
}

/**
 * Open a log page and wait for its list.
 *
 * @param page The page.
 * @param route The page's route, with any query.
 * @return The logs page.
 */
async function openLogs(page: Page, route: string): Promise<Locator> {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'domcontentloaded' })
	const logs = page.getByTestId('cn-logs-page')
	await expect(logs).toBeVisible({ timeout: 30_000 })

	return logs
}

/**
 * The ids of the rows a list request answered.
 *
 * @param body The answer of OpenRegister's object list.
 * @return The ids.
 */
function listedIds(body: { results?: Array<Record<string, unknown>> }): string[] {
	return (body.results ?? []).map(idOf)
}

test.describe('log filters', () => {
	test.afterAll(async ({ request }) => {
		for (const [schema, id] of seeded.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	test.describe('source logs', () => {
		let sourceName = ''
		let sourceId = ''
		let windowFrom = ''
		let windowTo = ''

		test.beforeAll(async ({ request }) => {
			sourceName = `${RUN} BRP Haal Centraal`
			sourceId = await seedPickable(request, 'source', {
				name: sourceName,
				location: 'https://brp.e2e.invalid',
				type: 'api',
				isEnabled: false,
			})

			// Yesterday 18:00 to today 08:00, UTC.
			const midnight = new Date()
			midnight.setUTCHours(0, 0, 0, 0)
			const hour = 3_600_000
			windowFrom = new Date(midnight.getTime() - 6 * hour).toISOString()
			windowTo = new Date(midnight.getTime() + 8 * hour).toISOString()
			const lastNight = new Date(midnight.getTime() - hour).toISOString()
			const twoNightsAgo = new Date(midnight.getTime() - 25 * hour).toISOString()

			const calls = [
				{ statusCode: 503, statusMessage: `${RUN} failed last night`, source: sourceId, created: lastNight },
				{ statusCode: 200, statusMessage: `${RUN} succeeded last night`, source: sourceId, created: lastNight },
				{ statusCode: 503, statusMessage: `${RUN} other source failed`, source: randomUUID(), created: lastNight },
				{ statusCode: 503, statusMessage: `${RUN} failed two nights ago`, source: sourceId, created: twoNightsAgo },
			]
			for (const call of calls) {
				await seed(request, 'call_log', {
					direction: 'outbound',
					request: { method: 'GET', url: 'https://brp.e2e.invalid/ingeschrevenpersonen' },
					...call,
				})
			}
		})

		// @e2e app-shell-and-logs-ui::an-administrator-finds-last-nights-failed-source-calls
		test('status, source and a time window narrow the list to last night\'s failed calls to that source', async ({ page }) => {
			const range = new URLSearchParams({ 'created[gte]': windowFrom, 'created[lte]': windowTo })
			const logs = await openLogs(page, `/sources/logs?${range.toString()}`)

			await expect(logs.getByText(`${RUN} failed last night`)).toBeVisible({ timeout: 30_000 })
			await expect(logs.getByText(`${RUN} failed two nights ago`), 'a call outside the window is not listed').toHaveCount(0)

			await choose(page, 'statusCode', 'Server error')
			await expect(page).toHaveURL(/_f_statusCode=2/)
			await expect(logs.getByText(`${RUN} succeeded last night`)).toHaveCount(0)

			await choose(page, 'source', sourceName, RUN)
			await expect(page).toHaveURL(new RegExp(`source=${sourceId}`))
			await expect(logs.getByText(`${RUN} other source failed`)).toHaveCount(0)

			await expect(logs.getByText(`${RUN} failed last night`)).toHaveCount(1)
			await expect(logs.getByText(`${RUN} failed two nights ago`)).toHaveCount(0)
		})

		// @e2e app-shell-and-logs-ui::a-row-action-and-a-picker-agree
		test('"View logs" on a source opens the source logs with the source picker set', async ({ page }) => {
			await page.goto(`${APP_BASE}/sources`, { waitUntil: 'domcontentloaded' })

			// The index search lives in the "Search and columns" sidebar.
			const search = page.getByPlaceholder('Type to search…')
			if (!(await search.isVisible({ timeout: 10_000 }).catch(() => false))) {
				await page.getByRole('button', { name: 'Search and columns' }).click()
			}
			await search.fill(sourceName)

			const row = page.locator('tr', { hasText: sourceName }).first()
			await expect(row).toBeVisible({ timeout: 30_000 })
			await row.getByRole('button', { name: /Actions/i }).first().click()
			await page.getByRole('menuitem', { name: 'View logs' }).click()

			await expect(page).toHaveURL(new RegExp(`/sources/logs\\?.*source=${sourceId}`), { timeout: 15_000 })
			await expect(control(page, 'source')).toContainText(sourceName, { timeout: 30_000 })

			const logs = page.getByTestId('cn-logs-page')
			await expect(logs.getByText(`${RUN} failed last night`)).toBeVisible({ timeout: 30_000 })
			await expect(logs.getByText(`${RUN} other source failed`)).toHaveCount(0)
		})
	})

	test.describe('traces', () => {
		const traces: Record<string, string> = {}

		test.beforeAll(async ({ request }) => {
			const now = new Date().toISOString()
			const kinds = [
				['failedSync', 'sync', 'failed'],
				['succeededSync', 'sync', 'success'],
				['failedJob', 'job', 'failed'],
			]
			for (const [name, entryPoint, status] of kinds) {
				traces[name] = await seed(request, 'execution_trace', {
					traceId: randomUUID(),
					entryPoint,
					entryPointId: RUN,
					status,
					triggeredBy: 'manual',
					startedAt: now,
					finishedAt: now,
					durationMs: 12,
					steps: [],
					created: now,
				})
			}
		})

		// @e2e app-shell-and-logs-ui::traces-filter-by-entry-point-and-status
		test('entry point and status narrow the traces to failed synchronizations', async ({ page }) => {
			await openLogs(page, '/traces')

			await choose(page, 'entryPoint', 'Synchronization')
			await expect(page).toHaveURL(/_f_entryPoint=3/)

			// The list the page asks for once both controls are set.
			const narrowed = page.waitForResponse((resp) => {
				const url = decodeURIComponent(resp.url())
				return url.includes('/execution_trace')
					&& url.includes('entryPoint=sync')
					&& url.includes('status=failed')
			}, { timeout: 30_000 })
			await choose(page, 'status', 'Failed')
			await expect(page).toHaveURL(/_f_status=2/)

			const ids = listedIds(await (await narrowed).json())
			expect(ids, 'the failed synchronization trace is listed').toContain(traces.failedSync)
			expect(ids, 'a successful synchronization trace is not').not.toContain(traces.succeededSync)
			expect(ids, 'a failed job trace is not').not.toContain(traces.failedJob)
		})
	})

	test.describe('job logs', () => {
		let jobName = ''

		test.beforeAll(async ({ request }) => {
			jobName = `${RUN} nightly import`
			const jobId = await seedPickable(request, 'job', {
				name: jobName,
				jobClass: 'OCA\\Integriq\\Service\\Job\\PingAction',
				isEnabled: false,
			})
			const now = new Date().toISOString()
			const rows = [
				{ level: 'ERROR', message: `${RUN} job failed`, jobId },
				{ level: 'INFO', message: `${RUN} job ran`, jobId },
				{ level: 'ERROR', message: `${RUN} other job failed`, jobId: randomUUID() },
			]
			for (const row of rows) {
				await seed(request, 'job_log', { ...row, created: now })
			}
		})

		// @e2e app-shell-and-logs-ui::a-colleague-opens-the-same-view
		test('a filtered job log reopened from its address shows the same controls and rows', async ({ page }) => {
			const logs = await openLogs(page, '/jobs/logs')

			await choose(page, 'level', 'Error')
			await choose(page, 'jobId', jobName, RUN)
			await expect(page).toHaveURL(/_f_level=3/)
			await expect(page).toHaveURL(/jobId=/)

			await expect(logs.getByText(`${RUN} job failed`)).toBeVisible({ timeout: 30_000 })
			await expect(logs.getByText(`${RUN} job ran`)).toHaveCount(0)
			await expect(logs.getByText(`${RUN} other job failed`)).toHaveCount(0)

			// The colleague opens the address in a tab of their own.
			const address = page.url()
			const colleague = await page.context().newPage()
			try {
				await colleague.goto(address, { waitUntil: 'domcontentloaded' })
				const theirLogs = colleague.getByTestId('cn-logs-page')

				await expect(control(colleague, 'level')).toContainText('Error', { timeout: 30_000 })
				await expect(control(colleague, 'jobId')).toContainText(jobName, { timeout: 30_000 })
				await expect(colleague).toHaveURL(address)

				await expect(theirLogs.getByText(`${RUN} job failed`)).toBeVisible({ timeout: 30_000 })
				await expect(theirLogs.getByText(`${RUN} job ran`)).toHaveCount(0)
				await expect(theirLogs.getByText(`${RUN} other job failed`)).toHaveCount(0)
			} finally {
				await colleague.close()
			}
		})
	})

	test.describe('call direction', () => {
		test.beforeAll(async ({ request }) => {
			const now = new Date().toISOString()
			await seed(request, 'call_log', {
				direction: 'inbound',
				statusCode: 200,
				statusMessage: `${RUN} inbound call`,
				created: now,
			})
			await seed(request, 'call_log', {
				direction: 'outbound',
				statusCode: 200,
				statusMessage: `${RUN} outbound call`,
				request: { method: 'GET', url: 'https://brp.e2e.invalid/ping' },
				created: now,
			})
		})

		// @e2e app-shell-and-logs-ui::an-inbound-call-is-not-a-source-log
		test('the source logs list only outbound calls, the endpoint logs only inbound ones', async ({ page }) => {
			const sourceLogs = await openLogs(page, '/sources/logs')
			await expect(sourceLogs.getByText(`${RUN} outbound call`)).toBeVisible({ timeout: 30_000 })
			await expect(sourceLogs.getByText(`${RUN} inbound call`)).toHaveCount(0)

			const endpointLogs = await openLogs(page, '/endpoints/logs')
			await expect(endpointLogs.getByText(`${RUN} inbound call`)).toBeVisible({ timeout: 30_000 })
			await expect(endpointLogs.getByText(`${RUN} outbound call`)).toHaveCount(0)
		})

		// @e2e app-shell-and-logs-ui::an-inbound-call-is-not-a-source-log
		test('an address asking for inbound calls does not widen the source logs', async ({ page }) => {
			const logs = await openLogs(page, '/sources/logs?direction=inbound')
			await expect(logs.getByText(`${RUN} outbound call`)).toBeVisible({ timeout: 30_000 })
			await expect(logs.getByText(`${RUN} inbound call`)).toHaveCount(0)
		})
	})
})
