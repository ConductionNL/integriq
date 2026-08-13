// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shared helpers for the bespoke per-action-type form SFCs under
 * src/views/Rule/actionForms. Each form receives a `value` prop (the
 * slice of `configuration[<type>]`) and emits `update:value` with the
 * next slice; RuleActionConfig wraps that back into the full
 * configuration blob.
 *
 * Picker helpers (`fetchOpenRegisterCollection`) follow the pattern
 * established by RuleActionConfig.vue's `fetchSynchronizations` — load
 * once on demand, no Vuex, no global cache.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch a paginated list of OpenRegister objects for picker
 * components. Tolerates both `{ results: [] }` and bare arrays.
 *
 * @param {string} schema  OpenRegister schema slug (e.g. 'synchronization').
 * @param {string} register Register slug (defaults to 'openconnector').
 * @param {number} limit   Max rows to load (default 500).
 * @return {Promise<Array<{ id: string, label: string, raw: object }>>}
 *   Resolved options ready for NcSelect.
 */
export async function fetchOpenRegisterCollection(
	schema,
	register = 'openconnector',
	limit = 500,
) {
	try {
		const response = await axios.get(
			generateUrl(`/apps/openregister/api/objects/${register}/${schema}`),
			// `_limit`, not `limit`. OpenRegister's object API treats every
			// UNPREFIXED query parameter as a PROPERTY FILTER, so `limit=500`
			// asked for objects whose `limit` property equals 500 and got
			// `total: 0` back under HTTP 200 — an empty picker that looks
			// exactly like an empty register. Control params take the
			// underscore. See FlowDetailPage.fetchPickerOptions().
			{ params: { _limit: limit } },
		)
		const data = response.data
		const list = Array.isArray(data?.results)
			? data.results
			: Array.isArray(data)
				? data
				: []
		return list.map((row) => ({
			id: String(row.id || row.uuid),
			label: row.name || row.title || row.id || '(unnamed)',
			raw: row,
		}))
	} catch (err) {
		// eslint-disable-next-line no-console
		console.warn(`[actionForms] fetch ${register}/${schema} failed`, err)
		return []
	}
}

/**
 * Mixin-like default behaviour: every action form uses the same
 * `value` ↔ `update:value` contract, so we centralise the props
 * declaration and the patch helper here. Components spread this into
 * their `props` / `methods` to stay terse.
 */
export const valueProp = {
	value: { type: Object, default: () => ({}) },
}

/**
 * Patch-helper factory — returns a closure that, when called from a
 * Vue component as `this.patch('field', newValue)`, emits an updated
 * copy of the form's `value` with that key/value pair applied.
 *
 * The returned function takes:
 *   - key  {string} Field name within the action's config slot.
 *   - next {*}      New value to write at that key.
 *
 * @return {Function} The patch closure to be installed on a component.
 */
export function patchMethod() {
	return function patch(key, next) {
		const base = this.value && typeof this.value === 'object' ? this.value : {}
		const merged = { ...base, [key]: next }
		this.$emit('update:value', merged)
	}
}
