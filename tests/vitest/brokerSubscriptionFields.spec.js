// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Broker as a delivery action on the subscription form
 * (events-broker-subscription-screen tasks 2, 3 and 5).
 *
 * The payload the helpers build is compared with
 * tests/fixtures/events/broker-subscription-payload.json, the same object
 * tests/Unit/Service/BrokerSubscriptionPayloadTest.php validates against the
 * merged event_subscription schema.
 *
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BrokerConnectionFields from '@/modals/EventSubscription/BrokerConnectionFields.vue'
import SubscriptionActionFields from '@/modals/EventSubscription/SubscriptionActionFields.vue'
import {
	brokerOptions,
	buildBrokerAction,
	buildBrokerSettings,
	contentModesFor,
	showsRoutingKey,
	storesBrokerSecret,
} from '@/modals/EventSubscription/brokerFields.js'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({ name, props, render: () => h('div', { class: name }) })
	return {
		NcSelect: stub('NcSelect', [
			'inputId',
			'inputLabel',
			'ariaLabelCombobox',
			'modelValue',
			'options',
			'loading',
			'clearable',
			'placeholder',
		]),
		NcTextField: stub('NcTextField', [
			'label',
			'modelValue',
			'type',
			'helperText',
		]),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcNoteCard: stub('NcNoteCard', ['type']),
	}
})

/** What GET /api/events/brokers answers with the four registered transports. */
const DESCRIPTIONS = [
	{
		id: 'cloudevents-http',
		label: 'CloudEvents HTTP',
		needsTopic: false,
		contentModes: ['structured', 'binary'],
	},
	{
		id: 'kafka-rest',
		label: 'Kafka (REST Proxy)',
		needsTopic: true,
		contentModes: ['structured'],
	},
	{
		id: 'log',
		label: 'No broker configured',
		needsTopic: false,
		contentModes: ['structured'],
	},
	{
		id: 'rabbitmq',
		label: 'RabbitMQ',
		needsTopic: true,
		contentModes: ['structured', 'binary'],
	},
]

const FIXTURE = JSON.parse(
	readFileSync(
		resolve(__dirname, '../fixtures/events/broker-subscription-payload.json'),
		'utf8',
	),
).subscription

describe('brokerFields', () => {
	it('lists the brokers with the one that refuses last', () => {
		const ids = brokerOptions(DESCRIPTIONS).map((option) => option.id)
		expect(ids).toEqual(['cloudevents-http', 'kafka-rest', 'rabbitmq', 'log'])
		expect(
			brokerOptions(DESCRIPTIONS).find((option) => option.id === 'log')
				.refuses,
		).toBe(true)
	})

	it('builds the stored RabbitMQ action and connection exactly', () => {
		const options = brokerOptions(DESCRIPTIONS)
		let action = buildBrokerAction(
			{ kind: 'broker' },
			{ brokerId: 'rabbitmq' },
			options,
		)
		action = buildBrokerAction(action, { topic: 'zaken' }, options)
		action = buildBrokerAction(action, { routingKey: 'zaak.created' }, options)
		action = buildBrokerAction(
			action,
			{ orderingKey: 'ZAAK-2026-0042' },
			options,
		)
		expect(action).toEqual(FIXTURE.action)

		let settings = buildBrokerSettings(
			undefined,
			{ baseUrl: 'https://rabbit.gemeente.example.nl' },
			'rabbitmq',
		)
		settings = buildBrokerSettings(settings, { vhost: 'zaken' }, 'rabbitmq')
		settings = buildBrokerSettings(
			settings,
			{ username: 'integriq' },
			'rabbitmq',
		)
		settings = buildBrokerSettings(
			settings,
			{ credentialRef: { credentialId: 'cred-rabbitmq-zaken' } },
			'rabbitmq',
		)
		expect(settings).toEqual(FIXTURE.protocolSettings)
	})

	it('offers Kafka structured mode only and hides the routing key', () => {
		const kafka = brokerOptions(DESCRIPTIONS).find(
			(option) => option.id === 'kafka-rest',
		)
		expect(contentModesFor(kafka)).toEqual(['structured'])
		expect(showsRoutingKey('kafka-rest')).toBe(false)
		expect(showsRoutingKey('rabbitmq')).toBe(true)

		const action = buildBrokerAction(
			{
				kind: 'broker',
				brokerId: 'rabbitmq',
				routingKey: 'zaak.created',
				contentMode: 'binary',
			},
			{ brokerId: 'kafka-rest' },
			brokerOptions(DESCRIPTIONS),
		)
		expect(action.routingKey).toBeUndefined()
		expect(action.contentMode).toBe('structured')
	})

	it('never carries a stored password or token into what it saves', () => {
		const settings = buildBrokerSettings(
			{
				broker: {
					baseUrl: 'https://rabbit.example.nl',
					password: '********',
				},
				headers: { 'X-Tenant': 'x' },
			},
			{ credentialRef: { credentialId: 'cred-1' } },
			'rabbitmq',
		)
		expect(settings.broker).toEqual({
			baseUrl: 'https://rabbit.example.nl',
			credentialRef: { credentialId: 'cred-1' },
		})
		expect(settings.headers).toEqual({ 'X-Tenant': 'x' })
		expect(storesBrokerSecret({ password: '********' })).toBe(true)
		expect(
			storesBrokerSecret({ credentialRef: { credentialId: 'cred-1' } }),
		).toBe(false)
	})
})

describe('the subscription form', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('offers Broker and loads the brokers from the app', async () => {
		get.mockResolvedValue({ data: { results: DESCRIPTIONS } })
		const updateField = vi.fn()
		const wrapper = mount(SubscriptionActionFields, {
			props: {
				formData: { action: { kind: 'broker', brokerId: 'kafka-rest' } },
				updateField,
			},
		})
		await flushPromises()

		expect(wrapper.vm.kindOptions.map((option) => option.id)).toContain('broker')
		expect(
			get.mock.calls
				.map((call) => call[0])
				.some((url) => url.includes('/apps/integriq/api/events/brokers')),
		).toBe(true)
		expect(wrapper.vm.contentModeOptions.map((option) => option.id)).toEqual([
			'structured',
		])
		expect(wrapper.vm.showsBrokerRoutingKey).toBe(false)

		wrapper.vm.onBrokerField({ brokerId: 'rabbitmq' })
		expect(updateField).toHaveBeenLastCalledWith('action', {
			kind: 'broker',
			brokerId: 'rabbitmq',
			contentMode: 'structured',
		})
	})

	it('shows no password field and says when a password is stored', async () => {
		get.mockImplementation((url) =>
			url.includes('/api/events/subscriptions')
				? Promise.resolve({
						data: {
							results: [
								{
									protocolSettings: {
										broker: { password: '********' },
									},
								},
							],
						},
					})
				: Promise.resolve({ data: { results: [] } }),
		)
		const wrapper = mount(BrokerConnectionFields, {
			props: {
				formData: {
					id: 'sub-1',
					action: { kind: 'broker', brokerId: 'rabbitmq' },
				},
				updateField: vi.fn(),
				brokerId: 'rabbitmq',
			},
		})
		await flushPromises()

		const labels = wrapper
			.findAllComponents({ name: 'NcTextField' })
			.map((field) => field.props('label'))
		expect(labels).toEqual(['Broker address', 'Virtual host', 'Username'])
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
		const subscriptionCall = get.mock.calls.find((call) =>
			call[0].includes('/api/events/subscriptions'),
		)
		expect(subscriptionCall[1]).toEqual({ params: { uuid: 'sub-1' } })
	})
})
