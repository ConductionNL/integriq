/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule editor's configuration: what a stored rule opens as, and what each
 * rule type is saved with.
 */

import { describe, expect, it } from 'vitest'
import {
	editableRuleConfiguration,
	initialiseExtendInputs,
	saveRuleConfiguration,
} from '../../src/modals/Rule/ruleConfiguration.js'

describe('editableRuleConfiguration', () => {
	it('opens an empty configuration with every section at its default', () => {
		const configuration = editableRuleConfiguration({})
		expect(configuration.error).toEqual({
			code: 500,
			name: 'Something went wrong',
			message: 'We encountered an unexpected problem',
			includeJsonLogicResult: false,
		})
		expect(configuration.authentication).toEqual({
			type: { label: 'Basic Authentication', value: 'basic' },
			users: [],
			groups: [],
			keys: [],
		})
		expect(configuration.upload).toEqual({ path: '', allowedTypes: '', maxSize: 10 })
		expect(configuration.locking).toEqual({ action: 'lock', timeout: 30 })
		expect(configuration.fetch_file.sourceConfiguration).toBe('[]')
		expect(Object.keys(configuration)).toEqual([
			'error', 'synchronization', 'authentication', 'download', 'upload',
			'locking', 'fetch_file', 'write_file', 'fileparts_create',
			'filepart_upload', 'save_object',
		])
	})

	it('keeps stored values and unknown keys, and edits the source configuration as JSON text', () => {
		const configuration = editableRuleConfiguration({
			zzz: 1,
			upload: { maxSize: 0 },
			fetch_file: { sourceConfiguration: { a: [1] } },
		})
		expect(configuration.zzz).toBe(1)
		expect(configuration.upload.maxSize).toBe(0)
		expect(configuration.fetch_file.sourceConfiguration).toBe('{\n  "a": [\n    1\n  ]\n}')
		expect(Object.keys(configuration).slice(0, 3)).toEqual(['zzz', 'upload', 'fetch_file'])
	})
})

describe('initialiseExtendInputs', () => {
	it('gives both extend sections one empty row', () => {
		const configuration = {}
		initialiseExtendInputs(configuration)
		expect(configuration.extend_external_input).toEqual({
			validate: true,
			properties: [{ property: '', schema: '' }],
		})
		expect(configuration.extend_input).toEqual({ items: [{ property: '', extends: [] }] })
	})

	it('turns stored extend_input properties into rows ending in an empty one', () => {
		const configuration = {
			extend_input: { properties: ['a', 'b'], extends: { a: ['x'] } },
			extend_external_input: { validate: false, properties: [] },
		}
		initialiseExtendInputs(configuration)
		expect(configuration.extend_input.items).toEqual([
			{ property: 'a', extends: ['x'] },
			{ property: 'b', extends: [] },
			{ property: '', extends: [] },
		])
		expect(configuration.extend_external_input).toEqual({
			validate: false,
			properties: [{ property: '', schema: '' }],
		})
	})
})

describe('saveRuleConfiguration', () => {
	const vm = (configuration) => ({
		ruleItem: { configuration },
		mappingOptions: { value: { value: 'mapping-1' } },
		sourceOptions: { sourceValue: { id: 7 } },
		methodOptions: { value: { label: 'POST' } },
	})

	it('saves only the section of the selected type', () => {
		const configuration = editableRuleConfiguration({ upload: { path: 'in' } })
		expect(saveRuleConfiguration('upload', vm(configuration))).toEqual({
			upload: { path: 'in', allowedTypes: '', maxSize: 10 },
		})
		expect(saveRuleConfiguration('mapping', vm(configuration))).toEqual({ mapping: 'mapping-1' })
	})

	it('saves nothing for a type without a section', () => {
		expect(saveRuleConfiguration('nope', vm({}))).toEqual({})
		expect(saveRuleConfiguration(undefined, vm({}))).toEqual({})
	})

	it('saves the named extend_input rows as properties and extends', () => {
		const configuration = {
			extend_input: { items: [{ property: 'a', extends: ['x'] }, { property: ' ', extends: [] }] },
		}
		expect(saveRuleConfiguration('extend_input', vm(configuration))).toEqual({
			extend_input: { properties: ['a'], extends: { a: ['x'] } },
		})
	})

	it('parses the fetch_file source configuration back to JSON', () => {
		const configuration = editableRuleConfiguration({ fetch_file: { sourceConfiguration: { a: 1 } } })
		const saved = saveRuleConfiguration('fetch_file', vm(configuration)).fetch_file
		expect(saved.sourceConfiguration).toEqual({ a: 1 })
		expect(saved.source).toBe(7)
		expect(saved.method).toBe('POST')
	})
})
