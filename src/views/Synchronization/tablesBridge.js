/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load-bearing, DOM-free helpers for the `nextcloud-table` sync-editor kind:
 * the table picker (SyncConfigWidget.vue) and the column-mapping helper
 * (TablesColumnMapping.vue) consume these. Extracted so they can be
 * unit-tested in the repo's node-env vitest harness (which mounts no .vue),
 * exactly like src/modals/v2/sourceCredentialRef.js.
 *
 * Backend contract: openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 *   GET /apps/integriq/api/synchronizations/tables-bridge/status
 *   GET /apps/integriq/api/synchronizations/tables-bridge/tables?sourceId=…
 *   GET /apps/integriq/api/synchronizations/tables-bridge/tables/{id}/columns?sourceId=…
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */

/** The `nextcloud-table` kind discriminator value (mirrors the backend). */
export const NEXTCLOUD_TABLE_KIND = 'nextcloud-table'

/**
 * Unwrap the OR/Integriq list envelope (`{results:[...]}`) or a bare
 * array — same soft-fail-safe unwrap the other sync pickers use.
 *
 * @param {*} data The axios response body.
 * @return {Array} The results array (empty on any unexpected shape).
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 */
export function extractResults(data) {
	if (Array.isArray(data?.results)) return data.results
	if (Array.isArray(data)) return data
	return []
}

/**
 * Map raw discovery-endpoint tables into NcSelect options.
 *
 * @param {Array} tables Raw `{id,title,ownerType}` entries.
 * @return {Array<{id:number,label:string}>} NcSelect options (id kept numeric
 *         so the caller can store `tableId` as a number in the config blob).
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 */
export function mapTableOptions(tables) {
	if (!Array.isArray(tables)) return []
	return tables
		.filter((t) => t && t.id !== undefined && t.id !== null)
		.map((t) => ({
			id: Number(t.id),
			label: t.title || String(t.id),
		}))
}

/**
 * Normalise a raw discovery column into the shape the mapping helper renders
 * (title, type, subtype, constraints) — tolerant of missing fields.
 *
 * @param {object} column Raw `{id,title,type,subtype,mandatory,constraints}`.
 * @return {{id:number,title:string,type:string,subtype:?string,mandatory:boolean,constraints:object}}
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function normaliseColumn(column) {
	const c = column || {}
	return {
		id: Number(c.id ?? 0),
		title: String(c.title ?? ''),
		type: String(c.type ?? ''),
		subtype: c.subtype != null ? String(c.subtype) : null,
		mandatory: Boolean(c.mandatory),
		constraints:
			c.constraints && typeof c.constraints === 'object' ? c.constraints : {},
	}
}

/**
 * Map raw discovery columns into normalised column descriptors.
 *
 * @param {Array} columns Raw column entries.
 * @return {Array} Normalised columns.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function mapColumnDescriptors(columns) {
	if (!Array.isArray(columns)) return []
	return columns.map(normaliseColumn)
}

/**
 * Produce a short human hint for a column's allowed values, used under each
 * mapping row so the user sees what a column accepts before saving (matches
 * the coercion rules in tables-bridge REQ-003).
 *
 * @param {object} column A normalised column descriptor.
 * @return {string} A hint like "number (2 decimals)" or "selection: open, paid".
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function columnTypeHint(column) {
	const c = normaliseColumn(column)
	const cons = c.constraints || {}
	switch (c.type) {
		case 'number':
			if (cons.numberDecimals != null) {
				return `number (${cons.numberDecimals} decimals)`
			}
			return 'number'
		case 'selection': {
			const opts = Array.isArray(cons.selectionOptions)
				? cons.selectionOptions
				: []
			if (opts.length > 0) {
				return `selection: ${opts.map(String).join(', ')}`
			}
			return 'selection'
		}
		case 'datetime':
			return c.subtype ? `datetime (${c.subtype})` : 'datetime'
		case 'text':
			if (cons.textMaxLength != null) {
				return `text (max ${cons.textMaxLength})`
			}
			return 'text'
		case 'usergroup':
			return 'usergroup (read-only — not writable)'
		default:
			return c.type || 'unknown'
	}
}

/**
 * Read the current `columnMapping` array from a target config blob, tolerating
 * a missing/malformed value.
 *
 * @param {object} config The `targetConfig` blob.
 * @return {Array<{column:string,value:string}>} The mapping entries.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function readColumnMapping(config) {
	if (!config || typeof config !== 'object') return []
	const mapping = config.columnMapping
	if (!Array.isArray(mapping)) return []
	return mapping.filter(
		(entry) =>
			entry && typeof entry === 'object' && typeof entry.column === 'string',
	)
}

/**
 * Immutably write a `{column: <title>, value: <expr>}` mapping entry into a
 * target config blob, keyed by column TITLE (never numeric id — the backend
 * resolves the title to a columnId at write time, tables-bridge REQ-001).
 * An empty `value` removes the entry so the config stays clean.
 *
 * @param {object} config      The current `targetConfig` blob.
 * @param {string} columnTitle The column title to map.
 * @param {string} value       The mapping output field path / expression.
 * @return {object} A new config blob with the mapping applied.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function upsertColumnMapping(config, columnTitle, value) {
	const base =
		config && typeof config === 'object' && !Array.isArray(config)
			? { ...config }
			: {}
	const existing = readColumnMapping(base).filter(
		(entry) => entry.column !== columnTitle,
	)

	const trimmed = typeof value === 'string' ? value.trim() : ''
	if (trimmed !== '') {
		existing.push({ column: columnTitle, value: trimmed })
	}

	if (existing.length === 0) {
		delete base.columnMapping
	} else {
		base.columnMapping = existing
	}

	return base
}

/**
 * Look up the current mapped value (expression) for a column title.
 *
 * @param {object} config      The `targetConfig` blob.
 * @param {string} columnTitle The column title.
 * @return {string} The mapped value/expression, or '' when unmapped.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
export function mappedValueFor(config, columnTitle) {
	const entry = readColumnMapping(config).find((e) => e.column === columnTitle)
	return entry ? String(entry.value ?? '') : ''
}
