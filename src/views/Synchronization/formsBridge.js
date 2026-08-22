/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load-bearing, DOM-free helpers for the `nextcloud-form` sync-editor kind:
 * the form picker (SyncConfigWidget.vue) and the field-mapping helper
 * (FormsFieldMapping.vue) consume these. Extracted so they can be
 * unit-tested in the repo's node-env vitest harness (which mounts no .vue),
 * mirroring src/views/Synchronization/tablesBridge.js.
 *
 * Backend contract: openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 *   GET /apps/integriq/api/synchronizations/forms-bridge/status
 *   GET /apps/integriq/api/synchronizations/forms-bridge/forms?sourceId=…
 *   GET /apps/integriq/api/synchronizations/forms-bridge/forms/{formId}/questions?sourceId=…
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */

/** The `nextcloud-form` kind discriminator value (mirrors the backend). */
export const NEXTCLOUD_FORM_KIND = 'nextcloud-form'

/** Question types whose answer resolves to an array (nextcloud-forms-connector REQ-003). */
export const MULTI_VALUE_QUESTION_TYPES = ['multiple', 'multiple_unique']

/**
 * Unwrap the OR/Integriq list envelope (`{results:[...]}`) or a bare
 * array — same soft-fail-safe unwrap the other sync pickers use.
 *
 * @param {*} data The axios response body.
 * @return {Array} The results array (empty on any unexpected shape).
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
 */
export function extractResults(data) {
	if (Array.isArray(data?.results)) return data.results
	if (Array.isArray(data)) return data
	return []
}

/**
 * Map raw discovery-endpoint forms into NcSelect options.
 *
 * @param {Array} forms Raw `{id,title}` entries.
 * @return {Array<{id:number,label:string}>} NcSelect options (id kept numeric
 *         so the caller can store `formId` as a number in the config blob).
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
 */
export function mapFormOptions(forms) {
	if (!Array.isArray(forms)) return []
	return forms
		.filter((f) => f && f.id !== undefined && f.id !== null)
		.map((f) => ({
			id: Number(f.id),
			label: f.title || String(f.id),
		}))
}

/**
 * Normalise a raw discovery question into the shape the field-mapping helper
 * renders (id, text, name, type) — tolerant of missing fields.
 *
 * @param {object} question Raw `{id,text,name,type}`.
 * @return {{id:number,text:string,name:string,type:string}}
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */
export function normaliseQuestion(question) {
	const q = question || {}
	return {
		id: Number(q.id ?? 0),
		text: String(q.text ?? ''),
		name: String(q.name ?? ''),
		type: String(q.type ?? ''),
	}
}

/**
 * Map raw discovery questions into normalised question descriptors.
 *
 * @param {Array} questions Raw question entries.
 * @return {Array} Normalised questions.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */
export function mapQuestionDescriptors(questions) {
	if (!Array.isArray(questions)) return []
	return questions.map(normaliseQuestion)
}

/**
 * Whether a question's type resolves to an array-valued answer
 * (nextcloud-forms-connector REQ-003).
 *
 * @param {object} question A normalised question descriptor.
 * @return {boolean}
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */
export function isArrayValuedQuestion(question) {
	const q = normaliseQuestion(question)
	return MULTI_VALUE_QUESTION_TYPES.includes(q.type)
}

/**
 * Compute the set of question TEXTS that are ambiguous (shared by two or
 * more questions in the same form) — nextcloud-forms-connector REQ-003's
 * "never guess" precedent, surfaced in the editor before the user hits the
 * runtime config error (sync-editor-ui REQ-SYNCUI-009).
 *
 * @param {Array} questions Normalised question descriptors.
 * @return {Set<string>} The set of ambiguous texts (empty strings excluded).
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */
export function ambiguousQuestionTexts(questions) {
	const counts = new Map()
	for (const question of Array.isArray(questions) ? questions : []) {
		const text = normaliseQuestion(question).text
		if (text === '') continue
		counts.set(text, (counts.get(text) || 0) + 1)
	}
	const ambiguous = new Set()
	for (const [text, count] of counts.entries()) {
		if (count > 1) ambiguous.add(text)
	}
	return ambiguous
}
