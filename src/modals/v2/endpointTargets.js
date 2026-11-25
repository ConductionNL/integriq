/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * An api endpoint's `targets` (the sources it routes to, by rule and weight)
 * as the editable rows the endpoint editor shows, and back
 * (gateway-upstream-routing, task 4).
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

/**
 * Whether a value is a rule object with at least one key.
 *
 * @param {object|null|undefined} value The candidate `when`.
 * @return {boolean}
 */
function isRule(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0
}

/**
 * The stored targets as rows. A target's `when` rule is carried along
 * unchanged: the editor shows that a row has one, it does not author it.
 *
 * @param {Array<object>|undefined} targets The endpoint's `targets`.
 * @return {Array<{source: string, weight: number, group: string, when: object|null}>}
 * @spec openspec/changes/archive/2026-11-25-gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
 */
export function targetRowsOf(targets) {
	if (!Array.isArray(targets)) return []
	return targets
		.filter((target) => target && typeof target === 'object')
		.map((target) => ({
			source: typeof target.source === 'string' ? target.source : '',
			weight: weightOf(target.weight),
			group: typeof target.group === 'string' ? target.group : '',
			when: isRule(target.when) ? target.when : null,
		}))
}

/**
 * A typed weight as the whole number the schema takes: at least 0, 1 when
 * nothing usable was typed.
 *
 * @param {number|string} value The typed weight.
 * @return {number}
 * @spec openspec/changes/archive/2026-11-25-gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
 */
export function weightOf(value) {
	if (value === '' || value === null || value === undefined) return 1
	const weight = Math.floor(Number(value))
	if (!Number.isFinite(weight)) return 1
	return Math.max(0, weight)
}

/**
 * The rows as the stored targets: a row without a source is left out, the
 * weight is a whole number of at least 0, an empty group is not written and a
 * rule is kept as it was.
 *
 * @param {Array<{source: string, weight: number|string, group?: string, when?: object|null}>} rows The rows.
 * @return {Array<{source: string, weight: number, group?: string, when?: object}>}
 * @spec openspec/changes/archive/2026-11-25-gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
 */
export function targetsOf(rows) {
	const targets = []
	for (const row of Array.isArray(rows) ? rows : []) {
		const source = String(row && row.source ? row.source : '').trim()
		if (source === '') continue
		const target = { source, weight: weightOf(row.weight) }
		const group = String(row.group || '').trim()
		if (group !== '') target.group = group
		if (isRule(row.when)) target.when = row.when
		targets.push(target)
	}
	return targets
}

/**
 * The group a row splits traffic in, as the runtime forms them: rows without
 * a rule share the default group, a rule row with a group name shares that
 * group, and a rule row without one is a group of its own.
 *
 * @param {{group?: string, when?: object|null}} row The row.
 * @param {number} index The row's position.
 * @return {string}
 */
function groupKeyOf(row, index) {
	if (!isRule(row.when)) return 'default'
	const group = String(row.group || '').trim()
	return group !== '' ? 'group:' + group : 'rule:' + index
}

/**
 * Each row's share of its group's calls, as a whole percentage. A group whose
 * weights add up to 0 is split evenly, as the runtime does.
 *
 * @param {Array<{source: string, weight: number|string, group?: string, when?: object|null}>} rows The rows.
 * @return {number[]} One percentage per row.
 * @spec openspec/changes/archive/2026-11-25-gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
 */
export function sharesOf(rows) {
	const list = Array.isArray(rows) ? rows : []
	const totals = {}
	const counts = {}
	list.forEach((row, index) => {
		const key = groupKeyOf(row, index)
		totals[key] = (totals[key] || 0) + weightOf(row.weight)
		counts[key] = (counts[key] || 0) + 1
	})
	return list.map((row, index) => {
		const key = groupKeyOf(row, index)
		if (totals[key] === 0) return Math.round(100 / counts[key])
		return Math.round((weightOf(row.weight) / totals[key]) * 100)
	})
}
