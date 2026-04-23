import { translate as t } from '@nextcloud/l10n'

export function sourceSchema() {
	return {
		title: t('openconnector', 'Source'),
		required: ['name', 'location', 'type'],
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
				title: t('openconnector', 'Description'),
				maxLength: 256,
				order: 2,
			},
			type: {
				type: 'string',
				title: t('openconnector', 'Type'),
				enum: ['database', 'api', 'file', 'soap'],
				default: 'api',
				required: true,
				order: 3,
			},
			location: {
				type: 'string',
				title: t('openconnector', 'Location'),
				required: true,
				description: t('openconnector', 'Trailing slash will be stripped on save.'),
				order: 4,
			},
		},
	}
}
