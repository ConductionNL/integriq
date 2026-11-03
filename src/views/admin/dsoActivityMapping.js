/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the DSO activities section on the admin settings page
 * (change dso-activity-mapping-table, REQ-DSO-012). They turn a stored
 * `dso_activity_mapping` row or an unmapped activity into an editable draft,
 * check a draft the way the schema and the guard listener will, and turn a
 * draft back into the payload OpenRegister stores.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 */

import { translate as t } from '@nextcloud/l10n'

/** The OpenRegister collection of the table. */
export const MAPPING_URL =
	'/apps/openregister/api/objects/integriq/dso_activity_mapping'

/** The admin endpoint with the unmapped activities. */
export const UNMAPPED_URL = '/apps/integriq/api/admin/dso-activities/unmapped'

/** The STAM imow-id pattern, as the schema declares it. */
export const IMOW_PATTERN =
	/^nl\.imow-(gm|pv|ws|mn|mnre)[0-9]{1,6}\.[A-Za-z]+\.[A-Za-z0-9]{1,32}$/

const text = (value) => (typeof value === 'string' ? value.trim() : '')

/**
 * An empty case type line.
 *
 * @return {{reference: string, title: string, department: string}}
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
 */
export function emptyCaseType() {
	return { reference: '', title: '', department: '' }
}

/**
 * The value when it is a list, otherwise an empty list.
 *
 * @param {unknown} value The value.
 * @return {Array} The list.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A samenloop strategy: `gecombineerd` when stored so, `deelzaken` otherwise.
 *
 * @param {unknown} value The stored strategy.
 * @return {string} The strategy.
 */
function strategyOf(value) {
	return value === 'gecombineerd' ? 'gecombineerd' : 'deelzaken'
}

/**
 * The id of a stored row, from whichever field carries it.
 *
 * @param {object} row The stored row.
 * @return {string|null} The id.
 */
function rowId(row) {
	return row.id || row['@self']?.id || row.uuid || null
}

/**
 * A draft line from a stored case type.
 *
 * @param {object} caseType The stored case type.
 * @return {{reference: string, title: string, department: string}} The line.
 */
function caseTypeDraft(caseType) {
	return {
		reference: text(caseType?.reference),
		title: text(caseType?.title),
		department: text(caseType?.department),
	}
}

/**
 * A draft samenloop rule from a stored one.
 *
 * @param {object} rule The stored rule.
 * @return {{withImowId: string, strategy: string}} The rule.
 */
function ruleDraft(rule) {
	return {
		withImowId: text(rule?.withImowId),
		strategy: strategyOf(rule?.strategy),
	}
}

/**
 * A draft from a stored row, or an empty draft.
 *
 * @param {object} [row] The stored row.
 * @return {object} The draft.
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
 */
export function draftFromRow(row = {}) {
	const caseTypes = listOf(row.caseTypes).map(caseTypeDraft)
	return {
		id: rowId(row),
		imowId: text(row.imowId),
		activityId: text(row.activityId),
		activityName: text(row.activityName),
		caseTypes: caseTypes.length > 0 ? caseTypes : [emptyCaseType()],
		samenloopStrategy: strategyOf(row.samenloopStrategy),
		samenloopRules: listOf(row.samenloopRules).map(ruleDraft),
		isActive: row.isActive !== false,
		note: text(row.note),
	}
}

/**
 * A new draft prefilled from an unmapped activity.
 *
 * @param {object} activity An entry of the unmapped list.
 * @return {object} The draft.
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-an-unmapped-activity-can-be-mapped-from-the-list
 */
export function draftFromUnmapped(activity) {
	return draftFromRow({
		imowId: activity?.imowId,
		activityId: activity?.activityId,
		activityName: activity?.activityName,
	})
}

/**
 * The checks on the ids of a draft, as [failed, problem] pairs.
 *
 * @param {object} draft The draft.
 * @return {Array<[boolean, string]>} The checks.
 */
function idChecks(draft) {
	const imowId = text(draft.imowId)
	const missing = imowId === '' && text(draft.activityId) === ''
	const malformed = imowId !== '' && !IMOW_PATTERN.test(imowId)
	return [
		[missing, t('integriq', 'Fill in the imow-id or the activity id.')],
		[
			malformed,
			t(
				'integriq',
				'The imow-id does not have the STAM form, for example nl.imow-gm0000.activiteit.Bouwen.',
			),
		],
	]
}

/**
 * The check that a draft has an activity name, as a [failed, problem] pair.
 *
 * @param {object} draft The draft.
 * @return {[boolean, string]} The check.
 */
function nameCheck(draft) {
	return [
		text(draft.activityName) === '',
		t('integriq', 'Fill in the activity name.'),
	]
}

/**
 * The check that a case type line of a draft has a reference, as a
 * [failed, problem] pair.
 *
 * @param {object} draft The draft.
 * @return {[boolean, string]} The check.
 */
function referenceCheck(draft) {
	const lacking = !draft.caseTypes.some(
		(caseType) => text(caseType.reference) !== '',
	)
	return [lacking, t('integriq', 'Add at least one case type with a reference.')]
}

/**
 * The check that every samenloop rule of a draft has a valid imow-id, as a
 * [failed, problem] pair.
 *
 * @param {object} draft The draft.
 * @return {[boolean, string]} The check.
 */
function ruleCheck(draft) {
	const bad = draft.samenloopRules.some(
		(rule) => !IMOW_PATTERN.test(text(rule.withImowId)),
	)
	return [
		bad,
		t(
			'integriq',
			'Every samenloop rule needs the imow-id of the other activity.',
		),
	]
}

/**
 * What is wrong with a draft, as sentences for the administrator.
 *
 * @param {object} draft The draft.
 * @return {string[]} The problems; empty when the draft can be saved.
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.1
 */
export function draftProblems(draft) {
	const checks = [
		...idChecks(draft),
		nameCheck(draft),
		referenceCheck(draft),
		ruleCheck(draft),
	]
	return checks.filter(([failed]) => failed).map(([, problem]) => problem)
}

/**
 * The payload OpenRegister stores for a draft: trimmed, empty fields left out.
 *
 * @param {object} draft The draft.
 * @return {object} The row.
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
 */
export function payloadFromDraft(draft) {
	const payload = {
		activityName: text(draft.activityName),
		caseTypes: draft.caseTypes
			.filter((caseType) => text(caseType.reference) !== '')
			.map((caseType) => {
				const entry = { reference: text(caseType.reference) }
				if (text(caseType.title) !== '') entry.title = text(caseType.title)
				if (text(caseType.department) !== '')
					entry.department = text(caseType.department)
				return entry
			}),
		samenloopStrategy: draft.samenloopStrategy,
		samenloopRules: draft.samenloopRules.map((rule) => ({
			withImowId: text(rule.withImowId),
			strategy: rule.strategy,
		})),
		isActive: draft.isActive !== false,
	}
	for (const key of ['imowId', 'activityId', 'note']) {
		if (text(draft[key]) !== '') payload[key] = text(draft[key])
	}
	return payload
}

/**
 * The message of a refused save: the guard listener's or OpenRegister's own.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The text when the server gave none.
 * @return {string}
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
 */
export function refusalMessage(error, fallback) {
	const data = error?.response?.data
	return data?.message || data?.error || fallback
}
