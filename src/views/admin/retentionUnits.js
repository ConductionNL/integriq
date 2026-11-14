// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Log retention in the units an administrator reads, and back.
 *
 * The stored retention is milliseconds, so a value set through `occ` keeps
 * meaning what it meant; the admin page shows days, and hours for successful
 * logs, which are kept for an hour by default.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

const HOUR = 3600000
const DAY = 24 * HOUR

/**
 * Each retention the page shows, in the order it shows them, with its unit.
 *
 * @type {Array<{key: string, unit: string}>}
 */
export const RETENTION_FIELDS = [
	{ key: 'callLogRetention', unit: 'days' },
	{ key: 'jobLogRetention', unit: 'days' },
	{ key: 'syncLogRetention', unit: 'days' },
	{ key: 'syncContractLogRetention', unit: 'days' },
	{ key: 'eventMessageRetention', unit: 'days' },
	{ key: 'executionTraceRetention', unit: 'days' },
	{ key: 'successLogRetention', unit: 'hours' },
]

/**
 * Milliseconds as a number of the unit, rounded to two decimals.
 *
 * @param {number} ms The stored milliseconds.
 * @param {string} unit 'days' or 'hours'.
 * @return {number} The value in the unit.
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */
export function toUnit(ms, unit) {
	const size = unit === 'hours' ? HOUR : DAY
	return Math.round((Number(ms) / size) * 100) / 100
}

/**
 * A typed value in the unit as whole milliseconds, or null when it is not
 * a number of 0 or more.
 *
 * @param {string|number} value The typed value.
 * @param {string} unit 'days' or 'hours'.
 * @return {number|null} Milliseconds.
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */
export function toMilliseconds(value, unit) {
	if (value === '' || value === null || value === undefined) {
		return null
	}
	const number = Number(value)
	if (!Number.isFinite(number) || number < 0) {
		return null
	}
	return Math.round(number * (unit === 'hours' ? HOUR : DAY))
}
