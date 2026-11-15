/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure helpers for the schedule section of the Jobs form
 * (`modals/v2/JobScheduleFields.vue`). Free of Vue, axios and DOM access so
 * they can be unit-tested directly, like `jobDraft.js`.
 *
 * A job's `schedule` is `{ type: 'interval' }` (today's behaviour, reading
 * `interval`) or a calendar: ISO weekdays (1 is Monday), an optional
 * day-of-month rule, HH:MM times, an IANA time zone and dates to skip. The
 * shape is declared in lib/Settings/register.d/job-calendar-schedule.json and
 * read by lib/Service/JobCalendarSchedule.php.
 *
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
 */

/** ISO weekday numbers, Monday first. */
export const WEEKDAY_NUMBERS = Object.freeze([1, 2, 3, 4, 5, 6, 7])

/** The day-of-month rules that are not a day number, in the order the form offers them. */
export const DAY_RULES = Object.freeze(['first', 'last', 'firstWorkingDay', 'lastWorkingDay'])

/** The time zone a new calendar starts in when the browser cannot tell. */
export const FALLBACK_TIME_ZONE = 'Europe/Amsterdam'

/**
 * The schedule's type: `calendar`, or `interval` for anything else.
 *
 * @param {object|null|undefined} schedule The stored schedule.
 *
 * @return {string} The type.
 */
export function scheduleType(schedule) {
	return schedule && schedule.type === 'calendar' ? 'calendar' : 'interval'
}

/**
 * A new calendar: working days at 07:00 in the given zone.
 *
 * @param {string} [timeZone] The browser's zone.
 *
 * @return {object} The calendar.
 */
export function defaultCalendar(timeZone) {
	return {
		type: 'calendar',
		weekdays: [1, 2, 3, 4, 5],
		times: ['07:00'],
		timeZone: timeZone || FALLBACK_TIME_ZONE,
		skipDates: [],
	}
}

/**
 * The schedule after switching its type. Switching to a calendar keeps the
 * calendar fields a job already had; switching to an interval stores only the
 * type, so `interval` governs again.
 *
 * @param {object|null|undefined} schedule The stored schedule.
 * @param {string} type `interval` or `calendar`.
 * @param {string} [timeZone] The browser's zone, for a new calendar.
 *
 * @return {object} The schedule.
 */
export function withType(schedule, type, timeZone) {
	if (type !== 'calendar') {
		return { type: 'interval' }
	}
	return { ...defaultCalendar(timeZone), ...(schedule || {}), type: 'calendar' }
}

/**
 * The calendar with one weekday switched on or off, kept sorted.
 *
 * @param {object} schedule The calendar.
 * @param {number} day The ISO weekday.
 * @param {boolean} on Whether the day is chosen.
 *
 * @return {object} The calendar.
 */
export function withWeekday(schedule, day, on) {
	const days = new Set(schedule.weekdays || [])
	if (on) {
		days.add(day)
	} else {
		days.delete(day)
	}
	return { ...schedule, weekdays: [...days].sort((a, b) => a - b) }
}

/**
 * The calendar with a day-of-month rule, or without one for an empty value.
 *
 * @param {object} schedule The calendar.
 * @param {string|number|null} rule A named rule, a day number, or null.
 *
 * @return {object} The calendar.
 */
export function withDayOfMonth(schedule, rule) {
	const next = { ...schedule }
	if (rule === null || rule === undefined || rule === '') {
		delete next.dayOfMonth
	} else {
		next.dayOfMonth = rule
	}
	return next
}

/**
 * Comma- or space-separated text as a list, blanks dropped, repeats removed.
 *
 * @param {string} text The text.
 *
 * @return {Array<string>} The items.
 */
export function splitList(text) {
	return [...new Set(String(text || '').split(/[\s,]+/).filter((item) => item !== ''))]
}

/**
 * A list as the text the form shows.
 *
 * @param {Array<string>|undefined} list The list.
 *
 * @return {string} The text.
 */
export function joinList(list) {
	return Array.isArray(list) ? list.join(', ') : ''
}

/**
 * Whether a calendar has what the preview route needs.
 *
 * @param {object|null|undefined} schedule The schedule.
 *
 * @return {boolean} True when a preview can be asked for.
 */
export function canPreview(schedule) {
	return scheduleType(schedule) === 'calendar'
		&& Array.isArray(schedule.weekdays) && schedule.weekdays.length > 0
		&& Array.isArray(schedule.times) && schedule.times.length > 0
		&& typeof schedule.timeZone === 'string' && schedule.timeZone !== ''
}
