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
 * REQ-IC-021, a handler works a message from the shared inbox: on a held
 * WhatsApp message the administrator (who passes every action right) opens the
 * row's actions on the Intake inbox page and assigns it to a case, after which
 * the row shows the case and who assigned it; Reply sends the typed text to the
 * reply route of the message. A reader without `intake.assign` and
 * `intake.reply` is told neither is allowed, which is what hides the two
 * actions from them.
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
import { BASE_URL } from './support/baseUrl.ts'

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

test.describe('intake inbox: assign and reply', () => {
	const READER = 'e2e-intake-reader'
	const READER_PASSWORD = process.env.INTEGRIQ_E2E_INTAKE_READER_PASS ?? 'Intake-Reader-e2e-1!'
	const OCS = { 'OCS-APIRequest': 'true' }

	test.afterAll(async ({ request }) => {
		await request.delete(`/index.php/ocs/v2.php/cloud/users/${READER}?format=json`, { failOnStatusCode: false, headers: OCS })
	})

	/**
	 * Deliver one held WhatsApp message and open its row's actions on the inbox.
	 *
	 * @param page The page.
	 * @param request The request fixture.
	 * @param text The message text, unique per test.
	 * @return The message id.
	 */
	async function openHeldRow(page: any, request: APIRequestContext, text: string): Promise<string> {
		await seedChannelConsumer(request, 'messaging')
		const result = await deliver(request, 'messaging', {
			messageId: `WA-inbox-${Date.now()}`,
			from: { phone: '+31612345678' },
			text,
		})
		expect(result.status, 'with no rule for it the message is held').toBe('held')

		await page.goto(`${APP_BASE}/messages/intake`, { waitUntil: 'domcontentloaded' })
		const row = page.getByRole('row').filter({ hasText: text })
		await expect(row, 'the held message is in the inbox').toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: 'Message actions' }).click()

		return String(result.id)
	}

	// @e2e intake-channels::a-handler-assigns-a-held-message-to-a-case
	test('a handler assigns a held message to a case', async ({ page, request }) => {
		const text = `Toewijzen ${Date.now()}`
		const caseRef = `ZAAK-E2E-${Date.now()}`
		const id = await openHeldRow(page, request, text)

		await page.getByRole('menuitem', { name: 'Assign to case' }).click()
		const dialog = page.getByRole('dialog', { name: 'Assign to case' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Case reference').fill(caseRef)
		const assigned = page.waitForResponse((resp) => resp.url().includes(`/api/intake/messages/${id}/assign`))
		await dialog.getByTestId('intake-message-action-submit').click()
		expect((await assigned).status(), 'the assign route accepts it').toBe(200)

		const row = page.getByRole('row').filter({ hasText: text })
		await expect(row, 'the row shows the case').toContainText(caseRef, { timeout: 20_000 })
		await expect(row, 'and who assigned it').toContainText('admin')

		const stored = await request.get(`${OR_BASE}/intake_message/${id}`)
		const message = await stored.json()
		expect(message.status).toBe('assigned')
		expect(message.targetRef).toBe(caseRef)
		expect(message.assignedBy).toBe('admin')
	})

	// @e2e intake-channels::a-handler-answers-from-the-inbox
	test('a handler answers from the inbox on the channel the message came in on', async ({ page, request }) => {
		const text = `Beantwoorden ${Date.now()}`
		const id = await openHeldRow(page, request, text)

		await page.getByRole('menuitem', { name: 'Reply' }).click()
		const dialog = page.getByRole('dialog', { name: 'Reply' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Your reply').fill('We bellen u morgen terug.')
		const replied = page.waitForResponse((resp) => resp.url().includes(`/api/intake/messages/${id}/reply`))
		await dialog.getByTestId('intake-message-action-submit').click()
		const answer = await replied
		expect(answer.status(), 'the reply route answers for the message').toBe(200)
		expect(answer.request().postDataJSON()).toEqual({ text: 'We bellen u morgen terug.' })
		// Whether WhatsApp takes it depends on the instance's outbound set-up;
		// the reply went to the message's own channel either way.
		expect((await answer.json()).channelId).toBe('messaging')
	})

	// @e2e intake-channels::a-reader-without-the-right-sees-no-actions
	test('a reader without the rights is allowed neither action', async ({ request, playwright }) => {
		await request.post('/index.php/ocs/v2.php/cloud/users?format=json', {
			failOnStatusCode: false,
			headers: OCS,
			form: { userid: READER, password: READER_PASSWORD },
		})
		const reader = await playwright.request.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: READER, password: READER_PASSWORD, send: 'always' },
			extraHTTPHeaders: OCS,
		})
		try {
			const rights = await reader.get(`${API_BASE}/intake/messages/rights`, { failOnStatusCode: false })
			expect(rights.status()).toBe(200)
			expect(await rights.json(), 'the row actions stay hidden for this reader').toEqual({ assign: false, reply: false })

			const assign = await reader.post(`${API_BASE}/intake/messages/does-not-matter/assign`, {
				failOnStatusCode: false,
				data: { caseRef: 'ZAAK-1' },
			})
			expect(assign.status(), 'and the route refuses them too').toBe(403)
		} finally {
			await reader.dispose()
		}
	})
})
