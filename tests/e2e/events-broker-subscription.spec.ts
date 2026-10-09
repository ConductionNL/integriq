/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/events-cloudevents/spec.md (REQ-EBSC-002, REQ-EBSC-003)
 *
 * The Webhooks form reads the brokers from GET /api/events/brokers and saves
 * a broker subscription through OpenRegister's generic object API. These
 * tests make the same two calls with the payload the form builds
 * (src/modals/EventSubscription/brokerFields.js, pinned by
 * tests/fixtures/events/broker-subscription-payload.json) and read the
 * subscription back through the app's own endpoint, which masks secrets.
 */

import { expect, test } from '@playwright/test'

const BROKERS = '/index.php/apps/integriq/api/events/brokers'
const OBJECTS =
	'/index.php/apps/openregister/api/objects/integriq/event_subscription'
const SUBSCRIPTIONS = '/index.php/apps/integriq/api/events/subscriptions'

async function brokers(request) {
	const resp = await request.get(BROKERS, { failOnStatusCode: false })
	expect(resp.status()).toBe(200)
	return (await resp.json()).results
}

test.describe('broker subscriptions', () => {
	// @e2e events-cloudevents::an-administrator-publishes-case-events-to-rabbitmq
	test('a RabbitMQ broker subscription is stored with its routing and no secret', async ({
		request,
	}) => {
		const ids = (await brokers(request)).map((broker) => broker.id)
		expect(ids).toContain('rabbitmq')

		const created = await request.post(OBJECTS, {
			data: {
				types: ['nl.zaak.created'],
				style: 'push',
				status: 'active',
				action: {
					kind: 'broker',
					brokerId: 'rabbitmq',
					topic: 'zaken',
					routingKey: 'zaak.created',
					contentMode: 'structured',
				},
				protocolSettings: {
					broker: {
						baseUrl: 'https://rabbit.example.org',
						vhost: 'zaken',
						username: 'integriq',
						credentialRef: { credentialId: 'e2e-rabbitmq-zaken' },
					},
				},
			},
			failOnStatusCode: false,
		})
		expect([200, 201]).toContain(created.status())
		const body = await created.json()
		const id = body.id ?? body.uuid ?? body['@self']?.id

		// The list filters on schema properties, and `uuid` is a property of
		// event_subscription that a created row leaves empty. So read the list
		// and find the row by its object id.
		const list = await request.get(SUBSCRIPTIONS, {
			params: { limit: 500 },
			failOnStatusCode: false,
		})
		expect(list.status()).toBe(200)
		const stored = ((await list.json()).results ?? []).find(
			(row: Record<string, unknown>) => row.id === id,
		)
		expect(stored, 'the created subscription is in the list').toBeTruthy()
		expect(stored.action.kind).toBe('broker')
		expect(stored.action.brokerId).toBe('rabbitmq')
		expect(stored.action.topic).toBe('zaken')
		expect(stored.action.routingKey).toBe('zaak.created')
		// protocolSettings is writeOnly, so the list may leave it out entirely.
		expect(stored.protocolSettings?.broker?.password).toBeUndefined()
	})

	// @e2e events-cloudevents::kafka-offers-only-the-modes-it-supports
	test('Kafka describes structured mode only', async ({ request }) => {
		const kafka = (await brokers(request)).find(
			(broker) => broker.id === 'kafka-rest',
		)
		expect(kafka.contentModes).toEqual(['structured'])
	})
})
