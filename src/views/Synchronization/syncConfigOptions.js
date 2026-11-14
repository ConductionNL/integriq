// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Option lists and config helpers of SyncConfigWidget: how it reads the
// OpenRegister and bridge answers, labels sources, registers and schemas,
// copies the config blob, and opens the source file picker.
//
// @spec openspec/specs/sync-editor-ui/spec.md

import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { mapFormOptions } from './formsBridge.js'
import { extractResults, mapTableOptions } from './tablesBridge.js'

/** The widget method that loads the option lists each type needs. */
export const TYPE_LOADERS = {
	api: 'loadApiLists',
	'register/schema': 'loadRegisterLists',
	'nextcloud-table': 'loadTableLists',
	'nextcloud-form': 'loadFormLists',
	sftp: 'loadSourcesOnce',
	ftps: 'loadSourcesOnce',
}

/**
 * The source, register and schema lookups, empty.
 *
 * @return {object} The lists, their loading flags and the picked register.
 */
export function lookupState() {
	return {
		sourceOptions: [],
		sourcesLoading: false,
		registerOptions: [],
		registersLoading: false,
		selectedRegisterRecord: null,
		/**
		 * Schema id to display label, from `/apps/openregister/api/schemas`.
		 * A register's own `schemas` array carries bare ids, so without
		 * this the picker can only show the id back to the user.
		 */
		schemaLabelsById: {},
	}
}

/**
 * The Tables and Forms bridge lookups, empty.
 *
 * @return {object} The lists, their loading flags and errors.
 */
export function bridgeState() {
	return {
		tableOptions: [],
		tablesLoading: false,
		tablesError: '',
		formOptions: [],
		formsLoading: false,
		formsError: '',
	}
}

/**
 * The rows of a list answer: its `results`, the answer itself when it is a
 * list, or nothing.
 *
 * @param {unknown} data The response body.
 * @return {Array} The rows.
 */
export function listOf(data) {
	if (Array.isArray(data?.results)) return data.results
	return Array.isArray(data) ? data : []
}

/**
 * A source as a select option.
 *
 * @param {object} row One source object.
 * @return {{id: string, label: string}} The option.
 */
export function sourceOption(row) {
	return {
		id: String(row.id || row.uuid),
		label: row.name || row.title || row.id,
	}
}

/**
 * A register as a select option, carrying its schema ids.
 *
 * @param {object} reg One register.
 * @return {{id: string, label: string, schemas: Array}} The option.
 */
export function registerOption(reg) {
	return {
		id: String(reg.id),
		label: reg.title || reg.name || reg.slug || String(reg.id),
		schemas: Array.isArray(reg.schemas) ? reg.schemas : [],
	}
}

/**
 * Schema id to label for every schema that has an id.
 *
 * @param {Array} list The schemas.
 * @return {object} The labels by schema id.
 */
export function schemaLabelMap(list) {
	const labels = {}
	for (const schema of list) {
		const id = schema?.id ?? schema?.uuid
		if (id !== undefined && id !== null) {
			labels[String(id)] = schemaName(schema, id)
		}
	}
	return labels
}

/**
 * The display name of a schema record.
 *
 * @param {object} schema The schema.
 * @param {string|number} id Its id, the last resort.
 * @return {string} The name.
 */
function schemaName(schema, id) {
	return schema.title || schema.name || schema.slug || String(id)
}

/**
 * One entry of a register's `schemas` as a picker option. Entries are bare
 * ids, or expanded records in case the registers endpoint inlines them. The
 * id itself is the label only when the schema list has no name for it:
 * better a visible id than an empty option.
 *
 * @param {object|string|number|null} schema The entry.
 * @param {object} labels Schema id to label.
 * @return {{id: string, label: string}} The option.
 */
export function schemaOption(schema, labels) {
	const isRecord = isObject(schema)
	const id = isRecord ? recordId(schema) : String(schema)
	const inlineLabel = isRecord ? inlineSchemaLabel(schema) : ''
	return { id, label: inlineLabel || labels[id] || id }
}

/**
 * Whether a register schema entry is an expanded record.
 *
 * @param {unknown} schema The entry.
 * @return {boolean} True for an object.
 */
function isObject(schema) {
	return schema !== null && typeof schema === 'object'
}

/**
 * The id of an expanded schema record, its slug when it has none.
 *
 * @param {object} schema The record.
 * @return {string} The id, or ''.
 */
function recordId(schema) {
	return String(schema.id ?? schema.slug ?? '')
}

/**
 * The label an expanded schema record carries itself.
 *
 * @param {object} schema The record.
 * @return {string} The label, or ''.
 */
function inlineSchemaLabel(schema) {
	return schema.title || schema.name || schema.slug || ''
}

/**
 * A copy of the config blob to write into; anything but a plain object
 * starts empty.
 *
 * @param {unknown} config The config prop.
 * @return {object} The copy.
 */
export function configCopy(config) {
	return config && typeof config === 'object' && !Array.isArray(config)
		? { ...config }
		: {}
}

/**
 * The rows a Tables or Forms bridge lists for a source.
 *
 * @param {string} path The bridge path under the synchronizations API.
 * @param {string} sourceId The source to ask.
 * @return {Promise<Array>} The rows.
 */
async function fetchBridgeList(path, sourceId) {
	const response = await axios.get(
		generateUrl(`/apps/integriq/api/synchronizations/${path}`),
		{ params: { sourceId } },
	)
	return extractResults(response.data)
}

/**
 * The tables of a source as select options.
 *
 * @param {string} sourceId The source.
 * @return {Promise<Array>} The options.
 */
export async function tableOptionsFor(sourceId) {
	return mapTableOptions(await fetchBridgeList('tables-bridge/tables', sourceId))
}

/**
 * The forms of a source as select options.
 *
 * @param {string} sourceId The source.
 * @return {Promise<Array>} The options.
 */
export async function formOptionsFor(sourceId) {
	return mapFormOptions(await fetchBridgeList('forms-bridge/forms', sourceId))
}

/**
 * Why a bridge list could not be loaded.
 *
 * @param {object} err The failed request.
 * @param {string} fallback The translated text when the server gave none.
 * @return {string} The text to show.
 */
export function bridgeError(err, fallback) {
	return err?.response?.data?.error || fallback
}

/**
 * The single-file picker for a sync source file.
 *
 * @return {object} The picker.
 */
export function sourceFilePicker() {
	return getFilePickerBuilder(t('integriq', 'Pick a sync source file'))
		.setMultiSelect(false)
		.setMimeTypeFilter([])
		.setModal(true)
		.setType(FilePickerType.Choose)
		.allowDirectories(false)
		.build()
}

/**
 * The error to show after the file picker closed without a path. A cancel
 * rejects the picker in @nextcloud/dialogs 3.x, so it and any error without
 * a message are a soft dismissal that shows nothing.
 *
 * @param {unknown} err The rejection.
 * @return {string} The error to show, or ''.
 */
export function pickerFailure(err) {
	if (err && err.message && !/cancel/i.test(err.message)) {
		return err.message
	}
	return ''
}
