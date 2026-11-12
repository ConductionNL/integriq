/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule configuration save sections the existing ruleConfiguration spec
 * leaves out (error, synchronization, javascript, authentication, download,
 * locking, extend_external_input, write_file, fileparts_create,
 * filepart_upload, save_object), each read from an editor-shaped object, and
 * the remaining initialiseExtendInputs branches.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	editableRuleConfiguration,
	initialiseExtendInputs,
	saveRuleConfiguration,
} from '../../src/modals/Rule/ruleConfiguration.js'

/**
 * An editor-shaped object around a configuration.
 *
 * @param {object} configuration the editable configuration
 * @param {object} extra option selections to override
 * @return {object} the editor stand-in
 */
function editor(configuration, extra = {}) {
	return {
		ruleItem: { configuration },
		mappingOptions: { value: { value: 'map-1' } },
		syncOptions: { value: { value: 'sync-1' } },
		schemaOptions: { value: { id: 'schema-9' } },
		filepartsCreateMappingOptions: { value: { value: 'map-create' } },
		filepartUploadMappingOptions: { value: { value: 'map-upload' } },
		authenticationTypeOptions: { value: { value: 'jwt' } },
		apiKeys: [],
		...extra,
	}
}

describe('saveRuleConfiguration sections', () => {
	it('saves the error section field by field, dropping extra keys', () => {
		const configuration = editableRuleConfiguration({
			error: { code: 403, name: 'Denied', message: 'No', includeJsonLogicResult: true, extra: 1 },
		})
		configuration.error.extra = 1
		expect(saveRuleConfiguration('error', editor(configuration))).toEqual({
			error: { code: 403, name: 'Denied', message: 'No', includeJsonLogicResult: true },
		})
	})

	it('saves the selected synchronization with the retain switch', () => {
		const configuration = editableRuleConfiguration({ synchronization: { retainResponse: true } })
		expect(saveRuleConfiguration('synchronization', editor(configuration))).toEqual({
			synchronization: { synchronization: 'sync-1', retainResponse: true },
		})
		expect(
			saveRuleConfiguration('synchronization', editor(configuration, { syncOptions: {} })),
		).toEqual({ synchronization: { synchronization: undefined, retainResponse: true } })
	})

	it('saves the javascript source as is', () => {
		expect(saveRuleConfiguration('javascript', editor({ javascript: 'return 1' }))).toEqual({
			javascript: 'return 1',
		})
	})

	it('saves no mapping when none is selected', () => {
		expect(saveRuleConfiguration('mapping', editor({}, { mappingOptions: { value: null } }))).toEqual({
			mapping: undefined,
		})
	})

	it('saves authentication as ids, omitting keys when none were entered', () => {
		const configuration = editableRuleConfiguration({
			authentication: { users: [{ id: 'u1' }, { id: 'u2' }], groups: [{ value: 'admin' }] },
		})
		expect(saveRuleConfiguration('authentication', editor(configuration))).toEqual({
			authentication: { type: 'jwt', users: ['u1', 'u2'], groups: ['admin'] },
		})
	})

	it('saves only the complete entered api keys', () => {
		const configuration = editableRuleConfiguration({})
		const apiKeys = [
			{ apiKey: 'k1', user: { id: 'u1' } },
			{ apiKey: 'k2', user: null },
			{ apiKey: '', user: { id: 'u3' } },
		]
		const saved = saveRuleConfiguration(
			'authentication',
			editor(configuration, { apiKeys, authenticationTypeOptions: { value: { value: 'api-key' } } }),
		)
		expect(saved).toEqual({
			authentication: { type: 'api-key', users: [], groups: [], keys: [{ k1: 'u1' }] },
		})
	})

	it('saves the download position', () => {
		const configuration = editableRuleConfiguration({ download: { fileIdPosition: 3 } })
		expect(saveRuleConfiguration('download', editor(configuration))).toEqual({
			download: { fileIdPosition: 3 },
		})
	})

	it('saves a locking action picked from the select or typed as text', () => {
		const configuration = editableRuleConfiguration({ locking: { timeout: 5 } })
		configuration.locking.action = { label: 'Unlock', value: 'unlock' }
		expect(saveRuleConfiguration('locking', editor(configuration))).toEqual({
			locking: { action: 'unlock', timeout: 5 },
		})
		configuration.locking.action = 'unlock'
		expect(saveRuleConfiguration('locking', editor(configuration)).locking.action).toBe('unlock')
	})

	it('saves only external-input rows with both a property and a schema', () => {
		const configuration = {
			extend_external_input: {
				validate: false,
				properties: [
					{ property: 'a', schema: 's', extra: 1 },
					{ property: 'b', schema: ' ' },
					{ property: ' ', schema: 's' },
					{ property: '', schema: '' },
				],
			},
		}
		expect(saveRuleConfiguration('extend_external_input', editor(configuration))).toEqual({
			extend_external_input: { validate: false, properties: [{ property: 'a', schema: 's' }] },
		})
	})

	it('validates external input by default when the section is missing', () => {
		expect(saveRuleConfiguration('extend_external_input', editor({}))).toEqual({
			extend_external_input: { validate: true, properties: [] },
		})
	})

	it('saves an empty extend_input when the section is missing', () => {
		expect(saveRuleConfiguration('extend_input', editor({}))).toEqual({
			extend_input: { properties: [], extends: {} },
		})
	})

	it('saves a property without extends as an empty list', () => {
		const configuration = { extend_input: { items: [{ property: 'p' }] } }
		expect(saveRuleConfiguration('extend_input', editor(configuration)).extend_input.extends).toEqual({
			p: [],
		})
	})

	it('saves the write_file section', () => {
		const configuration = editableRuleConfiguration({
			write_file: { filePath: 'a/b', fileNamePath: 'name', tags: ['t'], autoShare: true },
		})
		expect(saveRuleConfiguration('write_file', editor(configuration))).toEqual({
			write_file: { filePath: 'a/b', fileNamePath: 'name', tags: ['t'], autoShare: true },
		})
	})

	it('saves fileparts_create with the selected schema and mapping, not the stored ids', () => {
		const configuration = editableRuleConfiguration({
			fileparts_create: {
				sizeLocation: 'size',
				schemaId: 'old-schema',
				filenameLocation: 'name',
				filePartLocation: 'part',
				mappingId: 'old-map',
			},
		})
		expect(saveRuleConfiguration('fileparts_create', editor(configuration))).toEqual({
			fileparts_create: {
				sizeLocation: 'size',
				schemaId: 'schema-9',
				filenameLocation: 'name',
				filePartLocation: 'part',
				mappingId: 'map-create',
			},
		})
	})

	it('saves filepart_upload with the selected mapping', () => {
		expect(saveRuleConfiguration('filepart_upload', editor({}))).toEqual({
			filepart_upload: { mappingId: 'map-upload' },
		})
	})

	it('saves save_object with the typed register and schema and the selected mapping', () => {
		const configuration = editableRuleConfiguration({
			save_object: { register: 'r', schema: 's', mapping: 'stored' },
		})
		expect(saveRuleConfiguration('save_object', editor(configuration))).toEqual({
			save_object: { register: 'r', schema: 's', mapping: 'map-1' },
		})
	})

	it('saves an empty fetch_file source configuration as an empty list', () => {
		const configuration = editableRuleConfiguration({})
		configuration.fetch_file.sourceConfiguration = ''
		const saved = saveRuleConfiguration(
			'fetch_file',
			editor(configuration, { sourceOptions: {}, methodOptions: {} }),
		).fetch_file
		expect(saved.sourceConfiguration).toEqual([])
		expect(saved.source).toBeUndefined()
		expect(saved.method).toBeUndefined()
		expect(saved.endpoint).toBe('')
	})
})

describe('initialiseExtendInputs branches', () => {
	it('adds an empty row to stored sections that have none', () => {
		const configuration = {
			extend_external_input: { validate: false, properties: [] },
			extend_input: { items: [] },
		}
		initialiseExtendInputs(configuration)
		expect(configuration).toEqual({
			extend_external_input: { validate: false, properties: [{ property: '', schema: '' }] },
			extend_input: { items: [{ property: '', extends: [] }] },
		})
	})

	it('adds rows to sections stored without a list', () => {
		const configuration = { extend_external_input: { validate: true }, extend_input: {} }
		initialiseExtendInputs(configuration)
		expect(configuration.extend_external_input.properties).toEqual([{ property: '', schema: '' }])
		expect(configuration.extend_input.items).toEqual([{ property: '', extends: [] }])
	})

	it('keeps existing rows', () => {
		const rows = [{ property: 'a', schema: 's' }]
		const items = [{ property: 'b', extends: [] }]
		const configuration = {
			extend_external_input: { validate: true, properties: rows },
			extend_input: { items },
		}
		initialiseExtendInputs(configuration)
		expect(configuration.extend_external_input.properties).toBe(rows)
		expect(configuration.extend_input.items).toBe(items)
	})

	it('turns stored properties without extends into rows with an empty list', () => {
		const configuration = { extend_input: { properties: ['p'] } }
		initialiseExtendInputs(configuration)
		expect(configuration.extend_input).toEqual({
			items: [
				{ property: 'p', extends: [] },
				{ property: '', extends: [] },
			],
		})
	})

	it('gives an empty stored property list one empty row', () => {
		const configuration = { extend_input: { properties: [], extends: {} } }
		initialiseExtendInputs(configuration)
		expect(configuration.extend_input).toEqual({ items: [{ property: '', extends: [] }] })
	})

	it('adds no second empty row when the stored list ends in an empty property', () => {
		const configuration = { extend_input: { properties: ['a', ''], extends: { a: ['x'] } } }
		initialiseExtendInputs(configuration)
		expect(configuration.extend_input.items).toEqual([
			{ property: 'a', extends: ['x'] },
			{ property: '', extends: [] },
		])
	})
})
