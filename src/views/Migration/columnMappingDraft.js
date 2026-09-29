// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The column mapping an administrator edits on the migration page, and the
 * three shapes it leaves the page in: the check before a save
 * (`POST /api/migration-sources/column-mapping/validate`), the stored
 * `column_mapping` object, and the `config.mapping` a test run reads a file
 * through (`POST /api/migration-sources/preview`).
 *
 * Kept free of Vue so the payloads can be tested against the register schema
 * that stores them (tests/Unit/Migration/ColumnMappingStoredPayloadTest.php
 * validates the fixture this module's output is compared with).
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */

/**
 * The list a response carries, whichever of the two shapes it has.
 *
 * @param {object|Array} data The response body.
 *
 * @return {Array} The rows.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
export function listOf(data) {
	if (Array.isArray(data?.results)) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

/**
 * A blank draft with one empty column row.
 *
 * @return {{id: string|null, name: string, kind: string, targetSchema: string, identifierColumn: string, version: number, rows: Array<{column: string, target: string}>}} The draft.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
export function emptyDraft() {
	return {
		id: null,
		name: '',
		kind: '',
		targetSchema: '',
		identifierColumn: '',
		version: 0,
		rows: [{ column: '', target: '' }],
	}
}

/**
 * Turn a `columns` object into editable rows, in the order it came.
 *
 * @param {object} columns Column name to target field.
 *
 * @return {Array<{column: string, target: string}>} The rows.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
function rowsFrom(columns) {
	const rows = Object.entries(columns || {}).map(([column, target]) => ({
		column,
		target: String(target ?? ''),
	}))
	return rows.length > 0 ? rows : [{ column: '', target: '' }]
}

/**
 * A draft from a stored `column_mapping` object: picking a saved mapping is
 * all a second delivery of the same shape needs.
 *
 * @param {object} stored The stored object.
 *
 * @return {object} The draft, carrying the object's id and version.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice
 */
export function draftFromStored(stored) {
	return {
		id:
			String(stored?.id ?? stored?.uuid ?? stored?.['@self']?.id ?? '')
			|| null,
		name: String(stored?.name ?? ''),
		kind: String(stored?.kind ?? ''),
		targetSchema: String(stored?.targetSchema ?? ''),
		identifierColumn: String(stored?.identifierColumn ?? ''),
		version: Number(stored?.version ?? 1) || 1,
		rows: rowsFrom(stored?.columns),
	}
}

/**
 * A new draft started from a named-incumbent preset. It is not stored yet,
 * so it has no id and its first save is version 1.
 *
 * @param {object} preset A row of `GET /api/migration-sources/column-mapping/presets`.
 *
 * @return {object} The draft.
 *
 * @spec openspec/specs/migration-mapping-presets/spec.md
 */
export function draftFromPreset(preset) {
	const draft = draftFromStored(preset?.mapping || {})
	return { ...draft, id: null, version: 0 }
}

/**
 * The rows as a `columns` object. A row missing its column or its field is
 * left out; the first row for a column wins.
 *
 * @param {Array<{column: string, target: string}>} rows The rows.
 *
 * @return {object} Column name to target field.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
export function columnsFrom(rows) {
	const columns = {}
	for (const row of rows || []) {
		const column = String(row?.column ?? '').trim()
		const target = String(row?.target ?? '').trim()
		if (column === '' || target === '' || column in columns) {
			continue
		}
		columns[column] = target
	}
	return columns
}

/**
 * The fields and required fields of an OpenRegister schema.
 *
 * @param {object|null} schema A row of `/apps/openregister/api/schemas`.
 *
 * @return {{fields: Array<string>, required: Array<string>}} Its field names.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
 */
export function schemaFieldsFrom(schema) {
	const properties =
		schema && typeof schema.properties === 'object' && schema.properties !== null
			? schema.properties
			: {}
	return {
		fields: Object.keys(properties),
		required: Array.isArray(schema?.required) ? schema.required.map(String) : [],
	}
}

/**
 * The mapping in its stored shape, at a given version.
 *
 * @param {object} draft The draft.
 * @param {number} version The version to write.
 *
 * @return {object} The mapping.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
function mappingOf(draft, version) {
	const mapping = {
		name: String(draft?.name ?? '').trim(),
		kind: String(draft?.kind ?? '').trim(),
		columns: columnsFrom(draft?.rows),
		identifierColumn: String(draft?.identifierColumn ?? '').trim(),
		version,
	}
	return mapping
}

/**
 * The body of the check a save runs first.
 *
 * @param {object} draft The draft.
 * @param {object|null} schema The target schema, as OpenRegister lists it.
 *
 * @return {{mapping: object, schemaFields: Array<string>, requiredFields: Array<string>}} The request body.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
 */
export function validateRequest(draft, schema) {
	const { fields, required } = schemaFieldsFrom(schema)
	return {
		mapping: mappingOf(draft, nextVersion(draft)),
		schemaFields: fields,
		requiredFields: required,
	}
}

/**
 * The version the next save writes: 1 for a new mapping, one more than the
 * stored version otherwise.
 *
 * @param {object} draft The draft.
 *
 * @return {number} The version.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
export function nextVersion(draft) {
	if (!draft?.id) {
		return 1
	}
	return (Number(draft.version) || 0) + 1
}

/**
 * The `column_mapping` object a save writes to OpenRegister. An empty
 * identifier column or target schema is left out rather than stored blank.
 *
 * @param {object} draft The draft.
 *
 * @return {object} The object.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
export function storedPayload(draft) {
	const mapping = mappingOf(draft, nextVersion(draft))
	const payload = {
		name: mapping.name,
		kind: mapping.kind,
		targetSchema: String(draft?.targetSchema ?? '').trim(),
		columns: mapping.columns,
		identifierColumn: mapping.identifierColumn,
		version: mapping.version,
	}
	for (const key of ['kind', 'targetSchema', 'identifierColumn']) {
		if (payload[key] === '') {
			delete payload[key]
		}
	}
	return payload
}

/**
 * The body of a test run. A file source reads the delivered file through
 * the draft's mapping; any other source is read as configured.
 *
 * @param {string} sourceId The migration source id (`file`, `redmine`, ...).
 * @param {object} draft The draft, used by the file source.
 * @param {{path?: string, delimiter?: string, source?: string}} options The file path and delimiter, or the configured source to read.
 *
 * @return {{source: string, config: object}} The request body.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
 */
export function previewRequest(sourceId, draft, options = {}) {
	if (sourceId !== 'file') {
		const config = {}
		if (options.source) {
			config.source = String(options.source)
		}
		return { source: sourceId, config }
	}

	const config = {
		path: String(options.path ?? '').trim(),
		mapping: mappingOf(draft, Number(draft?.version) || 1),
	}
	if (options.delimiter && options.delimiter !== ',') {
		config.delimiter = options.delimiter
	}
	return { source: sourceId, config }
}
