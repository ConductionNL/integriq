import { translate as t } from '@nextcloud/l10n'

export function consumerSchema() {
	return {
		title: t('openconnector', 'Consumer'),
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
				title: t('openconnector', 'Description'),
				order: 2,
			},
			domains: {
				type: 'array',
				title: t('openconnector', 'Domains'),
				items: { type: 'string' },
				order: 3,
			},
			ips: {
				type: 'array',
				title: t('openconnector', 'IPs'),
				items: { type: 'string' },
				order: 4,
			},
			authorizationType: {
				type: 'string',
				title: t('openconnector', 'Authorization type'),
				enum: ['none', 'basic', 'bearer', 'apiKey', 'oauth2', 'jwt'],
				default: 'none',
				order: 5,
			},
			authorizationConfiguration: {
				type: 'object',
				widget: 'json',
				title: t('openconnector', 'Authorization configuration'),
				order: 6,
			},
		},
	}
}
