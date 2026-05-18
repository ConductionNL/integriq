import { translate as t } from '@nextcloud/l10n'

export const RULE_TYPE_KEYS = [
	'error',
	'mapping',
	'synchronization',
	'javascript',
	'authentication',
	'download',
	'upload',
	'locking',
	'fetch_file',
	'write_file',
	'fileparts_create',
	'filepart_upload',
	'save_object',
	'extend_input',
	'extend_external_input',
]

export const RULE_ACTION_KEYS = ['post', 'get', 'put', 'delete']

export const RULE_TIMING_KEYS = ['before', 'after']

export function ruleTypeLabel(type) {
	switch (type) {
	case 'error': return t('openconnector', 'Error')
	case 'mapping': return t('openconnector', 'Mapping')
	case 'synchronization': return t('openconnector', 'Synchronization')
	case 'javascript': return t('openconnector', 'JavaScript')
	case 'authentication': return t('openconnector', 'Authentication')
	case 'download': return t('openconnector', 'Download')
	case 'upload': return t('openconnector', 'Upload')
	case 'locking': return t('openconnector', 'Locking')
	case 'fetch_file': return t('openconnector', 'Fetch File')
	case 'write_file': return t('openconnector', 'Write File')
	case 'fileparts_create': return t('openconnector', 'Fileparts Create')
	case 'filepart_upload': return t('openconnector', 'Filepart Upload')
	case 'save_object': return t('openconnector', 'Save object')
	case 'extend_input': return t('openconnector', 'Extend input')
	case 'extend_external_input': return t('openconnector', 'Extend external input')
	default: return type || '-'
	}
}

export function ruleActionLabel(action) {
	switch (action) {
	case 'post': return t('openconnector', 'Post (Create)')
	case 'get': return t('openconnector', 'Get (Read)')
	case 'put': return t('openconnector', 'Put (Update)')
	case 'delete': return t('openconnector', 'Delete (Delete)')
	default: return action || '-'
	}
}

export function ruleTimingLabel(timing) {
	switch (timing) {
	case 'before': return t('openconnector', 'Before')
	case 'after': return t('openconnector', 'After')
	default: return timing || '-'
	}
}

export function ruleSchema() {
	return {
		title: t('openconnector', 'Rule'),
		required: ['name'],
		properties: {
			name: {
				type: 'string',
				title: t('openconnector', 'Name'),
				required: true,
				minLength: 1,
				maxLength: 255,
				order: 1,
			},
			description: {
				type: 'string',
				widget: 'textarea',
				title: t('openconnector', 'Description'),
				order: 2,
			},
			type: {
				type: 'string',
				title: t('openconnector', 'Type'),
				enum: RULE_TYPE_KEYS,
				enumLabels: Object.fromEntries(RULE_TYPE_KEYS.map(k => [k, ruleTypeLabel(k)])),
				default: 'error',
				order: 3,
			},
			action: {
				type: 'string',
				title: t('openconnector', 'Action'),
				enum: RULE_ACTION_KEYS,
				enumLabels: Object.fromEntries(RULE_ACTION_KEYS.map(k => [k, ruleActionLabel(k)])),
				default: 'post',
				order: 4,
			},
			timing: {
				type: 'string',
				title: t('openconnector', 'Timing'),
				enum: RULE_TIMING_KEYS,
				enumLabels: Object.fromEntries(RULE_TIMING_KEYS.map(k => [k, ruleTimingLabel(k)])),
				default: 'before',
				order: 5,
			},
			order: {
				type: 'number',
				title: t('openconnector', 'Order'),
				default: 0,
				order: 6,
			},
			conditions: {
				type: 'object',
				widget: 'json',
				title: t('openconnector', 'Conditions (JSON Logic)'),
				description: t('openconnector', 'JSON Logic expression evaluated against the request payload.'),
				default: {},
				order: 7,
			},
		},
	}
}
