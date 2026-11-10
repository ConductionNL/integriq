/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blank state the legacy EditRule modal starts from: the rule item with every
 * action type's configuration at its default, and the rule type and authentication
 * type pickers. Every call returns fresh objects, as data() needs.
 */

const RULE_TYPES = [
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
]

const AUTHENTICATION_TYPES = [
	{ label: 'Basic Authentication', value: 'basic' },
	{ label: 'JWT', value: 'jwt' },
	{ label: 'JWT-ZGW', value: 'jwt-zgw' },
	{ label: 'OAuth', value: 'oauth' },
	{ label: 'Api-key', value: 'api-key' },
]

/**
 * The request and availability flags, all idle.
 *
 * @return {object} The flags.
 */
export function blankRequestState() {
	return {
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
	}
}

/**
 * An empty picker that loads its options from a list.
 *
 * @return {{options: Array, value: null, loading: boolean}} The picker state.
 */
export function blankListPicker() {
	return {
		options: [],
		value: null,
		loading: false,
	}
}

/**
 * The pickers the editor fills once it has loaded their options.
 *
 * @return {object} The pickers, each empty.
 */
export function blankActionPickers() {
	return {
		actionOptions: {},
		timingOptions: {},
		sourceOptions: {},
		methodOptions: {},
		filepartUploadMappingOptions: {},
		filepartsCreateMappingOptions: {},
		schemaOptions: {},
	}
}

/**
 * The rule type picker, on Error.
 *
 * @return {{options: Array<object>, value: object}} The picker state.
 */
export function blankRuleTypeOptions() {
	return {
		options: RULE_TYPES.map((type) => ({ ...type })),
		value: { label: 'Error', id: 'error' },
	}
}

/**
 * The authentication type picker, on Basic Authentication.
 *
 * @return {{options: Array<object>, value: object}} The picker state.
 */
export function blankAuthenticationTypeOptions() {
	return {
		options: AUTHENTICATION_TYPES.map((type) => ({ ...type })),
		value: {
			label: 'Basic Authentication',
			value: 'basic',
		},
	}
}

/**
 * The extend-input and mapping defaults.
 *
 * @return {object} Their configuration blocks.
 */
function inputConfiguration() {
	return {
		extend_input: {
			items: [{ property: '', extends: [] }],
		},
		extend_external_input: {
			validate: true,
			properties: [{ property: '', schema: '' }],
		},
		mapping: null,
	}
}

/**
 * The synchronization, error and JavaScript defaults.
 *
 * @return {object} Their configuration blocks.
 */
function outcomeConfiguration() {
	return {
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
	}
}

/**
 * The authentication and download defaults.
 *
 * @return {object} Their configuration blocks.
 */
function accessConfiguration() {
	return {
		authentication: {
			type: { label: 'Basic Authentication', value: 'basic' },
			users: [],
			groups: [],
		},
		download: {
			fileIdPosition: 0,
		},
	}
}

/**
 * The upload and locking defaults.
 *
 * @return {object} Their configuration blocks.
 */
function storageConfiguration() {
	return {
		upload: {
			path: '',
			allowedTypes: '',
			maxSize: 10,
		},
		locking: {
			action: 'lock',
			timeout: 30,
		},
	}
}

/**
 * Where the fetch-file action reads from.
 *
 * @return {object} The source half of the fetch_file block.
 */
function fetchFileSource() {
	return {
		source: '',
		filePath: '',
		subObjectFilepath: '',
		objectIdPath: '',
		method: '',
		tags: [],
		sourceConfiguration: '[]',
		autoShare: false,
	}
}

/**
 * What the fetch-file action reads out of the answer.
 *
 * @return {object} The answer half of the fetch_file block.
 */
function fetchFileAnswer() {
	return {
		endpoint: '',
		contentPath: '',
		originIdPath: '',
		filenamePath: '',
		fileExtension: '',
	}
}

/**
 * The write-file default.
 *
 * @return {object} Its configuration block.
 */
function writeFileConfiguration() {
	return {
		write_file: {
			filePath: '',
			tags: [],
			fileNamePath: '',
			autoShare: false,
		},
	}
}

/**
 * The file-part defaults.
 *
 * @return {object} Their configuration blocks.
 */
function filepartConfiguration() {
	return {
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
	}
}

/**
 * The save-object default.
 *
 * @return {object} Its configuration block.
 */
function saveObjectConfiguration() {
	return {
		save_object: {
			register: '',
			schema: '',
			mapping: '',
		},
	}
}

/**
 * Every action type's configuration at its default, in the editor's order.
 *
 * @return {object} The configuration.
 */
function blankRuleConfiguration() {
	return {
		...inputConfiguration(),
		...outcomeConfiguration(),
		...accessConfiguration(),
		...storageConfiguration(),
		fetch_file: { ...fetchFileSource(), ...fetchFileAnswer() },
		...writeFileConfiguration(),
		...filepartConfiguration(),
		...saveObjectConfiguration(),
	}
}

/**
 * The blank rule item.
 *
 * @return {object} The rule item.
 */
export function blankRuleItem() {
	return {
		name: '',
		description: '',
		conditions: '',
		order: 0,
		action: '',
		type: '',
		actionConfig: '{}',
		timing: '',
		configuration: blankRuleConfiguration(),
	}
}
