/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers of the ADR-023 action-authorization matrix editor
 * (ActionAuthMatrix.vue): reading a server matrix into an editable map and
 * building the matrix the editor saves.
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 */

/**
 * A plain editable map with one copied allow-list per declared action.
 *
 * An action the server sent no list for gets an empty one; a key that is not a
 * declared action is dropped.
 *
 * @param {Array<string>} actions The declared actions.
 * @param {object|null|undefined} source The server's matrix; anything that is not an object reads as empty.
 * @return {Object<string, Array<string>>} The editable matrix.
 */
export function editableMatrix(actions, source) {
	const matrix = source && typeof source === 'object' ? source : {}
	const next = {}
	for (const action of actions) {
		next[action] = Array.isArray(matrix[action]) ? [...matrix[action]] : []
	}
	return next
}

/**
 * The matrix to save: `admin` first, then every other ticked group.
 *
 * Persisting `admin` with the explicit groups keeps the stored posture
 * admin-inclusive and human-readable.
 *
 * @param {Array<string>} actions The declared actions.
 * @param {Object<string, Array<string>>} matrix The edited matrix.
 * @return {Object<string, Array<string>>} The payload matrix.
 */
export function savePayload(actions, matrix) {
	const payload = {}
	for (const action of actions) {
		payload[action] = ['admin', ...(matrix[action] || []).filter((g) => g !== 'admin')]
	}
	return payload
}
