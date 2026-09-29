/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app ids a mapping lets run it by event (mapping-woo-index-field-mapping).
 *
 * @spec openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
 */

/**
 * Normalise a callableBy list: trimmed, lower-case app ids, no blanks, no repeats.
 *
 * @param {unknown} value The stored or edited value.
 * @return {string[]}
 * @spec openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
 */
export function normaliseCallableBy(value) {
	const list = Array.isArray(value) ? value : []
	const ids = list
		.map((entry) =>
			typeof entry === 'string' ? entry : (entry?.label ?? entry?.id ?? ''),
		)
		.map((entry) => String(entry).trim().toLowerCase())
		.filter((entry) => entry !== '')
	return [...new Set(ids)]
}
