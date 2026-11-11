/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure readers of a failed approve or reject call on the approval detail page
 * (ApprovalDetail.vue).
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

/**
 * The request that replaced this one, when the server refused the approval
 * because the source changed after the preview (409, REQ-INAV-004).
 *
 * @param {object} err The axios error.
 * @return {string|null} The replacing request's id, or null.
 */
export function supersededBy(err) {
	const replacedBy = err?.response?.data?._approval?.supersededBy
	if (err?.response?.status === 409 && replacedBy) {
		return replacedBy
	}
	return null
}

/**
 * The `: detail` suffix of a failure toast: the server's error, else the
 * error message, else nothing.
 *
 * @param {object} err The axios error.
 * @return {string} The suffix, empty when there is no detail.
 */
export function failureSuffix(err) {
	const detail = err?.response?.data?.error || err?.message || ''
	return detail ? `: ${detail}` : ''
}
