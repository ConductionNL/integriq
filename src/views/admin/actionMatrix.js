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

/**
 * The actions grouped into one section per area, for the screen.
 *
 * Sections are ordered by area name, actions within a section by name. An
 * action the seed gives no area to lands in a last section with an empty
 * area; an action without a label is labelled by its name.
 *
 * @param {Array<string>} actions The declared actions.
 * @param {{areas?: object|null, labels?: object|null}} names Action name to area, and action name to label.
 * @return {Array<{area: string, actions: Array<{name: string, label: string}>}>} The sections.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
 */
export function actionSections(actions, names) {
	const { areas, labels } = names || {}
	const areaOf = areas && typeof areas === 'object' ? areas : {}
	const labelOf = labels && typeof labels === 'object' ? labels : {}
	const sections = new Map()
	for (const name of [...actions].sort()) {
		const area = typeof areaOf[name] === 'string' ? areaOf[name] : ''
		const label = typeof labelOf[name] === 'string' && labelOf[name] !== '' ? labelOf[name] : name
		if (!sections.has(area)) {
			sections.set(area, [])
		}
		sections.get(area).push({ name, label })
	}
	return [...sections.entries()]
		.sort(([one], [two]) => (one === '') - (two === '') || one.localeCompare(two))
		.map(([area, entries]) => ({ area, actions: entries }))
}

/**
 * OpenRegister's reserved group for every signed-in user, used by the object
 * rights of the configuration schemas that carry no authorization block.
 */
export const EVERY_SIGNED_IN_USER = 'authenticated'

/**
 * Whether an action is an object right on a configuration schema.
 *
 * @param {string} name The action name.
 * @return {boolean} True for `object.<schema>.<verb>`.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */
export function isObjectRight(name) {
	return typeof name === 'string' && /^object\.[a-z_]+\.(create|read|update|delete)$/.test(name)
}

/**
 * The group columns of one section: `admin` first, then, for a section that
 * holds object rights, every signed-in user, then the Nextcloud groups.
 *
 * @param {{actions: Array<{name: string}>}} section The section.
 * @param {Array<string>} groups The Nextcloud group ids.
 * @return {Array<string>} The columns.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */
export function sectionColumns(section, groups) {
	const rest = (Array.isArray(groups) ? groups : []).filter((g) => g !== 'admin' && g !== EVERY_SIGNED_IN_USER)
	const objectRights = Array.isArray(section && section.actions) && section.actions.some((action) => isObjectRight(action.name))
	return objectRights ? ['admin', EVERY_SIGNED_IN_USER, ...rest] : ['admin', ...rest]
}

/**
 * Whether granting an action exposes a schema whose records hold secrets:
 * reading sources or consumers.
 *
 * @param {string} name The action name.
 * @return {boolean} True when the screen warns on the row.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */
export function warnsOnGrant(name) {
	return name === 'object.source.read' || name === 'object.consumer.read'
}
