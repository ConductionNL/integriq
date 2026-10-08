/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 *
 * Three scenarios drive real screens and the real endpoints: two channels
 * landing on two case types, an unroutable message held with its reason, and
 * a mapping onto a field the case type does not have refused at save.
 *
 * The inbound leg is signed, so the tests sign as a real sender would: an
 * `openconnector` scheme HMAC over `<timestamp>.<body>`. The scenarios marked
 * `@e2e exclude` in the spec are the adapter contract, per-item isolation and
 * the two external channels, all covered by PHPUnit.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { anonymousRequest } from './support/anonymous.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'
const SECRET = 'e2e-intake-secret'

/**
 * Sign a body the way the `openconnector` scheme does.
 *
 * @param body The exact bytes that will be sent.
 * @return The signature header value.
 */
function sign(body: string): string {
	const timestamp = Math.floor(Date.now() / 1000)
	const signature = createHmac('sha256', SECRET)
		.update(`${timestamp}.${body}`)
		.digest('hex')

	return `t=${timestamp},v1=${signature}`
}

/**
 * Configure one channel's webhook connection.
 *
 * Since the public webhooks moved onto the consumer model, a channel's trust
 * and its account live on a `consumer` whose `authorizationType` is
 * `intake-channel-<channelId>`, not on a source. A channel with no such
 * consumer answers 503 (`no_connection`), and a channel with two answers 503
 * as well, so a rerun reuses the consumer it finds instead of adding one.
 *
 * @param request The Playwright request context.
 * @param channelId The channel id.
 */
async function seedChannelConsumer(
	request: APIRequestContext,
	channelId: string,
): Promise<void> {
	const authorizationType = `intake-channel-${channelId}`
	const data = {
		name: `E2E ${channelId}`,
		description: 'Seeded by tests/e2e/intake-channels.spec.ts',
		authorizationType,
		authorizationConfiguration: { scheme: 'openconnector', secret: SECRET },
		userId: 'admin',
	}

	const found = await request.get(`${OR_BASE}/consumer`, {
		failOnStatusCode: false,
		params: { authorizationType, _limit: 10 },
	})
	expect(found.status(), 'listing the channel consumers must succeed').toBe(200)
	const existing = ((await found.json()).results ?? []) as Array<
		Record<string, any>
	>
	expect(
		existing.length,
		`more than one ${authorizationType} consumer: the channel refuses every delivery until one is removed`,
	).toBeLessThan(2)

	const resp =
		existing.length === 1
			? await request.patch(
					`${OR_BASE}/consumer/${existing[0].id ?? existing[0]['@self']?.id}`,
					{ failOnStatusCode: false, data },
				)
			: await request.post(`${OR_BASE}/consumer`, {
					failOnStatusCode: false,
					data,
				})
	expect(
		resp.status(),
		'configuring a channel consumer must succeed',
	).toBeLessThan(300)
}

/**
 * The routing rules this file seeded, removed after each test.
 *
 * A rule is live for every later delivery on its channel. Left in place, the
 * messaging rule of the routing test routes the message the next test needs
 * to be unroutable.
 */
const seededRules: string[] = []

/**
 * Seed one routing rule.
 *
 * @param request The Playwright request context.
 * @param rule The rule.
 */
async function seedRule(
	request: APIRequestContext,
	rule: Record<string, unknown>,
): Promise<void> {
	const resp = await request.post(`${OR_BASE}/intake_routing_rule`, {
		failOnStatusCode: false,
		data: rule,
	})
	expect(resp.status(), 'seeding a routing rule must succeed').toBeLessThan(300)
	const body = await resp.json()
	seededRules.push(String(body.id ?? body['@self']?.id))
}

/**
 * Deliver one signed payload on a channel.
 *
 * @param request The Playwright request context.
 * @param channelId The channel id.
 * @param payload The channel's own payload shape.
 * @return The parsed response body.
 */
async function deliver(
	request: APIRequestContext,
	channelId: string,
	payload: Record<string, unknown>,
): Promise<Record<string, any>> {
	const body = JSON.stringify(payload)
	const resp = await request.post(
		`${API_BASE}/intake/channels/${channelId}/inbound`,
		{
			failOnStatusCode: false,
			headers: {
				'Content-Type': 'application/json',
				'X-OpenConnector-Signature': sign(body),
			},
			data: body,
		},
	)
	expect(resp.status(), 'a signed delivery must be accepted').toBe(202)

	return await resp.json()
}

test.describe('intake channels', () => {
	test.afterEach(async ({ request }) => {
		for (const id of seededRules.splice(0)) {
			await request.delete(`${OR_BASE}/intake_routing_rule/${id}`, {
				failOnStatusCode: false,
			})
		}
	})

	test('two channels land on two different case types', async ({ request }) => {
		await seedChannelConsumer(request, 'public-space-report')
		await seedChannelConsumer(request, 'messaging')
		await seedRule(request, {
			name: 'E2E meldingen',
			channelId: 'public-space-report',
			targetSchema: 'melding_openbare_ruimte',
			fieldMapping: { omschrijving: 'text' },
			isEnabled: true,
			order: 10,
		})
		await seedRule(request, {
			name: 'E2E berichten',
			channelId: 'messaging',
			targetSchema: 'algemene_vraag',
			fieldMapping: { vraag: 'text' },
			isEnabled: true,
			order: 10,
		})

		const report = await deliver(request, 'public-space-report', {
			id: `MOR-${Date.now()}`,
			description: 'De lantaarnpaal brandt niet.',
			location: { latitude: 52.0907, longitude: 5.1214 },
		})
		const chat = await deliver(request, 'messaging', {
			messageId: `WA-${Date.now()}`,
			from: { phone: '+31612345678' },
			text: 'Wanneer komt de reiniging?',
		})

		// No app in this environment opens those case types, so each message is
		// held rather than routed. What the scenario is about is which rule
		// matched, and that is recorded on the message either way.
		const reportRow = await request.get(`${OR_BASE}/intake_message/${report.id}`)
		const chatRow = await request.get(`${OR_BASE}/intake_message/${chat.id}`)
		const reportBody = await reportRow.json()
		const chatBody = await chatRow.json()

		expect(reportBody.channelId).toBe('public-space-report')
		expect(chatBody.channelId).toBe('messaging')
		expect(
			reportBody.reason,
			'the report reached its rule and waits for the app that owns the case type',
		).toContain('melding_openbare_ruimte')
		expect(chatBody.reason).toContain('algemene_vraag')
	})

	test('an unroutable message is held, not lost', async ({ page, request }) => {
		await seedChannelConsumer(request, 'messaging')

		const externalId = `WA-unrouted-${Date.now()}`
		const result = await deliver(request, 'messaging', {
			messageId: externalId,
			from: { phone: '+31698765432' },
			text: 'Een vraag zonder regel',
		})

		expect(result.status).toBe('held')
		expect(result.reason).toContain('messaging')

		await page.goto(`${APP_BASE}/messages/intake`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByText('Een vraag zonder regel').first(),
			'a held message is on the page somebody actually reads',
		).toBeVisible({ timeout: 20_000 })
	})

	test('a mapping onto a field that does not exist fails at configuration time', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/intake/routing-rules`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: {
				name: 'E2E kapotte mapping',
				channelId: 'public-space-report',
				targetSchema: 'intake_message',
				fieldMapping: { veldDatNietBestaat: 'text' },
				isEnabled: true,
			},
		})

		expect(resp.status(), 'the save must be refused').toBe(400)
		const body = await resp.json()
		expect(body.error, 'and it must name the field').toContain(
			'veldDatNietBestaat',
		)
	})

	test('an anonymous caller cannot change routing', async () => {
		// The least privileged principal that should be refused. A rule decides
		// what opens a case, so an unauthenticated 2xx here would be the whole
		// intake surface open to anyone.
		const anonymous = await anonymousRequest()

		const resp = await anonymous.post(`${API_BASE}/intake/routing-rules`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			data: {
				name: 'nope',
				channelId: 'messaging',
				targetSchema: 'algemene_vraag',
			},
		})

		expect(resp.status()).toBeGreaterThanOrEqual(401)
		await anonymous.dispose()
	})
})
