import { translate as t } from '@nextcloud/l10n'

export function endpointSchema() {
	return {
		title: t('openconnector', 'Endpoint'),
		required: ['name'],
		properties: {
			name: {
				type: 'string',
				title: t('openconnector', 'Name'),
				required: true,
				minLength: 1,
				order: 1,
			},
			description: {
				type: 'string',
				widget: 'textarea',
				title: t('openconnector', 'Description'),
				order: 2,
			},
			slug: {
				type: 'string',
				title: t('openconnector', 'Slug'),
				order: 3,
			},
			endpoint: {
				type: 'string',
				title: t('openconnector', 'Endpoint'),
				order: 4,
			},
			endpointArray: {
				type: 'array',
				title: t('openconnector', 'Endpoint Array'),
				description: t('openconnector', 'Type a path and press Enter to add it'),
				items: { type: 'string' },
				order: 5,
			},
			endpointRegex: {
				type: 'string',
				title: t('openconnector', 'Endpoint Regex'),
				order: 6,
			},
			method: {
				type: 'string',
				title: t('openconnector', 'Method'),
				enum: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
				default: 'GET',
				order: 7,
			},
			version: {
				type: 'string',
				title: t('openconnector', 'Version'),
				default: '0.0.0',
				order: 8,
			},
			targetType: {
				type: 'string',
				title: t('openconnector', 'Target Type'),
				enum: ['register/schema'],
				default: 'register/schema',
				order: 9,
			},
			targetId: {
				type: 'string',
				title: t('openconnector', 'Target ID'),
				order: 10,
			},
			configurations: {
				type: 'array',
				title: t('openconnector', 'Configurations'),
				items: { type: 'string' },
				order: 11,
			},
		},
	}
}
