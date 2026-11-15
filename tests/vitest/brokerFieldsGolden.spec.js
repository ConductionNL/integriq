/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the broker delivery fields of the subscription form: broker
 * actions and connection settings of every shape, compared with
 * tests/vitest/golden/brokerFields.json.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	buildBrokerAction,
	buildBrokerSettings,
	readBrokerConnection,
	storesBrokerSecret,
} from '@/modals/EventSubscription/brokerFields.js'

const golden = goldenFile(new URL('./golden/brokerFields.json', import.meta.url))
afterAll(() => golden.save())

/**
 * The result of a call, or the kind of error it threw.
 *
 * @param {Function} run The call.
 * @return {object} The outcome.
 */
function outcome(run) {
	try {
		return { result: run() }
	} catch (error) {
		return { threw: error.constructor.name }
	}
}

const OPTIONS = {
	undefined,
	empty: [],
	rabbit: [
		{ id: 'rabbitmq', contentModes: ['structured', 'binary'] },
		{ id: 'kafka', contentModes: [] },
		{ id: 'log' },
	],
}

const CURRENT = {
	undefined,
	null: null,
	string: 'x',
	empty: {},
	full: {
		brokerId: 'rabbitmq',
		topic: ' t ',
		routingKey: 'rk',
		contentMode: 'binary',
		orderingKey: '',
		extra: 1,
	},
	kafka: {
		brokerId: 'kafka',
		topic: 't',
		routingKey: 'rk',
		contentMode: 'binary',
	},
	log: { brokerId: 'log', contentMode: 'structured', topic: null },
}

const PATCHES = {
	none: {},
	broker: { brokerId: 'rabbitmq' },
	mode: { contentMode: 'binary' },
	badMode: { contentMode: 'avro' },
	blank: { topic: '   ', routingKey: undefined },
	other: { brokerId: 'unknown', contentMode: 'binary', orderingKey: ' o ' },
}

const SETTINGS = {
	undefined,
	null: null,
	string: 's',
	empty: {},
	noBroker: { other: 1 },
	brokerString: { broker: 'b', other: 1 },
	full: {
		other: 2,
		broker: {
			baseUrl: ' https://mq.example ',
			vhost: '/v',
			username: 'u',
			credentialRef: { credentialId: 'c-1' },
			password: 'p',
		},
	},
	refWithoutId: {
		broker: { credentialRef: { credentialName: 'n' }, username: '' },
	},
	refNull: { broker: { credentialRef: null, vhost: '' } },
}

const SETTING_PATCHES = {
	none: {},
	ref: { credentialRef: { credentialId: 'c-2' } },
	refEmpty: { credentialRef: { credentialId: '' } },
	user: { username: ' admin ', vhost: 'v2' },
	password: { password: 'secret' },
}

describe('brokerFields golden', () => {
	it('builds the broker action', () => {
		for (const [currentName, current] of Object.entries(CURRENT)) {
			for (const [patchName, patch] of Object.entries(PATCHES)) {
				for (const [optionsName, options] of Object.entries(OPTIONS)) {
					golden.check(
						`action/${currentName}/${patchName}/${optionsName}`,
						outcome(() => buildBrokerAction(current, patch, options)),
					)
				}
			}
		}
	})

	it('builds the broker settings', () => {
		for (const [settingsName, settings] of Object.entries(SETTINGS)) {
			for (const [patchName, patch] of Object.entries(SETTING_PATCHES)) {
				for (const brokerId of ['rabbitmq', 'kafka']) {
					golden.check(
						`settings/${settingsName}/${patchName}/${brokerId}`,
						outcome(() =>
							buildBrokerSettings(settings, patch, brokerId),
						),
					)
				}
			}
		}
	})

	it('reads the connection and its stored secret', () => {
		const forms = {
			undefined,
			empty: {},
			noSettings: { protocolSettings: null },
			string: { protocolSettings: { broker: 'b' } },
			broker: { protocolSettings: { broker: { username: 'u' } } },
		}
		for (const [name, form] of Object.entries(forms)) {
			golden.check(
				`read/${name}`,
				outcome(() => readBrokerConnection(form)),
			)
		}
		const brokers = {
			undefined,
			string: 's',
			empty: {},
			password: { password: 'p' },
			emptyPassword: { password: '', token: null },
			token: { token: 't' },
			zero: { token: 0 },
		}
		for (const [name, broker] of Object.entries(brokers)) {
			golden.check(
				`secret/${name}`,
				outcome(() => storesBrokerSecret(broker)),
			)
		}
	})
})
