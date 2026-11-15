/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Jobs form's schedule helpers (src/modals/v2/jobSchedule.js),
 * and the day rules they offer against the schema fragment the backend validates.
 *
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	canPreview,
	DAY_RULES,
	defaultCalendar,
	joinList,
	scheduleType,
	splitList,
	withDayOfMonth,
	withType,
	withWeekday,
} from '../../src/modals/v2/jobSchedule.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

describe('scheduleType', () => {
	it('reads calendar only for a calendar schedule', () => {
		expect(scheduleType({ type: 'calendar' })).toBe('calendar')
		expect(scheduleType({ type: 'interval' })).toBe('interval')
		expect(scheduleType(null)).toBe('interval')
		expect(scheduleType(undefined)).toBe('interval')
	})
})

describe('withType', () => {
	it('starts a new calendar on working days at seven in the given zone', () => {
		expect(withType(null, 'calendar', 'Europe/Berlin')).toEqual({
			type: 'calendar',
			weekdays: [1, 2, 3, 4, 5],
			times: ['07:00'],
			timeZone: 'Europe/Berlin',
			skipDates: [],
		})
	})

	it('falls back to Amsterdam without a zone', () => {
		expect(defaultCalendar().timeZone).toBe('Europe/Amsterdam')
	})

	it('keeps the calendar fields a job already had', () => {
		const stored = { type: 'interval', weekdays: [6], times: ['09:15'], timeZone: 'UTC' }
		expect(withType(stored, 'calendar', 'Europe/Berlin')).toEqual({
			type: 'calendar', weekdays: [6], times: ['09:15'], timeZone: 'UTC', skipDates: [],
		})
	})

	it('stores only the type when switching back to an interval', () => {
		expect(withType({ type: 'calendar', weekdays: [1] }, 'interval')).toEqual({ type: 'interval' })
	})
})

describe('withWeekday', () => {
	it('adds a day in order and removes one', () => {
		const calendar = { type: 'calendar', weekdays: [5, 1] }
		expect(withWeekday(calendar, 3, true).weekdays).toEqual([1, 3, 5])
		expect(withWeekday(calendar, 5, false).weekdays).toEqual([1])
		expect(withWeekday(calendar, 1, true).weekdays).toEqual([1, 5])
		expect(calendar.weekdays).toEqual([5, 1])
	})
})

describe('withDayOfMonth', () => {
	it('sets a named rule or a number and drops an empty one', () => {
		expect(withDayOfMonth({ type: 'calendar' }, 'lastWorkingDay').dayOfMonth).toBe('lastWorkingDay')
		expect(withDayOfMonth({ type: 'calendar' }, 15).dayOfMonth).toBe(15)
		expect(withDayOfMonth({ type: 'calendar', dayOfMonth: 'first' }, null)).toEqual({ type: 'calendar' })
	})
})

describe('splitList and joinList', () => {
	it('splits on commas and spaces, dropping blanks and repeats', () => {
		expect(splitList('07:00, 12:30,,07:00  18:00')).toEqual(['07:00', '12:30', '18:00'])
		expect(splitList('')).toEqual([])
		expect(splitList(undefined)).toEqual([])
	})

	it('joins a list for display', () => {
		expect(joinList(['2026-12-25', '2026-12-26'])).toBe('2026-12-25, 2026-12-26')
		expect(joinList(undefined)).toBe('')
	})
})

describe('canPreview', () => {
	it('needs a calendar with weekdays, times and a zone', () => {
		expect(canPreview(defaultCalendar('UTC'))).toBe(true)
		expect(canPreview({ ...defaultCalendar('UTC'), weekdays: [] })).toBe(false)
		expect(canPreview({ ...defaultCalendar('UTC'), times: [] })).toBe(false)
		expect(canPreview({ ...defaultCalendar('UTC'), timeZone: '' })).toBe(false)
		expect(canPreview({ type: 'interval' })).toBe(false)
		expect(canPreview(null)).toBe(false)
	})
})

describe('the day rules match the schema fragment', () => {
	it('offers exactly the named rules the job schema accepts', () => {
		const fragment = JSON.parse(fs.readFileSync(path.join(root, 'lib/Settings/register.d/job-calendar-schedule.json'), 'utf8'))
		const enumValues = fragment.components.schemas.job.properties.schedule.properties.dayOfMonth.enum
		expect(enumValues.filter((value) => typeof value === 'string')).toEqual([...DAY_RULES])
	})
})
