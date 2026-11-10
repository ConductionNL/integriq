/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the blank state of the legacy EditRule modal: the literals below are copied
 * verbatim from its data() before the defaults moved to editRuleDefaults.js.
 */

import { describe, expect, it } from 'vitest'
import {
	blankActionPickers,
	blankAuthenticationTypeOptions,
	blankListPicker,
	blankRequestState,
	blankRuleItem,
	blankRuleTypeOptions,
} from '../../src/modals/Rule/editRuleDefaults.js'

const INLINE_RULE_ITEM = {
		name: '',
		description: '',
		conditions: '',
		order: 0,
		action: '',
		type: '',
		actionConfig: '{}',
		timing: '',
		configuration: {
			extend_input: {
				items: [{ property: '', extends: [] }],
			},

			extend_external_input: {
				validate: true,
				properties: [{ property: '', schema: '' }],
			},

			mapping: null,
			synchronization: {
				synchronization: null,
				retainResponse: false,
			},

			error: {
				code: 500,
				name: 'Something went wrong',
				message: 'We encountered an unexpected problem',
				includeJsonLogicResult: false,
			},

			javascript: '',
			authentication: {
				type: { label: 'Basic Authentication', value: 'basic' },
				users: [],
				groups: [],
			},

			download: {
				fileIdPosition: 0,
			},

			upload: {
				path: '',
				allowedTypes: '',
				maxSize: 10,
			},

			locking: {
				action: 'lock',
				timeout: 30,
			},

			fetch_file: {
				source: '',
				filePath: '',
				subObjectFilepath: '',
				objectIdPath: '',
				method: '',
				tags: [],
				sourceConfiguration: '[]',
				autoShare: false,
				endpoint: '',
				contentPath: '',
				originIdPath: '',
				filenamePath: '',
				fileExtension: '',
			},

			write_file: {
				filePath: '',
				tags: [],
				fileNamePath: '',
				autoShare: false,
			},

			fileparts_create: {
				sizeLocation: '',
				schemaId: '',
				filenameLocation: '',
				filePartLocation: '',
				mappingId: '',
			},

			filepart_upload: {
				mappingId: '',
			},

			save_object: {
				register: '',
				schema: '',
				mapping: '',
			},
		},
	}

const INLINE_TYPE_OPTIONS = {
		options: [
			{ label: 'Error', id: 'error' },
			{ label: 'Mapping', id: 'mapping' },
			{ label: 'Synchronization', id: 'synchronization' },
			{ label: 'JavaScript', id: 'javascript' },
			{ label: 'Authentication', id: 'authentication' },
			{ label: 'Download', id: 'download' },
			{ label: 'Upload', id: 'upload' },
			{ label: 'Locking', id: 'locking' },
			{ label: 'Fetch File', id: 'fetch_file' },
			{ label: 'Write File', id: 'write_file' },
			{ label: 'Fileparts Create', id: 'fileparts_create' },
			{ label: 'Filepart Upload', id: 'filepart_upload' },
			{ label: 'Save object', id: 'save_object' },
			{ label: 'Extend input', id: 'extend_input' },
			{ label: 'Extend external input', id: 'extend_external_input' },
		],

		value: { label: 'Error', id: 'error' },
	}

const INLINE_AUTHENTICATION_TYPE_OPTIONS = {
		options: [
			{ label: 'Basic Authentication', value: 'basic' },
			{ label: 'JWT', value: 'jwt' },
			{ label: 'JWT-ZGW', value: 'jwt-zgw' },
			{ label: 'OAuth', value: 'oauth' },
			{ label: 'Api-key', value: 'api-key' },
		],

		value: {
			label: 'Basic Authentication',
			value: 'basic',
		},
	}

const INLINE_STATE = {
	success: null,
	loading: false,
	error: false,
	warning: null,
	closeAlert: false,
	sourcesLoading: false,
	usersLoading: false,
	groupsLoading: false,
	openRegister: {
		isInstalled: true,
		isAvailable: true,
	},

	mappingOptions: {
		options: [],
		value: null,
		loading: false,
	},

	syncOptions: {
		options: [],
		value: null,
		loading: false,
	},
}

const INLINE_PICKERS = {
	actionOptions: {},
	timingOptions: {},
	sourceOptions: {},
	methodOptions: {},
	filepartUploadMappingOptions: {},
	filepartsCreateMappingOptions: {},
	schemaOptions: {},
}

describe('editRuleDefaults', () => {
	it('the request flags, list pickers and action pickers are the inline ones, keys in order', () => {
		const state = { ...blankRequestState(), mappingOptions: blankListPicker(), syncOptions: blankListPicker() }

		expect(JSON.stringify(state)).toBe(JSON.stringify(INLINE_STATE))
		expect(state).toStrictEqual(INLINE_STATE)
		expect(blankActionPickers()).toStrictEqual(INLINE_PICKERS)
		expect(blankListPicker().options).not.toBe(blankListPicker().options)
	})

	it('blankRuleItem is the inline blank rule item, keys in the same order', () => {
		expect(blankRuleItem()).toStrictEqual(INLINE_RULE_ITEM)
		expect(JSON.stringify(blankRuleItem())).toBe(JSON.stringify(INLINE_RULE_ITEM))
	})

	it('blankRuleTypeOptions is the inline rule type picker', () => {
		expect(blankRuleTypeOptions()).toStrictEqual(INLINE_TYPE_OPTIONS)
	})

	it('blankAuthenticationTypeOptions is the inline authentication type picker', () => {
		expect(blankAuthenticationTypeOptions()).toStrictEqual(INLINE_AUTHENTICATION_TYPE_OPTIONS)
	})

	it('returns fresh objects per call', () => {
		expect(blankRuleItem().configuration.fetch_file.tags).not.toBe(blankRuleItem().configuration.fetch_file.tags)
		expect(blankRuleTypeOptions().options[0]).not.toBe(blankRuleTypeOptions().options[0])
		expect(blankAuthenticationTypeOptions().options[0]).not.toBe(blankAuthenticationTypeOptions().options[0])
	})
})
