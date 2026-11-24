/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md
 * (REQ-ADEF-002 an administrator sets retention on the admin page, REQ-ADEF-003
 * changed retention can be applied to existing logs).
 *
 * What an administrator does here: open the integriq admin settings, read
 * each log retention in days or hours with its default beside it, keep call
 * logs for 90 days and see 90 days again after a reload, meet the server's
 * refusal of a negative value, and choose "Apply to existing logs", which
 * queues the background job and shows that it is queued.
 *
 * Left to PHPUnit: the resolver and its drift guard against the schema
 * defaults (REQ-ADEF-001, RetentionSettingsTest), the expiry a call is logged
 * with after the save (CallService with the resolver), the 403 for a user who
 * is not an administrator (SettingsControllerTest), and what the job does
 * once it runs (RetentionRebaseJobTest). A browser cannot see a cron job page
 * through the logs.
 *
 * Settings: the retention values read before each test are written back
 * after it. The rebase test reads the job list and the stored rebase
 * progress through occ (see support/flowWorker.ts for how occ is found) and
 * puts the progress back as it was. The queued job itself stays queued: it
 * then runs under the restored values, so it gives each log the expiry the
 * instance's own retention already implies.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { occ, parseOccJson } from './support/flowWorker.ts'
import { withRequestToken } from './support/requestToken.ts'

const SETTINGS_URL = '/index.php/apps/integriq/api/settings'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const REBASE_JOB = 'OCA\\Integriq\\BackgroundJob\\RetentionRebaseJob'
const REBASE_PROGRESS_KEY = 'retention_rebase'

const HOUR = 3_600_000
const DAY = 24 * HOUR

/** Each retention the page shows, with its unit (src/views/admin/retentionUnits.js). */
const FIELDS: Array<{ key: string, unit: 'days' | 'hours', label: string }> = [
	{ key: 'callLogRetention', unit: 'days', label: 'Call logs with an error, in days' },
	{ key: 'jobLogRetention', unit: 'days', label: 'Job logs with an error, in days' },
	{ key: 'syncLogRetention', unit: 'days', label: 'Synchronization logs with an error, in days' },
	{ key: 'syncContractLogRetention', unit: 'days', label: 'Synchronization contract logs with an error, in days' },
	{ key: 'eventMessageRetention', unit: 'days', label: 'Event messages, in days' },
	{ key: 'executionTraceRetention', unit: 'days', label: 'Execution traces, in days' },
	{ key: 'successLogRetention', unit: 'hours', label: 'Successful logs, in hours' },
]

interface RetentionSettings {
	retention: Record<string, number>
	defaults: Record<string, number>
	rebase: Record<string, unknown>
}

/**
 * Milliseconds in the unit the page shows, rounded as the page rounds them.
 *
 * @param ms The stored milliseconds.
 * @param unit 'days' or 'hours'.
 * @return The value in the unit.
 */
function inUnit(ms: number, unit: 'days' | 'hours'): number {
	return Math.round((Number(ms) / (unit === 'hours' ? HOUR : DAY)) * 100) / 100
}

/**
 * The headers a direct call to the settings route needs.
 *
 * @param request The request context (logged in as the administrator).
 * @return The headers.
 */
async function settingsHeaders(request: APIRequestContext): Promise<Record<string, string>> {
	return { ...HEADERS, ...(await withRequestToken(request)) }
}

/**
 * What the settings route holds now.
 *
 * @param request The request context (logged in as the administrator).
 * @return The retention, its defaults and the last rebase.
 */
async function storedSettings(request: APIRequestContext): Promise<RetentionSettings> {
	const resp = await request.get(SETTINGS_URL, { headers: await settingsHeaders(request) })
	expect(resp.status()).toBe(200)

	return resp.json()
}

/**
 * Open the integriq admin settings at the retention section.
 *
 * @param page The page.
 * @return Nothing; the section's fields are shown afterwards.
 */
async function openRetention(page: Page): Promise<void> {
	await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
	await expect(page.getByTestId('admin-retention-section')).toBeVisible({ timeout: 20_000 })
	await expect(page.getByTestId('admin-retention-callLogRetention')).toBeVisible({ timeout: 20_000 })
}

/**
 * An integriq app config value as occ reads it.
 *
 * @param key The app config key.
 * @return The raw value, or null when the key is not set.
 */
function appConfigValue(key: string): string | null {
	try {
		return occ().run(['config:app:get', 'integriq', key]).replace(/\n$/, '')
	} catch {
		return null
	}
}

/**
 * Put an integriq app config value back as it was read.
 *
 * @param key The app config key.
 * @param value The raw value, or null to remove the key.
 * @return Nothing.
 */
function restoreAppConfig(key: string, value: string | null): void {
	if (value === null) {
		occ().run(['config:app:delete', 'integriq', key])
		return
	}
	occ().run(['config:app:set', 'integriq', key, `--value=${value}`])
}

/**
 * The ids of the queued rebase jobs.
 *
 * @return The job ids.
 */
function queuedRebaseJobs(): string[] {
	const raw = occ().run(['background-job:list', `--class=${REBASE_JOB}`, '--output=json'])
	const rows = parseOccJson(raw, 'background-job:list') as Array<{ id: string | number }>

	return rows.map((row) => String(row.id))
}

test.describe('log retention on the admin page', () => {
	let original: Record<string, number> = {}

	test.beforeEach(async ({ request }) => {
		original = (await storedSettings(request)).retention
	})

	test.afterEach(async ({ request }) => {
		await request.put(SETTINGS_URL, {
			failOnStatusCode: false,
			headers: await settingsHeaders(request),
			data: { retention: original },
		})
	})

	test('each retention shows in days or hours with its default beside it', async ({ page, request }) => {
		const stored = await storedSettings(request)
		await openRetention(page)
		const section = page.getByTestId('admin-retention-section')

		for (const field of FIELDS) {
			const input = page.getByTestId(`admin-retention-${field.key}`)
			await expect(section.getByText(field.label, { exact: true })).toBeVisible()
			await expect(input).toHaveValue(String(inUnit(stored.retention[field.key], field.unit)))

			const row = section.locator('.retention-settings__row', { has: input })
			await expect(row).toContainText(`Default: ${inUnit(stored.defaults[field.key], field.unit)}`)
		}
	})

	// @e2e logs-and-statistics::an-administrator-keeps-call-logs-for-90-days
	test('an administrator keeps call logs for 90 days', async ({ page, request }) => {
		await openRetention(page)

		await page.getByTestId('admin-retention-callLogRetention').fill('90')
		await page.getByTestId('admin-retention-save').click()

		await expect
			.poll(async () => (await storedSettings(request)).retention.callLogRetention, { timeout: 10_000 })
			.toBe(90 * DAY)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('admin-retention-callLogRetention')).toHaveValue('90', { timeout: 20_000 })
	})

	test('a negative retention is refused, naming the field, and nothing changes', async ({ page, request }) => {
		await openRetention(page)

		await page.getByTestId('admin-retention-callLogRetention').fill('-5')
		await page.getByTestId('admin-retention-save').click()

		const section = page.getByTestId('admin-retention-section')
		await expect(section.getByRole('alert')).toContainText('callLogRetention', { timeout: 10_000 })
		expect((await storedSettings(request)).retention.callLogRetention).toBe(original.callLogRetention)
	})

	// existing-call-logs-follow-the-new-value is `@e2e exclude` for the job's
	// work; the spec asks Playwright only that the button queues it.
	test('"Apply to existing logs" queues the rebase job and shows it is queued', async ({ page, request }) => {
		const progressBefore = appConfigValue(REBASE_PROGRESS_KEY)
		try {
			// A rebase still marked queued or running disables the button.
			restoreAppConfig(REBASE_PROGRESS_KEY, null)
			const jobsBefore = queuedRebaseJobs()

			await openRetention(page)
			const button = page.getByTestId('admin-retention-rebase')
			await expect(button).toBeEnabled()

			const queued = page.waitForResponse(
				(resp) => resp.url().includes('/api/settings/rebase') && resp.request().method() === 'POST',
			)
			await button.click()
			const answer = await queued
			expect(answer.status()).toBe(202)
			expect(await answer.json()).toMatchObject({ queued: true })

			await expect(page.getByTestId('admin-retention-rebase-status')).toContainText('Queued', { timeout: 10_000 })
			await expect(button).toBeDisabled()

			expect((await storedSettings(request)).rebase).toMatchObject({ status: 'queued', processed: 0, updated: 0 })
			// IJobList::add() does not add a job identical to one already queued,
			// so a rebase left queued by an earlier run is the one that answers.
			const jobsAfter = queuedRebaseJobs()
			const added = jobsAfter.filter((id) => !jobsBefore.includes(id))
			expect(added.length, 'the button adds at most one rebase job').toBeLessThanOrEqual(1)
			expect(jobsAfter.length, 'a rebase job is in the job list').toBeGreaterThan(0)
		} finally {
			restoreAppConfig(REBASE_PROGRESS_KEY, progressBefore)
		}
	})
})
