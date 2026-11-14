// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure helpers for the targets of an endpoint with several upstreams
// (gateway-upstream-routing): the rows the endpoint editor edits, and the
// JsonLogic rule text it shows and reads back.

/**
 * A weight as the schema stores it: a whole number of at least zero, 1 when unset.
 *
 * @param {unknown} value The typed or stored weight.
 * @return {number} The weight.
 */
export function weightOf(value) {
	if (value === '' || value === null || value === undefined) {
		return 1
	}
	const number = Math.floor(Number(value))
	return Number.isFinite(number) && number >= 0 ? number : 1
}

/**
 * The targets as editable rows: objects with a source, a whole weight of at
 * least zero, and a group and rule only when set.
 *
 * @param {unknown} targets The stored `targets` value.
 * @return {Array<{source: string, weight: number, group?: string, when?: object}>} The rows.
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export function normaliseTargets(targets) {
	if (!Array.isArray(targets)) {
		return []
	}
	return targets
		.filter((target) => target && typeof target === 'object')
		.map((target) => {
			const row = { source: String(target.source || ''), weight: weightOf(target.weight) }
			if (target.group) {
				row.group = String(target.group)
			}
			if (target.when && typeof target.when === 'object' && Object.keys(target.when).length > 0) {
				row.when = target.when
			}
			return row
		})
}

/**
 * The rows with one changed: `source`, `weight` and `group` are set (an empty
 * group is removed); `when` is set, or removed when null.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} index The row to change.
 * @param {object} patch The fields to change.
 * @return {Array<object>} New rows.
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export function updateTarget(rows, index, patch) {
	return rows.map((row, at) => {
		if (at !== index) {
			return row
		}
		const next = { ...row }
		if ('source' in patch) {
			next.source = String(patch.source || '')
		}
		if ('weight' in patch) {
			next.weight = weightOf(patch.weight)
		}
		if ('group' in patch) {
			const group = String(patch.group || '').trim()
			if (group) {
				next.group = group
			} else {
				delete next.group
			}
		}
		if ('when' in patch) {
			if (patch.when) {
				next.when = patch.when
			} else {
				delete next.when
			}
		}
		return next
	})
}

/**
 * Read the rule text a person typed: empty is no rule, otherwise a JSON object.
 *
 * @param {string} text The typed text.
 * @return {{ok: boolean, value?: (object|null)}} The rule, or `ok: false` when it is not readable.
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export function parseRule(text) {
	const trimmed = String(text || '').trim()
	if (trimmed === '') {
		return { ok: true, value: null }
	}
	try {
		const value = JSON.parse(trimmed)
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			return { ok: true, value: Object.keys(value).length > 0 ? value : null }
		}
	} catch {
		// Not JSON: the editor shows the error and keeps the stored rule.
	}
	return { ok: false }
}

/**
 * The text the editor shows for a stored rule.
 *
 * @param {object|undefined} rule The rule.
 * @return {string} Its JSON, or '' without one.
 */
export function ruleText(rule) {
	return rule && typeof rule === 'object' ? JSON.stringify(rule) : ''
}

/**
 * Each target's share of its group's calls, in whole percent; '' for a target
 * whose group has no weight at all. Targets without a rule are the default
 * group; targets with a rule share their named group, or are a group of their own.
 *
 * @param {Array<{weight: number, group?: string, when?: object}>} rows The rows.
 * @return {Array<string>} One share per row, e.g. '95%'.
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export function shares(rows) {
	// The same grouping as UpstreamSelector::targetsOf(): without a rule the
	// default group; with one its named group, else a group of its own.
	const groupOf = (row, index) => {
		if (!row.when) {
			return 'default'
		}
		return row.group ? 'g:' + row.group : 'r:' + index
	}
	const totals = {}
	rows.forEach((row, index) => {
		const group = groupOf(row, index)
		totals[group] = (totals[group] || 0) + row.weight
	})
	return rows.map((row, index) => {
		const total = totals[groupOf(row, index)]
		return total > 0 ? Math.round((row.weight / total) * 100) + '%' : ''
	})
}
