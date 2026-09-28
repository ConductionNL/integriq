// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Find the CnLogsPage a slot component is rendered in and re-fetch it, so a
// replay shows up in the list without a reload. CnLogsPage exposes
// `refresh()` for exactly this; the row-actions slot sits a few components
// below it (inside the data table), so the parent chain is walked.
//
// @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002

/**
 * Refresh the nearest log page above a component, if there is one.
 *
 * @param {object} vm the component instance
 * @return {boolean} true when a page was refreshed
 */
export function refreshLogPage(vm) {
	let node = vm?.$parent
	while (node) {
		if (
			node.$options?.name === 'CnLogsPage'
			&& typeof node.refresh === 'function'
		) {
			node.refresh()
			return true
		}
		node = node.$parent
	}
	return false
}
