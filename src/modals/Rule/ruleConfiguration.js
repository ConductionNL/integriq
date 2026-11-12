/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule editor's configuration in both directions: the form state a stored
 * configuration opens as (every section, with its defaults), and the save
 * payload section for the selected rule type.
 */

import { buildAuthenticationConfiguration } from './buildAuthenticationConfiguration.js'

/**
 * The form state of each configuration section, from the stored configuration.
 * Every section is present, whatever the rule type, so switching type in the
 * editor never meets a missing section.
 */
const EDITABLE_SECTIONS = {
	error: (c) => ({
		code: c.error?.code ?? 500,
		name: c.error?.name ?? 'Something went wrong',
		message: c.error?.message ?? 'We encountered an unexpected problem',
		includeJsonLogicResult: c.error?.includeJsonLogicResult ?? false,
	}),
	synchronization: (c) => ({
		synchronization: c.synchronization?.synchronization ?? null,
		retainResponse: c.synchronization?.retainResponse ?? false,
	}),
	authentication: (c) => ({
		type: c.authentication?.type ?? {
			label: 'Basic Authentication',
			value: 'basic',
		},
		users: c.authentication?.users ?? [],
		groups: c.authentication?.groups ?? [],
		keys: c.authentication?.keys ?? [],
	}),
	download: (c) => ({
		fileIdPosition: c.download?.fileIdPosition ?? 0,
	}),
	upload: (c) => ({
		path: c.upload?.path ?? '',
		allowedTypes: c.upload?.allowedTypes ?? '',
		maxSize: c.upload?.maxSize ?? 10,
	}),
	locking: (c) => ({
		action: c.locking?.action ?? 'lock',
		timeout: c.locking?.timeout ?? 30,
	}),
	fetch_file: (c) => editableFetchFile(c.fetch_file),
	write_file: (c) => ({
		filePath: c.write_file?.filePath ?? '',
		fileNamePath: c.write_file?.fileNamePath ?? '',
		tags: c.write_file?.tags ?? [],
		autoShare: c.write_file?.autoShare ?? false,
	}),
	fileparts_create: (c) => ({
		sizeLocation: c.fileparts_create?.sizeLocation ?? '',
		schemaId: c.fileparts_create?.schemaId ?? '',
		filenameLocation: c.fileparts_create?.filenameLocation ?? '',
		filePartLocation: c.fileparts_create?.filePartLocation ?? '',
		mappingId: c.fileparts_create?.mappingId ?? '',
	}),
	filepart_upload: (c) => ({
		mappingId: c.filepart_upload?.mappingId ?? '',
	}),
	save_object: (c) => ({
		register: c.save_object?.register ?? '',
		schema: c.save_object?.schema ?? '',
		mapping: c.save_object?.mapping ?? '',
	}),
}

/**
 * The fetch_file section's form state; its source configuration is edited as
 * JSON text.
 *
 * @param {object|undefined} f The stored fetch_file section.
 * @return {object} The form state.
 */
function editableFetchFile(f) {
	return {
		source: f?.source ?? '',
		filePath: f?.filePath ?? '',
		subObjectFilepath: f?.subObjectFilepath ?? '',
		objectIdPath: f?.objectIdPath ?? '',
		method: f?.method ?? '',
		tags: f?.tags ?? [],
		sourceConfiguration: f?.sourceConfiguration
			? JSON.stringify(f.sourceConfiguration, null, 2)
			: '[]',
		autoShare: f?.autoShare ?? false,
		endpoint: f?.endpoint ?? '',
		contentPath: f?.contentPath ?? '',
		originIdPath: f?.originIdPath ?? '',
		filenamePath: f?.filenamePath ?? '',
		fileExtension: f?.fileExtension ?? '',
	}
}

/**
 * The configuration a stored rule opens with in the editor: the stored keys,
 * with every section replaced by its form state.
 *
 * @param {object} originalConfig The stored configuration.
 * @return {object} The editable configuration.
 */
export function editableRuleConfiguration(originalConfig) {
	const configuration = { ...originalConfig }
	for (const [section, build] of Object.entries(EDITABLE_SECTIONS)) {
		configuration[section] = build(originalConfig)
	}
	return configuration
}

/**
 * Give extend_external_input and extend_input the shape the editor rows
 * need, with one empty row to type into. A stored extend_input
 * (`properties` + `extends`) becomes one row per property. Changes
 * `configuration` in place.
 *
 * @param {object} configuration The editable configuration.
 */
export function initialiseExtendInputs(configuration) {
	if (!configuration.extend_external_input) {
		configuration.extend_external_input = {
			validate: true,
			properties: [{ property: '', schema: '' }],
		}
	} else if (
		!configuration.extend_external_input.properties
		|| configuration.extend_external_input.properties.length === 0
	) {
		configuration.extend_external_input.properties = [
			{ property: '', schema: '' },
		]
	}

	if (configuration?.extend_input?.properties) {
		configuration.extend_input = extendInputRows(configuration.extend_input)
	} else if (!configuration.extend_input) {
		configuration.extend_input = {
			items: [{ property: '', extends: [] }],
		}
	} else if (
		!configuration.extend_input.items
		|| configuration.extend_input.items.length === 0
	) {
		configuration.extend_input.items = [{ property: '', extends: [] }]
	}
}

/**
 * A stored extend_input as editor rows, ending in an empty row.
 *
 * @param {object} stored `{ properties, extends }`.
 * @return {object} `{ items }`.
 */
function extendInputRows(stored) {
	const props = stored.properties || []
	const ext = stored.extends || {}
	const items = props.map((p) => ({ property: p, extends: ext[p] || [] }))
	if (items.length === 0 || items[items.length - 1].property) {
		items.push({ property: '', extends: [] })
	}
	return { items }
}

/**
 * The rows of a list that name a property.
 *
 * @param {Array<object>|undefined} items The rows.
 * @return {Array<object>} The named rows.
 */
function namedItems(items) {
	return (items ?? []).filter((i) => i.property && i.property.trim())
}

/**
 * The save payload section for each rule type, read from the editor
 * component (`vm`).
 */
const SAVE_SECTIONS = {
	error: (vm) => {
		const e = vm.ruleItem.configuration.error
		return {
			code: e.code,
			name: e.name,
			message: e.message,
			includeJsonLogicResult: e.includeJsonLogicResult,
		}
	},
	mapping: (vm) => vm.mappingOptions.value?.value,
	synchronization: (vm) => ({
		synchronization: vm.syncOptions.value?.value,
		retainResponse: vm.ruleItem.configuration.synchronization.retainResponse,
	}),
	javascript: (vm) => vm.ruleItem.configuration.javascript,
	// SECURITY (ocon#147 / openregister#463): the inbound apiKey => userId map is
	// write-only, so this editor never sees the stored keys and `apiKeys` seeds empty.
	// buildAuthenticationConfiguration() OMITS `keys` when no complete new key was
	// entered, so openregister#463 preserves the stored keys instead of the PUT-null-fill
	// destroying them. Only a non-empty `keys` REPLACES the stored keys. See that module.
	authentication: (vm) => buildAuthenticationConfiguration({
		type: vm.authenticationTypeOptions.value.value,
		users: vm.ruleItem.configuration.authentication.users.map((user) => user.id),
		groups: vm.ruleItem.configuration.authentication.groups.map((group) => group.value),
		apiKeys: vm.apiKeys,
	}),
	download: (vm) => ({
		fileIdPosition: vm.ruleItem.configuration.download.fileIdPosition,
	}),
	upload: (vm) => ({
		path: vm.ruleItem.configuration.upload.path,
		allowedTypes: vm.ruleItem.configuration.upload.allowedTypes,
		maxSize: vm.ruleItem.configuration.upload.maxSize,
	}),
	locking: (vm) => ({
		action:
			vm.ruleItem.configuration.locking.action.value
			|| vm.ruleItem.configuration.locking.action,
		timeout: vm.ruleItem.configuration.locking.timeout,
	}),
	extend_input: (vm) => {
		const items = namedItems(vm.ruleItem.configuration.extend_input?.items)
		return {
			properties: items.map((i) => i.property),
			extends: items.reduce((acc, i) => {
				acc[i.property] = i.extends || []
				return acc
			}, {}),
		}
	},
	extend_external_input: (vm) => ({
		validate: vm.ruleItem.configuration.extend_external_input?.validate ?? true,
		properties: (vm.ruleItem.configuration.extend_external_input?.properties ?? [])
			.filter((p) => p.property && p.property.trim() && p.schema && p.schema.trim())
			.map((p) => ({ property: p.property, schema: p.schema })),
	}),
	fetch_file: (vm) => saveFetchFile(vm),
	write_file: (vm) => {
		const w = vm.ruleItem.configuration.write_file
		return {
			filePath: w.filePath,
			fileNamePath: w.fileNamePath,
			tags: w.tags,
			autoShare: w.autoShare,
		}
	},
	fileparts_create: (vm) => {
		const f = vm.ruleItem.configuration.fileparts_create
		return {
			sizeLocation: f.sizeLocation,
			schemaId: vm.schemaOptions.value?.id,
			filenameLocation: f.filenameLocation,
			filePartLocation: f.filePartLocation,
			mappingId: vm.filepartsCreateMappingOptions.value?.value,
		}
	},
	filepart_upload: (vm) => ({
		mappingId: vm.filepartUploadMappingOptions.value?.value,
	}),
	save_object: (vm) => ({
		register: vm.ruleItem.configuration.save_object.register,
		schema: vm.ruleItem.configuration.save_object.schema,
		mapping: vm.mappingOptions.value?.value,
	}),
}

/**
 * The fetch_file save section; the source configuration text is parsed back
 * to JSON.
 *
 * @param {object} vm The editor component.
 * @return {object} The section.
 */
function saveFetchFile(vm) {
	const f = vm.ruleItem.configuration.fetch_file
	return {
		source: vm.sourceOptions.sourceValue?.id,
		filePath: f.filePath,
		subObjectFilepath: f.subObjectFilepath,
		objectIdPath: f.objectIdPath,
		method: vm.methodOptions.value?.label,
		tags: f.tags,
		sourceConfiguration: f.sourceConfiguration
			? JSON.parse(f.sourceConfiguration)
			: [],
		autoShare: f.autoShare,
		endpoint: vm.ruleItem.configuration?.fetch_file?.endpoint ?? '',
		contentPath: vm.ruleItem.configuration?.fetch_file?.contentPath ?? '',
		originIdPath: vm.ruleItem.configuration?.fetch_file?.originIdPath ?? '',
		filenamePath: vm.ruleItem.configuration?.fetch_file?.filenamePath ?? '',
		fileExtension: vm.ruleItem.configuration?.fetch_file?.fileExtension ?? '',
	}
}

/**
 * The configuration a rule of `type` is saved with: only that type's section,
 * or nothing for a type without one.
 *
 * @param {string|undefined} type The rule type.
 * @param {object} vm The editor component.
 * @return {object} The configuration.
 */
export function saveRuleConfiguration(type, vm) {
	const configuration = {}
	if (Object.hasOwn(SAVE_SECTIONS, type)) {
		configuration[type] = SAVE_SECTIONS[type](vm)
	}
	return configuration
}
