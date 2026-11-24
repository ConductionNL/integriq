/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-24-automation-job-calendar-schedules/specs/job-scheduling/spec.md
 * (REQ-JCAL-001, a pull runs at seven on working days; REQ-JCAL-003, the
 * preview answers "which days").
 *
 * What an administrator does here: open a job in the Jobs form, switch its
 * schedule to "On a calendar", pick weekdays, a time, a time zone and a
 * day-of-month rule, and read the next five run times the form previews.
 *
 * The job's `nextRun` is written by the run loop on the cron worker, not on
 * save (D3 in the change), and a browser cannot tick that worker. So the first test
 * asserts the stored calendar, and asks the preview route, which runs the same
 * calculator the run loop does, for the first slot of that calendar. The run
 * loop itself (an interval job unchanged, a failure waiting for the next slot,
 * an edited calendar picked up on the next tick), the date calculations (last
 * working day, skipped dates, daylight saving) and the job to flow generator
 * (REQ-JCAL-002, REQ-JCAL-004) are covered by PHPUnit on JobService,
 * JobCalendarSchedule and JobToFlowGenerator; their scenarios carry
 * `@e2e exclude`.
 *
 * Each test seeds its own disabled job through OpenRegister's object API and
 * deletes it again, so nothing depends on the seed data and nothing runs.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { BASE_URL } from './support/baseUrl.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const PREVIEW_URL = `${APP_BASE}/api/jobs/schedule-preview`
const HEADERS = { 'OCS-APIRequest': 'true' }
const ZONE = 'Europe/Amsterdam'
const SYNCHRONIZATION_ACTION = 'OCA\\Integriq\\Service\\Job\\SynchronizationAction'
const OUTSIDER = 'e2e-job-calendar-outsider'
const OUTSIDER_PASSWORD = process.env.INTEGRIQ_E2E_DEV_PASS ?? 'Calendar-e2e-1!'

/** A run time as it reads on the wall clock of the calendar's time zone. */
interface WallClock {
	date: string
	year: number
	month: number
	day: number
	weekday: number
	time: string
}

/**
 * Seed one disabled synchronization job.
 *
 * @param request  The request fixture.
 * @param name     The job's name.
 * @param schedule The job's schedule, or undefined for an interval job.
 * @return The job's OpenRegister id.
 */
async function seedJob(
	request: APIRequestContext,
	name: string,
	schedule?: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/job`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: {
			name,
			description: 'Seeded by job-calendar-schedule.spec.ts',
			jobClass: SYNCHRONIZATION_ACTION,
			interval: 3600,
			isEnabled: false,
			...(schedule ? { schedule } : {}),
		},
	})
	expect([200, 201], await resp.text()).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, 'the seeded job has an id').toBe('string')

	return id as string
}

/**
 * The stored job.
 *
 * @param request The request fixture.
 * @param id      The job's OpenRegister id.
 * @return The job object.
 */
async function storedJob(
	request: APIRequestContext,
	id: string,
): Promise<Record<string, unknown>> {
	const resp = await request.get(`${OR_BASE}/job/${id}`, {
		failOnStatusCode: false,
		headers: HEADERS,
	})
	expect(resp.status()).toBe(200)

	return resp.json()
}

/**
 * Ask the preview route for the next five run times of a calendar.
 *
 * @param request  The request fixture.
 * @param schedule The calendar.
 * @return The run times, ISO 8601.
 */
async function previewRuns(
	request: APIRequestContext,
	schedule: unknown,
): Promise<string[]> {
	const resp = await request.post(PREVIEW_URL, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: { schedule },
	})
	expect(resp.status(), await resp.text()).toBe(200)

	return (await resp.json()).runs as string[]
}

/**
 * Open the Jobs form on one job through the row's Edit action.
 *
 * @param page The page.
 * @param name The job's name.
 * @return The dialog.
 */
async function openJobEditor(page: Page, name: string): Promise<Locator> {
	await page.goto(`${APP_BASE}/jobs`, { waitUntil: 'domcontentloaded' })
	const row = page.getByRole('row').filter({ hasText: name }).first()
	await expect(row, `the seeded job "${name}" is listed`).toBeVisible({ timeout: 30_000 })
	await row.getByRole('button', { name: /Actions/i }).first().click()
	await page.getByRole('menuitem', { name: /^Edit$/ }).click()

	// Jobs has no detail page, so Edit opens the form in place.
	const dialog = appDialog(page)
	await expect(dialog).toBeVisible({ timeout: 15_000 })

	return dialog
}

/**
 * The preview list items of the schedule section.
 *
 * @param dialog The job form.
 * @return The list items.
 */
function previewItems(dialog: Locator): Locator {
	return dialog.locator('.cn-job-schedule__preview li')
}

/**
 * The ISO weekday of a calendar date, 1 for Monday to 7 for Sunday.
 *
 * @param year  The year.
 * @param month The month, 1 to 12.
 * @param day   The day of the month.
 * @return The ISO weekday.
 */
function isoWeekday(year: number, month: number, day: number): number {
	const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay()

	return weekday === 0 ? 7 : weekday
}

/**
 * A moment as it reads on the wall clock of a time zone.
 *
 * @param moment The moment, as a Date or an ISO 8601 string.
 * @param zone   The IANA time zone.
 * @return The wall clock reading.
 */
function wallClock(moment: Date | string, zone: string): WallClock {
	const parts = Object.fromEntries(
		new Intl.DateTimeFormat('en-GB', {
			timeZone: zone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23',
		})
			.formatToParts(new Date(moment))
			.map((part) => [part.type, part.value]),
	)
	const year = Number(parts.year)
	const month = Number(parts.month)
	const day = Number(parts.day)

	return {
		date: `${parts.year}-${parts.month}-${parts.day}`,
		year,
		month,
		day,
		weekday: isoWeekday(year, month, day),
		time: `${parts.hour}:${parts.minute}`,
	}
}

/**
 * The date of the first Monday-to-Friday 07:00 slot after a moment, in a zone.
 *
 * @param now  The moment.
 * @param zone The IANA time zone.
 * @return The date, YYYY-MM-DD.
 */
function nextWorkingDayAtSeven(now: Date, zone: string): string {
	const today = wallClock(now, zone)
	for (let offset = 0; offset < 8; offset++) {
		const candidate = new Date(Date.UTC(today.year, today.month - 1, today.day + offset))
		const weekday = candidate.getUTCDay()
		const isWorkingDay = weekday >= 1 && weekday <= 5
		const stillAhead = offset > 0 || today.time < '07:00'
		if (isWorkingDay && stillAhead) {
			return candidate.toISOString().slice(0, 10)
		}
	}

	throw new Error('no working day within a week')
}

/**
 * The first Monday-to-Friday day number of a month.
 *
 * @param year  The year.
 * @param month The month, 1 to 12.
 * @return The day number.
 */
function firstWorkingDay(year: number, month: number): number {
	let day = 1
	while (isoWeekday(year, month, day) > 5) {
		day++
	}

	return day
}

/**
 * A request context logged in as one account, with no admin session.
 *
 * @param userId The account id.
 * @return A context the caller must dispose.
 */
async function asAccount(userId: string): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: OUTSIDER_PASSWORD, send: 'always' },
		extraHTTPHeaders: HEADERS,
	})
}

test.describe('automation-job-calendar-schedules: the schedule section of the job form', () => {
	const seeded: string[] = []

	test.afterEach(async ({ request }) => {
		for (const id of seeded.splice(0)) {
			await request.delete(`${OR_BASE}/job/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e job-scheduling::a-pull-runs-at-seven-on-working-days
	test('a synchronization job set to working days at seven runs next on a weekday at 07:00 Amsterdam time', async ({
		page,
		request,
	}) => {
		const name = `e2e-calendar-weekdays-${Date.now()}`
		const id = await seedJob(request, name)
		seeded.push(id)
		const dialog = await openJobEditor(page, name)

		await dialog.getByRole('radio', { name: 'On a calendar' }).check({ force: true })

		const days = dialog.getByRole('group', { name: 'Weekdays' }).getByRole('checkbox')
		await expect(days).toHaveCount(7)
		for (let index = 0; index < 7; index++) {
			// The boxes are Monday first (ISO 1 to 7); the labels follow the
			// browser's locale, so they are found by position, not by name.
			if (index < 5) {
				await days.nth(index).check({ force: true })
			} else {
				await days.nth(index).uncheck({ force: true })
			}
		}
		await dialog.getByRole('textbox', { name: 'Times of day' }).fill('07:00')
		await dialog.getByRole('textbox', { name: 'Time zone' }).fill(ZONE)
		await expect(previewItems(dialog)).toHaveCount(5, { timeout: 15_000 })

		await dialog.getByRole('button', { name: /^(Create|Save)$/ }).click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect
			.poll(async () => (await storedJob(request, id)).schedule, { timeout: 10_000 })
			.toMatchObject({
				type: 'calendar',
				weekdays: [1, 2, 3, 4, 5],
				times: ['07:00'],
				timeZone: ZONE,
			})

		const schedule = (await storedJob(request, id)).schedule
		const expectedDate = nextWorkingDayAtSeven(new Date(), ZONE)
		const runs = await previewRuns(request, schedule)
		expect(runs).toHaveLength(5)

		const first = wallClock(runs[0], ZONE)
		expect(first.date, 'the first run is the next weekday').toBe(expectedDate)
		expect(first.time, 'at seven, Amsterdam time').toBe('07:00')
		for (const run of runs) {
			const slot = wallClock(run, ZONE)
			expect(slot.weekday, `${run} falls on a working day`).toBeLessThanOrEqual(5)
			expect(slot.time, `${run} is at 07:00 Amsterdam time`).toBe('07:00')
		}
	})

	// @e2e job-scheduling::the-preview-answers-which-days
	test('switching the day-of-month rule to the first working day previews the next five first working days at 06:00', async ({
		page,
		request,
	}) => {
		const name = `e2e-calendar-first-working-day-${Date.now()}`
		const id = await seedJob(request, name, {
			type: 'calendar',
			weekdays: [1, 2, 3, 4, 5, 6, 7],
			dayOfMonth: 'first',
			times: ['06:00'],
			timeZone: ZONE,
			skipDates: [],
		})
		seeded.push(id)
		const dialog = await openJobEditor(page, name)

		await expect(dialog.getByRole('radio', { name: 'On a calendar' })).toBeChecked()
		await expect(previewItems(dialog)).toHaveCount(5, { timeout: 15_000 })

		const answered = page.waitForResponse((resp) => {
			if (!resp.url().includes('/api/jobs/schedule-preview') || resp.request().method() !== 'POST') {
				return false
			}
			return resp.request().postDataJSON()?.schedule?.dayOfMonth === 'firstWorkingDay'
		})
		const dayRule = dialog.locator('#cn-job-schedule-day')
		await dayRule.click()
		await dayRule.fill('First working')
		await page.getByRole('option', { name: 'First working day of the month' }).first().click()

		const response = await answered
		expect(response.status()).toBe(200)
		const runs = (await response.json()).runs as string[]
		expect(runs).toHaveLength(5)

		let previous: WallClock | null = null
		for (const run of runs) {
			const slot = wallClock(run, ZONE)
			expect(slot.time, `${run} is at 06:00 Amsterdam time`).toBe('06:00')
			expect(slot.day, `${run} is the first working day of its month`).toBe(firstWorkingDay(slot.year, slot.month))
			if (previous !== null) {
				const monthsApart = (slot.year - previous.year) * 12 + (slot.month - previous.month)
				expect(monthsApart, 'no month is skipped').toBe(1)
			}
			previous = slot
		}

		// The form shows those same five run times, formatted the way the
		// section formats them in this browser.
		const shown = await page.evaluate(
			([moments, zone]) => (moments as string[]).map((moment) => new Date(moment).toLocaleString(undefined, {
				weekday: 'long',
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
				timeZone: zone as string,
			})),
			[runs, ZONE],
		)
		await expect(previewItems(dialog)).toHaveText(shown)
	})

	test('the preview route refuses an account without the preview action', async ({ request }) => {
		// The route-level gate; the action check itself is covered by PHPUnit
		// on JobsController. A plain account holds no `job.schedule-preview`.
		await request.post('/index.php/ocs/v2.php/cloud/users?format=json', {
			failOnStatusCode: false,
			headers: HEADERS,
			form: { userid: OUTSIDER, password: OUTSIDER_PASSWORD },
		})
		const outsider = await asAccount(OUTSIDER)
		try {
			const resp = await outsider.post(PREVIEW_URL, {
				failOnStatusCode: false,
				data: {
					schedule: { type: 'calendar', weekdays: [1], times: ['07:00'], timeZone: ZONE },
				},
			})
			expect(resp.status()).toBe(403)
		} finally {
			await outsider.dispose()
			await request.delete(`/index.php/ocs/v2.php/cloud/users/${OUTSIDER}?format=json`, {
				failOnStatusCode: false,
				headers: HEADERS,
			})
		}
	})
})
