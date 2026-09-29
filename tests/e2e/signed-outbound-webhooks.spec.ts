/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/webhook-signing/spec.md (REQ-SOW-001..003)
 *
 * A push subscription made with no signing configuration comes back signed,
 * with its secret in the create response once and nowhere after; an unsigned
 * one carries the reason somebody gave; and the list says which is which.
 * The signing default runs in SubscriptionSigningDefaultListener on
 * OpenRegister's create path, so these go through the app's subscribe route
 * and are read back through OpenRegister's generic object API, the one the
 * Webhooks page uses. The wire half (the header on a delivery) and the
 * attempt log are PHPUnit's: EventServiceTest.
 */

import { expect, test } from '@playwright/test'

const SUBSCRIBE = '/index.php/apps/integriq/api/events/subscriptions'
const OBJECTS =
	'/index.php/apps/openregister/api/objects/integriq/event_subscription'

async function create(request, body: Record<string, unknown>) {
	const resp = await request.post(SUBSCRIBE, {
		data: {
			style: 'push',
			sink: 'https://example.org/hook',
			types: ['nl.example.e2e.signed'],
			...body,
		},
		failOnStatusCode: false,
	})
	expect(resp.status()).toBe(200)
	return resp.json()
}

async function readBack(request, id: string) {
	const resp = await request.get(`${OBJECTS}/${id}`, { failOnStatusCode: false })
	expect(resp.status()).toBe(200)
	return resp.text()
}

test.describe('signed outbound webhooks', () => {
	// @e2e webhook-signing::a-new-subscription-is-signed-without-anybody-asking
	test('a new push subscription is signed and its secret shown once', async ({
		request,
	}) => {
		const created = await create(request, {})
		expect(String(created.signingSecret)).toMatch(/^whsec_/)

		const stored = await readBack(request, created.uuid ?? created.id)
		expect(JSON.parse(stored).signingPosture).toBe('signed')
		expect(stored).not.toContain(created.signingSecret)
	})

	// @e2e webhook-signing::unsigned-with-a-reason-is-accepted-and-recorded
	test('unsigned with a reason is stored with the reason and no secret', async ({
		request,
	}) => {
		const created = await create(request, {
			protocolSettings: {
				unsigned: { reason: 'receiver cannot verify HMAC yet' },
			},
		})
		expect(created.signingSecret).toBeUndefined()

		const stored = JSON.parse(
			await readBack(request, created.uuid ?? created.id),
		)
		expect(stored.signingPosture).toBe('unsigned')
		expect(stored.unsignedReason).toBe('receiver cannot verify HMAC yet')
	})

	// @e2e webhook-signing::the-list-distinguishes-signed-from-unsigned
	test('the list marks the unsigned subscription and not the signed one', async ({
		request,
	}) => {
		const signed = await create(request, {})
		const unsigned = await create(request, {
			protocolSettings: {
				unsigned: { reason: 'receiver cannot verify HMAC yet' },
			},
		})

		const resp = await request.get(`${OBJECTS}?_limit=200`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const rows = (await resp.json()).results ?? []
		const posture = (id: string) =>
			rows.find(
				(row: { id?: string; uuid?: string }) => (row.uuid ?? row.id) === id,
			)?.signingPosture
		expect(posture(signed.uuid ?? signed.id)).toBe('signed')
		expect(posture(unsigned.uuid ?? unsigned.id)).toBe('unsigned')
	})
})
