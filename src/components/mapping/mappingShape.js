/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Shape normalisers for the three rule collections on a Mapping object.
 *
 * A mapping's `mapping`, `cast` and `unset` fields reach the frontend in more
 * than one shape depending on where the record came from: OpenRegister stores
 * them as JSON, the legacy tables stored them as strings, and an imported
 * configuration can carry either. Both the editor modal and the detail page
 * need the same normalisation, so it lives here rather than being copied.
 */

/**
 * Normalise a keyed rule collection (`mapping` or `cast`) to a plain object.
 *
 * @param {*} raw The raw value from the Mapping record.
 * @return {object} Object with `{ property: value }` shape; empty when unusable.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
export function asObjectMap(raw) {
	if (!raw) return {}
	if (typeof raw === 'string') {
		try {
			const parsed = JSON.parse(raw)
			return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
				? parsed
				: {}
		} catch (_e) {
			return {}
		}
	}
	if (typeof raw === 'object' && !Array.isArray(raw)) return raw
	return {}
}

/**
 * Normalise the `unset` field to a plain string array.
 *
 * The legacy storage shape was a comma-separated string; the current
 * OpenRegister shape is a JSON array. Either is accepted.
 *
 * @param {*} raw The raw value from the Mapping record.
 * @return {Array<string>} List of property names to unset.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
export function asUnsetList(raw) {
	if (!raw) return []
	if (Array.isArray(raw)) {
		return raw.filter((entry) => typeof entry === 'string' && entry.length > 0)
	}
	if (typeof raw === 'string') {
		return raw.split(/ *, */g).filter(Boolean)
	}
	return []
}
