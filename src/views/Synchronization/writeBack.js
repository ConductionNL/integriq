/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Helpers for a synchronization's `writeBack` (REQ-CSD-001): the fields a push
 * from a register/schema source sets on the object that started it, once the
 * target accepted it (`onSuccess`) or refused it (`onFailure`). The editor
 * works with rows so a half-typed field name survives; the record stores an
 * object per side.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */

/** The two moments a push writes back, in the order the editor shows them. */
export const WRITE_BACK_SIDES = ['onSuccess', 'onFailure']

/** The placeholders OutcomeWriteBack fills from the attempt's outcome. */
export const WRITE_BACK_PLACEHOLDERS = [
	'{{ response.* }}',
	'{{ status }}',
	'{{ targetId }}',
	'{{ error.message }}',
]

/**
 * Turn a stored write-back into editor rows, one per field.
 *
 * @param {*} writeBack the record's `writeBack`, possibly missing or malformed
 * @return {{onSuccess: Array<{field: string, value: *}>, onFailure: Array<{field: string, value: *}>}} the rows per side
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */
export function writeBackRows(writeBack) {
	const rows = { onSuccess: [], onFailure: [] }
	for (const side of WRITE_BACK_SIDES) {
		const fields = writeBack?.[side]
		if (fields === null || typeof fields !== 'object' || Array.isArray(fields)) {
			continue
		}
		rows[side] = Object.entries(fields).map(([field, value]) => ({
			field,
			value,
		}))
	}
	return rows
}

/**
 * Turn editor rows back into the stored shape. A row without a field name is
 * still being typed and is left out; a side without rows is left out; nothing
 * at all gives null.
 *
 * @param {{onSuccess: Array<{field: string, value: *}>, onFailure: Array<{field: string, value: *}>}} rows the rows per side
 * @return {?object} the write-back to store, or null when it declares nothing
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */
export function writeBackFromRows(rows) {
	const writeBack = {}
	for (const side of WRITE_BACK_SIDES) {
		const fields = {}
		for (const row of rows?.[side] || []) {
			const field = String(row.field ?? '').trim()
			if (field !== '') {
				fields[field] = row.value ?? ''
			}
		}
		if (Object.keys(fields).length > 0) {
			writeBack[side] = fields
		}
	}
	return Object.keys(writeBack).length > 0 ? writeBack : null
}
