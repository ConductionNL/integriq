/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for SyncConfigWidget's option helpers
 * (src/views/Synchronization/syncConfigOptions.js): list unwrapping, option
 * labels, the config copy, the bridge list fetches and the source file picker.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

const builderCalls = []
const picker = { pick: vi.fn() }
const builder = new Proxy({}, {
	get(_target, method) {
		if (method === 'build') {
			return () => {
				builderCalls.push(['build'])
				return picker
			}
		}
		return (...args) => {
			builderCalls.push([method, ...args])
			return builder
		}
	},
})
const getFilePickerBuilder = vi.fn(() => builder)
vi.mock('@nextcloud/dialogs', () => ({
	FilePickerType: { Choose: 1 },
	getFilePickerBuilder,
}))

const options = await import('../../src/views/Synchronization/syncConfigOptions.js')

describe('state factories', () => {
	it('start every lookup and bridge list empty and idle, freshly per call', () => {
		expect(options.lookupState()).toEqual({
			sourceOptions: [],
			sourcesLoading: false,
			registerOptions: [],
			registersLoading: false,
			selectedRegisterRecord: null,
			schemaLabelsById: {},
		})
		expect(options.bridgeState()).toEqual({
			tableOptions: [],
			tablesLoading: false,
			tablesError: '',
			formOptions: [],
			formsLoading: false,
			formsError: '',
		})
		expect(options.lookupState().sourceOptions).not.toBe(options.lookupState().sourceOptions)
	})

	it('names a loader for every source and target kind', () => {
		expect(options.TYPE_LOADERS).toEqual({
			api: 'loadApiLists',
			'register/schema': 'loadRegisterLists',
			'nextcloud-table': 'loadTableLists',
			'nextcloud-form': 'loadFormLists',
		})
	})
})

describe('listOf', () => {
	it('unwraps an OpenRegister envelope or takes a bare list', () => {
		expect(options.listOf({ results: [1, 2] })).toEqual([1, 2])
		expect(options.listOf([3])).toEqual([3])
	})

	it('reads anything else as empty', () => {
		expect(options.listOf({ results: 'x' })).toEqual([])
		expect(options.listOf(null)).toEqual([])
		expect(options.listOf({})).toEqual([])
	})
})

describe('option labels', () => {
	it('labels a source by name, then title, then id, with a string id', () => {
		expect(options.sourceOption({ id: 4, name: 'CRM', title: 'ignored' })).toEqual({ id: '4', label: 'CRM' })
		expect(options.sourceOption({ uuid: 'u-1', title: 'Zaken' })).toEqual({ id: 'u-1', label: 'Zaken' })
		expect(options.sourceOption({ id: 9 })).toEqual({ id: '9', label: 9 })
	})

	it('labels a register by title, name, slug or id and keeps its schemas', () => {
		expect(options.registerOption({ id: 1, title: 'Main', schemas: [5] })).toEqual({ id: '1', label: 'Main', schemas: [5] })
		expect(options.registerOption({ id: 2, slug: 'docs', schemas: 'x' })).toEqual({ id: '2', label: 'docs', schemas: [] })
		expect(options.registerOption({ id: 3 })).toEqual({ id: '3', label: '3', schemas: [] })
	})

	it('maps schema ids to their best label, skipping rows without an id', () => {
		expect(options.schemaLabelMap([
			{ id: 1, title: 'Case', name: 'case' },
			{ uuid: 'u-2', name: 'person' },
			{ id: 3, slug: 'doc' },
			{ id: 4 },
			{ title: 'no id' },
			null,
		])).toEqual({ 1: 'Case', 'u-2': 'person', 3: 'doc', 4: '4' })
	})

	it('labels a schema from its own record first, then the lookup, then its id', () => {
		const labels = { 7: 'From lookup' }
		expect(options.schemaOption({ id: 7, title: 'Inline' }, labels)).toEqual({ id: '7', label: 'Inline' })
		expect(options.schemaOption({ id: 7 }, labels)).toEqual({ id: '7', label: 'From lookup' })
		expect(options.schemaOption({ slug: 'case' }, {})).toEqual({ id: 'case', label: 'case' })
		expect(options.schemaOption(7, labels)).toEqual({ id: '7', label: 'From lookup' })
		expect(options.schemaOption('8', labels)).toEqual({ id: '8', label: '8' })
		expect(options.schemaOption(null, {})).toEqual({ id: 'null', label: 'null' })
	})
})

describe('configCopy', () => {
	it('copies a plain object shallowly', () => {
		const config = { url: 'x', nested: { a: 1 } }
		const copy = options.configCopy(config)
		expect(copy).toEqual(config)
		expect(copy).not.toBe(config)
		expect(copy.nested).toBe(config.nested)
	})

	it('reads arrays, null and scalars as an empty config', () => {
		expect(options.configCopy([1])).toEqual({})
		expect(options.configCopy(null)).toEqual({})
		expect(options.configCopy('x')).toEqual({})
	})
})

describe('bridge lists', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('fetches the tables of a source and maps them to numeric options', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ id: '3', title: 'Leads' }, { id: null }, { id: 4 }] } })

		await expect(options.tableOptionsFor('src-1')).resolves.toEqual([
			{ id: 3, label: 'Leads' },
			{ id: 4, label: '4' },
		])
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/synchronizations/tables-bridge/tables',
			{ params: { sourceId: 'src-1' } },
		)
	})

	it('fetches the forms of a source from a bare list', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 8, title: 'Intake' }] })

		await expect(options.formOptionsFor('src-2')).resolves.toEqual([{ id: 8, label: 'Intake' }])
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/synchronizations/forms-bridge/forms',
			{ params: { sourceId: 'src-2' } },
		)
	})

	it('lets a failed fetch reach the caller', async () => {
		get.mockRejectedValueOnce(new Error('403'))

		await expect(options.tableOptionsFor('src-1')).rejects.toThrow('403')
	})

	it('shows the server error when there is one, else the fallback', () => {
		expect(options.bridgeError({ response: { data: { error: 'Tables app disabled' } } }, 'Could not load')).toBe('Tables app disabled')
		expect(options.bridgeError({ response: { data: {} } }, 'Could not load')).toBe('Could not load')
		expect(options.bridgeError(undefined, 'Could not load')).toBe('Could not load')
	})
})

describe('source file picker', () => {
	it('is a single-file, modal chooser without a MIME filter or directories', () => {
		builderCalls.length = 0

		expect(options.sourceFilePicker()).toBe(picker)
		expect(getFilePickerBuilder).toHaveBeenCalledWith('Pick a sync source file')
		expect(builderCalls).toEqual([
			['setMultiSelect', false],
			['setMimeTypeFilter', []],
			['setModal', true],
			['setType', 1],
			['allowDirectories', false],
			['build'],
		])
	})

	it('reports a picker failure but not a cancellation', () => {
		expect(options.pickerFailure(new Error('Disk full'))).toBe('Disk full')
		expect(options.pickerFailure(new Error('User canceled the picker'))).toBe('')
		expect(options.pickerFailure(new Error('Cancelled'))).toBe('')
		expect(options.pickerFailure(null)).toBe('')
		expect(options.pickerFailure({})).toBe('')
	})
})
